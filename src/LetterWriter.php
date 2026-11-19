<?php

namespace ErnestDefoe\SlowMail;

use Carbon\Carbon;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Turns a new letter into one ready to travel: checked (Rules), given its
 * journey (Journey) and encrypted (Vault). It arrives later through Mailroom;
 * until then it exists only in its sender's outbox.
 */
class LetterWriter
{
    public function __construct(
        private readonly Rules $rules,
        private readonly Journey $journey,
        private readonly Vault $vault,
        private readonly Mailroom $mailroom,
        private readonly Cache $cache,
    ) {
    }

    /** Everything that doesn't depend on the sender's limits: who to, what, and as a reply to what. */
    public function check(Letter $letter, User $sender, string $text): User
    {
        if ($text === '' || mb_strlen($text) > Settings::MAX_LENGTH || ! Stamps::valid($letter->stamp)) {
            throw new Refused(Refused::BAD_LETTER);
        }

        $recipient = $letter->recipient;
        if ($code = $this->rules->refusal($sender, $recipient)) {
            throw new Refused($code);
        }
        /** @var User $recipient */

        // A reply answers a letter that arrived for you from this same person.
        if ($letter->reply_to_id && ! Letter::query()->whereKey($letter->reply_to_id)
            ->where('sender_id', $recipient->id)->where('recipient_id', $sender->id)->whereNotNull('delivered_at')->exists()) {
            throw new Refused(Refused::BAD_LETTER);
        }

        return $recipient;
    }

    /**
     * Sends it: one send at a time per member (a cache lock, so two quick
     * clicks can't slip past the limits together), within the limits, on its
     * journey, encrypted.
     */
    public function send(Letter $letter, User $sender, User $recipient, string $text, callable $save): void
    {
        $lock = $this->cache->lock('slow-mail.send.'.$sender->id, 10);
        if (! $lock->get()) {
            throw new Refused(Refused::SLOW_DOWN);
        }

        try {
            if ($limit = $this->rules->limit($sender, $recipient)) {
                throw new Refused($limit[1]);
            }

            $now = Carbon::now();
            $plan = $this->journey->plan($sender, $recipient, $now);

            $letter->sender_id = $sender->id;
            $letter->body = $this->vault->seal($text, Letter::contextFor($sender->id, $recipient->id));
            $letter->words = count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);
            $letter->stamp = $letter->stamp === Stamps::HOME && ! $plan['origin'] ? Stamps::THEMES[0] : $letter->stamp;
            $letter->postmark = $plan['postmark'];
            $letter->origin_country = $plan['origin'];
            $letter->destination = $plan['destination'];
            $letter->travel_hours = $plan['hours'];
            $letter->sent_at = $now;
            $letter->arrives_at = $plan['arrives'];
            $save();
        } finally {
            $lock->release();
        }

        $this->mailroom->expect($letter->arrives_at);
    }

    /** Trimmed, line breaks kept (\r\n → \n), control characters dropped. */
    public static function clean(mixed $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", is_string($text) ? $text : '');

        return trim((string) preg_replace('/[^\P{Cc}\n\t]/u', '', $text));
    }
}
