<?php

namespace ErnestDefoe\SlowMail;

use Carbon\Carbon;
use ErnestDefoe\SlowMail\Notification\LetterArrivedBlueprint;
use ErnestDefoe\SlowMail\Notification\LetterDeliveredBlueprint;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Delivers letters whose time has come. Safe to run every minute, twice at
 * once, or from a page load while the scheduler is also running:
 *
 *  - delivering is a conditional UPDATE (`delivered_at IS NULL`): exactly
 *    one caller wins each letter, and only the winner notifies;
 *  - a block since sending returns the letter instead (same claim);
 *  - notifications are one blueprint per letter (a second sync is a no-op)
 *    and `notified_at` marks them sent; a failed notify is retried later.
 *
 * Forums without a scheduler: DeliverOnVisit runs this from any request
 * once the next arrival (kept in the cache) has passed. Between arrivals
 * that check costs a cache read, no query.
 */
class Mailroom
{
    public const NEXT = 'slow-mail.next-arrival';
    private const LOCK = 'slow-mail.delivering';
    private const NONE = 'none';
    private const BATCH = 200;
    /** The cached next arrival is re-read from the database at least this often, so a lost update can't stall visit delivery. */
    private const TTL = 3600;

    public function __construct(
        private readonly NotificationSyncer $notifications,
        private readonly Rules $rules,
        private readonly Cache $cache,
        private readonly LoggerInterface $log,
    ) {
    }

    /** The scheduler's run. Returns how many letters arrived. */
    public function run(int $limit = self::BATCH): int
    {
        $delivered = 0;

        Letter::query()->whereNull('delivered_at')->whereNull('returned_at')->where('arrives_at', '<=', Carbon::now())
            ->with(['sender', 'recipient'])->orderBy('arrives_at')->limit($limit)->get()
            ->each(function (Letter $letter) use (&$delivered) {
                try {
                    $delivered += (int) $this->deliver($letter);
                } catch (Throwable $e) {
                    $this->log->error('[slow-mail] letter '.$letter->id.': '.$e->getMessage());
                }
            });

        $this->followUp();
        $this->remember();

        return $delivered;
    }

    /** From a request: deliver only when an arrival is due, and only one request at a time. */
    public function runIfDue(): void
    {
        $next = $this->cache->get(self::NEXT);
        if ($next === null) {
            $next = $this->remember();
        }
        if ($next === self::NONE || (int) $next > Carbon::now()->getTimestamp()) {
            return;
        }

        $lock = $this->cache->lock(self::LOCK, 60);
        if ($lock->get()) {
            try {
                $this->run(25);
            } finally {
                $lock->release();
            }
        }
    }

    /** A new letter may arrive before the one we were waiting for. */
    public function expect(Carbon $arrives): void
    {
        $next = $this->cache->get(self::NEXT);
        if ($next === null || $next === self::NONE || (int) $next > $arrives->getTimestamp()) {
            $this->cache->put(self::NEXT, (string) $arrives->getTimestamp(), self::TTL);
        }
    }

    /** True only for the caller that delivered (or returned) it. */
    public function deliver(Letter $letter): bool
    {
        if (! $letter->inTransit() || $letter->arrives_at->isFuture()) {
            return false;
        }

        $now = Carbon::now();
        $sender = $letter->sender;
        $recipient = $letter->recipient;
        // It comes back instead when, since it was sent, they blocked each other, or the sender was
        // suspended or lost Slow Mail (moderation must stop letters already on their way).
        // Letters turned off later still arrive: they were welcome when sent.
        $returned = ! $sender || ! $recipient || ! $this->senderInGoodStanding($sender)
            || $this->rules->blocked($letter->sender_id, $letter->recipient_id);
        $column = $returned ? 'returned_at' : 'delivered_at';

        $won = Letter::query()->whereKey($letter->id)->whereNull('delivered_at')->whereNull('returned_at')
            ->update($returned ? ['returned_at' => $now, 'notified_at' => $now] : ['delivered_at' => $now]) === 1;
        if (! $won) {
            return false;
        }

        $letter->$column = $now;
        if (! $returned) {
            $this->notify($letter);
        }

        return ! $returned;
    }

    private function senderInGoodStanding(User $sender): bool
    {
        $until = $sender->getAttribute('suspended_until');
        if ($until && Carbon::parse($until)->isFuture()) {
            return false;
        }

        return $sender->hasPermission(Settings::SEND);
    }

    /** Notifications still owed (a failure while delivering): retried by the next run. */
    private function followUp(): void
    {
        Letter::query()->whereNotNull('delivered_at')->whereNull('notified_at')
            ->where('delivered_at', '<', Carbon::now()->subMinute())
            ->with(['sender', 'recipient'])->limit(self::BATCH)->get()
            ->each(fn (Letter $letter) => $this->notify($letter));
    }

    private function notify(Letter $letter): void
    {
        try {
            if ($letter->recipient && $letter->sender) {
                $this->notifications->sync(new LetterArrivedBlueprint($letter), [$letter->recipient]);
                $this->notifications->sync(new LetterDeliveredBlueprint($letter), [$letter->sender]);
            }
            Letter::query()->whereKey($letter->id)->whereNull('notified_at')->update(['notified_at' => Carbon::now()]);
        } catch (Throwable $e) {
            $this->log->error('[slow-mail] notifying letter '.$letter->id.': '.$e->getMessage());
        }
    }

    /** The next arrival, cached for the visit check. */
    private function remember(): string
    {
        $next = Letter::query()->whereNull('delivered_at')->whereNull('returned_at')->min('arrives_at');
        $value = $next ? (string) Carbon::parse($next, 'UTC')->getTimestamp() : self::NONE;
        $this->cache->put(self::NEXT, $value, self::TTL);

        return $value;
    }
}
