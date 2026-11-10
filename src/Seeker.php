<?php

namespace ErnestDefoe\SlowMail;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member looking for a pen pal.
 *
 * @property int $user_id
 * @property string $interests
 * @property string $region
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property User|null $user
 */
class Seeker extends AbstractModel
{
    public const REGIONS = ['anywhere', 'country', 'continent', 'far'];
    public const MAX_INTERESTS = 8;

    protected $table = 'slow_mail_seekers';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public $timestamps = true;

    protected $casts = [
        'user_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return list<string> */
    public function interestList(): array
    {
        return self::parseInterests($this->interests);
    }

    /** "Hiking, tea ,  Jazz" → ["hiking", "tea", "jazz"]: lowercase, unique, at most 8 of 30 characters. */
    public static function parseInterests(mixed $value): array
    {
        $list = is_array($value) ? $value : explode(',', (string) $value);
        $clean = [];
        foreach ($list as $item) {
            $item = mb_strtolower(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $item)) ?? ''));
            $item = mb_substr($item, 0, 30);
            if ($item !== '' && ! in_array($item, $clean, true)) {
                $clean[] = $item;
            }
        }

        return array_slice($clean, 0, self::MAX_INTERESTS);
    }
}
