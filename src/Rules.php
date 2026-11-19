<?php

namespace ErnestDefoe\SlowMail;

use Carbon\Carbon;
use ErnestDefoe\SlowMail\Integration\Integrations;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * Who may write to whom, and how much.
 *
 * Every reason a member can't be written to answers with the same code
 * (`slow_mail_not_accepting`), so a refusal never says WHICH it was: a
 * block, letters turned off, private messages turned off, or a recipient
 * without Slow Mail. Blocks count both ways. Other extensions have their
 * say through Integrations (Parley's blocks and "who can message me",
 * fof/byobu's "block private discussions", flarum/messages' `message`
 * ability), each one present only while its extension is enabled.
 */
class Rules
{
    public function __construct(
        private readonly Integrations $integrations,
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    /** null when $sender may write to $recipient now, else an error code. */
    public function refusal(User $sender, ?User $recipient): ?string
    {
        if (! $recipient || $recipient->id === $sender->id) {
            return 'slow_mail_not_accepting';
        }

        if (! $this->accepts($sender, $recipient)) {
            return 'slow_mail_not_accepting';
        }

        return null;
    }

    /** Whether $recipient takes letters from $sender (everything but the sender's own limits). */
    public function accepts(User $sender, User $recipient): bool
    {
        if (! $recipient->is_email_confirmed || ! $recipient->hasPermission(Settings::SEND)) {
            return false;
        }

        if (! User::query()->whereVisibleTo($sender)->whereKey($recipient->id)->exists()) {
            return false;
        }

        $accept = (string) $recipient->getPreference(Settings::ACCEPT);
        if ($accept === Settings::ACCEPT_NOBODY || ($accept === Settings::ACCEPT_PALS && ! $this->wroteTo($recipient->id, $sender->id))) {
            return false;
        }

        return ! $this->blocked($sender->id, $recipient->id) && $this->integrations->allow($sender, $recipient);
    }

    /** A block either way: ours, or another extension's (Integrations). */
    public function blocked(int $a, int $b): bool
    {
        return Block::query()->between($a, $b)->exists() || $this->integrations->blockedEitherWay($a, $b);
    }

    /**
     * Which of these sender/recipient pairs are blocked either way, as "sender:recipient" keys: one query for
     * ours, and each extension's list once per sender (Integrations).
     *
     * @param list<array{int, int}> $pairs
     * @return array<string, true>
     */
    public function blockedPairs(array $pairs): array
    {
        if (! $pairs) {
            return [];
        }

        $ids = array_values(array_unique(array_merge(...$pairs)));
        $ours = [];
        foreach (Block::query()->whereIn('user_id', $ids)->whereIn('blocked_id', $ids)->get(['user_id', 'blocked_id']) as $row) {
            $ours[$row->user_id.':'.$row->blocked_id] = true;
        }

        $theirs = [];
        $blocked = [];
        foreach ($pairs as [$a, $b]) {
            $theirs[$a] ??= $this->integrations->blockedWith($a);
            if (isset($ours["$a:$b"]) || isset($ours["$b:$a"]) || isset($theirs[$a][$b])) {
                $blocked["$a:$b"] = true;
            }
        }

        return $blocked;
    }

    /** Whether a member may still send: not suspended (Integrations) and still holding the permission. */
    public function inGoodStanding(User $user): bool
    {
        return $this->integrations->inGoodStanding($user) && $user->hasPermission(Settings::SEND);
    }

    /** $from has written to $to, and the letter has arrived (one still on its way must not be given away). */
    public function wroteTo(int $from, int $to): bool
    {
        return Letter::query()->where('sender_id', $from)->where('recipient_id', $to)->whereNotNull('delivered_at')->exists();
    }

    /** The sender's own limits: null, or an error code with its HTTP status. */
    public function limit(User $sender, User $recipient): ?array
    {
        $now = Carbon::now();
        $sent = Letter::query()->where('sender_id', $sender->id);

        if ($sent->clone()->whereNull('delivered_at')->whereNull('returned_at')->count() >= Settings::IN_TRANSIT) {
            return [422, 'slow_mail_too_many_in_transit'];
        }

        if ($sent->clone()->where('recipient_id', $recipient->id)->whereNull('delivered_at')->whereNull('returned_at')->count() >= Settings::IN_TRANSIT_TO_ONE) {
            return [422, 'slow_mail_wait_for_delivery'];
        }

        $today = $sent->clone()->where('sent_at', '>', $now->copy()->subDay());
        if ($today->clone()->count() >= $this->daily()) {
            return [429, 'slow_mail_daily_limit'];
        }

        // Letters to someone new (never written to, never had a letter from) are the spam vector: fewer of those.
        if (! $this->pals($sender->id, $recipient->id) && ! $sent->clone()->where('recipient_id', $recipient->id)->exists()) {
            // Today's first-ever letters to someone: an old pen pal who never wrote back doesn't count again,
            // and neither does a first letter to someone who had written to you.
            $cold = $today->clone()
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('slow_mail_letters as earlier')
                    ->whereColumn('earlier.sender_id', 'slow_mail_letters.sender_id')
                    ->whereColumn('earlier.recipient_id', 'slow_mail_letters.recipient_id')
                    ->whereColumn('earlier.id', '<', 'slow_mail_letters.id'))
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('slow_mail_letters as theirs')
                    ->whereColumn('theirs.sender_id', 'slow_mail_letters.recipient_id')
                    ->whereColumn('theirs.recipient_id', 'slow_mail_letters.sender_id')
                    ->whereNotNull('theirs.delivered_at')
                    ->whereColumn('theirs.delivered_at', '<', 'slow_mail_letters.sent_at'))
                ->count();
            if ($cold >= Settings::clamp($this->settings->get(Settings::NEW_PALS), 1, 100)) {
                return [429, 'slow_mail_new_pals_limit'];
            }
        }

        return null;
    }

    public function daily(): int
    {
        return Settings::clamp($this->settings->get(Settings::DAILY), 1, 500);
    }

    /** They have a delivered letter between them, either way. */
    public function pals(int $a, int $b): bool
    {
        return $this->wroteTo($a, $b) || $this->wroteTo($b, $a);
    }
}
