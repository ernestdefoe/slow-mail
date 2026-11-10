<?php

namespace ErnestDefoe\SlowMail;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A letter. No JSON:API resource on purpose: letters are served by our plain
 * endpoints only, so no `include` or search can ever pull one in.
 *
 * @property int $id
 * @property int $sender_id
 * @property int $recipient_id
 * @property int|null $reply_to_id
 * @property string $body
 * @property int $words
 * @property string $stamp
 * @property string|null $postmark
 * @property string|null $origin_country
 * @property string|null $destination
 * @property int $travel_hours
 * @property Carbon $sent_at
 * @property Carbon $arrives_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $returned_at
 * @property Carbon|null $notified_at
 * @property Carbon|null $read_at
 * @property bool $sender_hidden
 * @property bool $recipient_hidden
 * @property User|null $sender
 * @property User|null $recipient
 */
class Letter extends AbstractModel
{
    protected $table = 'slow_mail_letters';

    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'sender_id' => 'integer',
        'recipient_id' => 'integer',
        'reply_to_id' => 'integer',
        'words' => 'integer',
        'travel_hours' => 'integer',
        'sent_at' => 'datetime',
        'arrives_at' => 'datetime',
        'delivered_at' => 'datetime',
        'returned_at' => 'datetime',
        'notified_at' => 'datetime',
        'read_at' => 'datetime',
        'sender_hidden' => 'boolean',
        'recipient_hidden' => 'boolean',
    ];

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function isDelivered(): bool
    {
        return $this->delivered_at !== null;
    }

    public function inTransit(): bool
    {
        return $this->delivered_at === null && $this->returned_at === null;
    }

    /** Associated data for the encryption: a row moved to other people no longer decrypts. */
    public function context(): string
    {
        return self::contextFor($this->sender_id, $this->recipient_id);
    }

    public static function contextFor(int $sender, int $recipient): string
    {
        return "letter:$sender:$recipient";
    }
}
