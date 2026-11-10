<?php

namespace ErnestDefoe\SlowMail;

use Flarum\User\User;

/**
 * "Looking for a pen pal": members who opted in, best match first.
 *
 * Only for members who opted in themselves (no browsing the list without
 * joining it). Candidates are filtered in SQL (Members::reachable), then by
 * blocks, letter preferences and both sides' region wishes; places come from
 * Member Map only as far as the viewer may see them (Places::seenBy), and a
 * member without a visible place only matches "anywhere". Scored by shared
 * interests, then by who opted in or updated most recently.
 */
class Matcher
{
    private const POOL = 300;
    private const SHOW = 12;

    public function __construct(
        private readonly Members $members,
        private readonly Places $places,
        private readonly Present $present,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function for(User $actor, Seeker $mine): array
    {
        $me = (int) $actor->id;

        $seekers = Seeker::query()
            ->whereIn('user_id', $this->members->reachable($actor)->select('users.id'))
            ->where('user_id', '<>', $me)
            ->orderByDesc('updated_at')->limit(self::POOL)
            ->with('user')->get();
        if ($seekers->isEmpty()) {
            return [];
        }

        $blocked = $this->members->blockedWith($me);
        $pals = Letter::query()->toBase()->whereNotNull('delivered_at')
            ->where(fn ($q) => $q->where('sender_id', $me)->orWhere('recipient_id', $me))
            ->selectRaw('case when sender_id = ? then recipient_id else sender_id end as pal', [$me])
            ->pluck('pal')->map(fn ($id) => (int) $id)->flip()->all();

        $places = $this->places->seenBy($actor, array_merge([$me], $seekers->pluck('user_id')->all()));
        $here = $places[$me] ?? null;
        $myInterests = $mine->interestList();

        $matches = [];
        foreach ($seekers as $seeker) {
            $user = $seeker->user;
            $id = (int) $seeker->user_id;
            if (! $user || isset($blocked[$id]) || isset($pals[$id])) {
                continue;
            }
            // Pen pals only (or nobody): a stranger's letter wouldn't be taken, so don't suggest one.
            if ((string) $user->getPreference(Settings::ACCEPT, Settings::ACCEPT_EVERYONE) !== Settings::ACCEPT_EVERYONE) {
                continue;
            }

            $there = $places[$id] ?? null;
            if (! self::fits($mine->region, $here, $there) || ! self::fits($seeker->region, $there, $here)) {
                continue;
            }

            $theirs = $seeker->interestList();
            $shared = array_values(array_intersect($theirs, $myInterests));
            $matches[] = [
                'user' => $this->present->user($user),
                'interests' => $theirs,
                'shared' => $shared,
                'region' => $seeker->region,
                'place' => $there['postmark'] ?? null,
                'country' => $there['country'] ?? null,
                '_score' => count($shared),
                '_at' => $seeker->updated_at->getTimestamp(),
            ];
        }

        usort($matches, fn ($a, $b) => [$b['_score'], $b['_at']] <=> [$a['_score'], $a['_at']]);

        return array_map(function ($match) {
            unset($match['_score'], $match['_at']);

            return $match;
        }, array_slice($matches, 0, self::SHOW));
    }

    /**
     * Whether $there suits a wish made from $here.
     *
     * @param array{country: string|null}|null $here
     * @param array{country: string|null}|null $there
     */
    public static function fits(string $wish, ?array $here, ?array $there): bool
    {
        if ($wish === 'anywhere' || ! in_array($wish, Seeker::REGIONS, true)) {
            return true;
        }
        if (! $here || ! $there || ! $here['country'] || ! $there['country']) {
            return false;
        }

        return match ($wish) {
            'country' => $here['country'] === $there['country'],
            'continent' => Continents::of($here['country']) !== null && Continents::of($here['country']) === Continents::of($there['country']),
            'far' => Continents::of($here['country']) !== Continents::of($there['country']),
            default => true,
        };
    }
}
