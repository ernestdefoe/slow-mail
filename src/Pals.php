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
        // 🚨 The id is written into the SQL (an int, cast above), not bound: PostgreSQL treats the same CASE
        // with two different placeholders in SELECT and GROUP BY as two expressions and refuses the query.
        $pal = "case when sender_id = $me then recipient_id else sender_id end";

        $rows = Letter::query()->toBase()
            ->whereNotNull('delivered_at')
            ->where(fn ($q) => $q->where('sender_id', $me)->orWhere('recipient_id', $me))
            ->selectRaw("$pal as pal")
            ->selectRaw("sum(case when sender_id = $me then 1 else 0 end) as sent")
            ->selectRaw("sum(case when sender_id = $me then 0 else 1 end) as received")
            ->selectRaw("sum(case when sender_id <> $me and read_at is null and recipient_hidden = ? then 1 else 0 end) as unread", [false])
            ->selectRaw('max(delivered_at) as last_at')
            ->groupByRaw($pal)
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
