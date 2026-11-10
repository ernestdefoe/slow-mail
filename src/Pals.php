<?php

namespace ErnestDefoe\SlowMail;

use Flarum\User\User;

/**
 * A member's pen pals: everyone a letter has arrived between, either way.
 * One aggregate query and one for the people.
 */
class Pals
{
    public function __construct(private readonly Present $present, private readonly Members $members)
    {
    }

    /** @return list<array<string, mixed>> */
    public function of(User $actor, int $limit = 200): array
    {
        $me = (int) $actor->id;

        $rows = Letter::query()->toBase()
            ->whereNotNull('delivered_at')
            ->where(fn ($q) => $q->where('sender_id', $me)->orWhere('recipient_id', $me))
            ->selectRaw('case when sender_id = ? then recipient_id else sender_id end as pal', [$me])
            ->selectRaw('sum(case when sender_id = ? then 1 else 0 end) as sent', [$me])
            ->selectRaw('sum(case when sender_id = ? then 0 else 1 end) as received', [$me])
            ->selectRaw('sum(case when sender_id <> ? and read_at is null and recipient_hidden = ? then 1 else 0 end) as unread', [$me, false])
            ->selectRaw('max(delivered_at) as last_at')
            ->groupByRaw('case when sender_id = ? then recipient_id else sender_id end', [$me])
            ->orderByDesc('last_at')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $users = User::query()->whereVisibleTo($actor)->whereIn('id', $rows->pluck('pal')->map(fn ($id) => (int) $id)->all())->get()->keyBy('id');
        $blocked = $this->members->blockedBy($me);

        $pals = [];
        foreach ($rows as $row) {
            $user = $users->get((int) $row->pal);
            if (! $user) {
                continue;
            }
            $pals[] = [
                'user' => $this->present->user($user),
                'sent' => (int) $row->sent,
                'received' => (int) $row->received,
                'unread' => (int) $row->unread,
                'lastAt' => \Carbon\Carbon::parse($row->last_at, 'UTC')->toIso8601String(),
                'blocked' => isset($blocked[$user->id]),
            ];
        }

        return $pals;
    }
}
