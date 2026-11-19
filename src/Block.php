<?php

namespace ErnestDefoe\SlowMail;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\Builder;

/**
 * A member who stopped letters from someone. Counted both ways (Rules).
 *
 * @property int $user_id
 * @property int $blocked_id
 * @property Carbon $created_at
 */
class Block extends AbstractModel
{
    protected $table = 'slow_mail_blocks';

    /** The table's key is the pair (user_id, blocked_id): rows are written and removed by put()/lift() below, never saved one at a time. */
    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    protected $casts = [
        'user_id' => 'integer',
        'blocked_id' => 'integer',
        'created_at' => 'datetime',
    ];

    /** @param Builder<Block> $query */
    public function scopeBetween(Builder $query, int $a, int $b): void
    {
        $query->where(fn ($q) => $q->where(fn ($q) => $q->where('user_id', $a)->where('blocked_id', $b))
            ->orWhere(fn ($q) => $q->where('user_id', $b)->where('blocked_id', $a)));
    }

    public static function put(int $userId, int $blockedId): void
    {
        static::query()->insertOrIgnore(['user_id' => $userId, 'blocked_id' => $blockedId, 'created_at' => Carbon::now()]);
    }

    public static function lift(int $userId, int $blockedId): void
    {
        static::query()->where('user_id', $userId)->where('blocked_id', $blockedId)->delete();
    }
}
