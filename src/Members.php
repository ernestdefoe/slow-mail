<?php

namespace ErnestDefoe\SlowMail;

use ErnestDefoe\SlowMail\Integration\Integrations;
use Flarum\Group\Group;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lists of members, filtered in SQL so a page of 12 costs the same as 1:
 * who can take letters at all (permission, confirmed, and whatever other
 * extensions rule out: suspended, private discussions turned off), and who
 * is blocked either way.
 */
class Members
{
    public function __construct(private readonly Integrations $integrations)
    {
    }

    /** @return Builder<User> */
    public function reachable(User $viewer): Builder
    {
        $query = $this->withPermission(Settings::SEND)->whereVisibleTo($viewer);
        $this->integrations->scopeReachable($query);

        return $query;
    }

    /**
     * Confirmed members holding a permission (through a group, or as admins). One query.
     *
     * @return Builder<User>
     */
    public function withPermission(string $permission): Builder
    {
        // The permission checked in SQL (a group granted it, Members meaning everyone, or an admin), so a list
        // costs one query whatever its length instead of a permission lookup per member.
        $query = User::query()->where('users.is_email_confirmed', true)
            ->where(fn ($q) => $q
                ->whereExists(fn ($sub) => $sub->selectRaw('1')->from('group_permission')->where('permission', $permission)->where('group_id', Group::MEMBER_ID))
                ->orWhereIn('users.id', fn ($sub) => $sub->select('user_id')->from('group_user')->where(fn ($in) => $in
                    ->where('group_id', Group::ADMINISTRATOR_ID)
                    ->orWhereIn('group_id', fn ($g) => $g->select('group_id')->from('group_permission')->where('permission', $permission)))));

        return $query;
    }

    /**
     * Everyone blocked either way with $userId: ours and other extensions' (Integrations).
     *
     * @return array<int, true>
     */
    public function blockedWith(int $userId): array
    {
        $ids = $this->integrations->blockedWith($userId);
        foreach (Block::query()->where('user_id', $userId)->orWhere('blocked_id', $userId)->get(['user_id', 'blocked_id']) as $row) {
            $ids[$row->user_id === $userId ? $row->blocked_id : $row->user_id] = true;
        }

        return $ids;
    }

    /** @return array<int, true> who $userId has blocked in Slow Mail */
    public function blockedBy(int $userId): array
    {
        return array_fill_keys(Block::query()->where('user_id', $userId)->pluck('blocked_id')->map(fn ($id) => (int) $id)->all(), true);
    }
}
