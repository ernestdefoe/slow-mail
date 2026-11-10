<?php

namespace ErnestDefoe\SlowMail;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A letter its recipient reported. The only way a letter's words ever reach
 * anyone but its two members: the recipient chose to share this one.
 *
 * @property int $id
 * @property int|null $letter_id
 * @property int $reporter_id
 * @property int $sender_id
 * @property string $body
 * @property string $reason
 * @property Carbon $letter_sent_at
 * @property Carbon $created_at
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by
 * @property User|null $reporter
 * @property User|null $sender
 */
class Report extends AbstractModel
{
    public const PER_DAY = 20;

    protected $table = 'slow_mail_reports';

    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'letter_id' => 'integer',
        'reporter_id' => 'integer',
        'sender_id' => 'integer',
        'resolved_by' => 'integer',
        'letter_sent_at' => 'datetime',
        'created_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function context(): string
    {
        return "report:{$this->reporter_id}:{$this->sender_id}";
    }
}
