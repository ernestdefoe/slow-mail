<?php

namespace ErnestDefoe\SlowMail;

use Carbon\Carbon;
use Flarum\Extension\ExtensionManager;
use Flarum\Group\Group;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lists of members, filtered in SQL so a page of 12 costs the same as 1:
 * who can take letters at all (permission, confirmed, not suspended, private
 * discussions not turned off), and who is blocked either way.
 */
class Members
{
    public function __construct(private readonly ExtensionManager $extensions, private readonly ConnectionInterface $db)
    {
    }

    /** @return Builder<User> */
    public function reachable(User $viewer): Builder
    {
        $query = $this->withPermission(Settings::SEND)->whereVisibleTo($viewer);

        if ($this->extensions->isEnabled('fof-byobu')) {
            $query->where(fn ($q) => $q->whereNull('users.blocks_byobu_pd')->orWhere('users.blocks_byobu_pd', false));
        }

        return $query;
    }

    /**
     * Confirmed members holding a permission (through a group, or as admins), not suspended. One query.
     *
     * @return Builder<User>
     */
    public function withPermission(string $permission): Builder
    {
        $query = User::query()->where('users.is_email_confirmed', true)
            ->where(fn ($q) => $q
                ->whereExists(fn ($sub) => $sub->selectRaw('1')->from('group_permission')->where('permission', $permission)->where('group_id', Group::MEMBER_ID))
                ->orWhereIn('users.id', fn ($sub) => $sub->select('user_id')->from('group_user')->where(fn ($in) => $in
                    ->where('group_id', Group::ADMINISTRATOR_ID)
                    ->orWhereIn('group_id', fn ($g) => $g->select('group_id')->from('group_permission')->where('permission', $permission)))));

        if ($this->extensions->isEnabled('flarum-suspend')) {
            $query->where(fn ($q) => $q->whereNull('users.suspended_until')->orWhere('users.suspended_until', '<', Carbon::now()));
        }

        return $query;
    }

    /**
     * Everyone blocked either way with $userId: ours and Parley's. Two small queries at most.
     *
     * @return array<int, true>
     */
    public function blockedWith(int $userId): array
    {
        $ids = [];
        $tables = ['slow_mail_blocks'];
        if ($this->extensions->isEnabled('ernestdefoe-parley') && $this->db->getSchemaBuilder()->hasTable('parley_blocks')) {
            $tables[] = 'parley_blocks';
        }

        foreach ($tables as $table) {
            foreach ($this->db->table($table)->where('user_id', $userId)->orWhere('blocked_id', $userId)->get(['user_id', 'blocked_id']) as $row) {
                $ids[(int) $row->user_id === $userId ? (int) $row->blocked_id : (int) $row->user_id] = true;
            }
        }

        return $ids;
    }

    /** @return array<int, true> who $userId has blocked in Slow Mail */
    public function blockedBy(int $userId): array
    {
        return array_fill_keys($this->db->table('slow_mail_blocks')->where('user_id', $userId)->pluck('blocked_id')->map(fn ($id) => (int) $id)->all(), true);
    }
}
