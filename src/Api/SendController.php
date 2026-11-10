<?php

namespace ErnestDefoe\SlowMail\Api;

use Carbon\Carbon;
use ErnestDefoe\SlowMail\Journey;
use ErnestDefoe\SlowMail\KeyMissing;
use ErnestDefoe\SlowMail\Letter;
use ErnestDefoe\SlowMail\Mailroom;
use ErnestDefoe\SlowMail\Present;
use ErnestDefoe\SlowMail\Rules;
use ErnestDefoe\SlowMail\Settings;
use ErnestDefoe\SlowMail\Stamps;
use ErnestDefoe\SlowMail\Vault;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/slow-mail/letters   { to, body, stamp, replyTo? }
 *
 * Sends a letter: checked (Rules), encrypted, and given its journey
 * (Journey). It arrives later through Mailroom; until then it exists only in
 * the sender's outbox. The answer is the envelope.
 */
class SendController implements RequestHandlerInterface
{
    public function __construct(
        private readonly Rules $rules,
        private readonly Journey $journey,
        private readonly Vault $vault,
        private readonly Mailroom $mailroom,
        private readonly Present $present,
        private readonly Cache $cache,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan(Settings::SEND);

        $body = Input::body($request);
        $text = Input::text($body, 'body');
        $stamp = is_string($body['stamp'] ?? null) ? $body['stamp'] : '';
        if ($text === '' || mb_strlen($text) > Settings::MAX_LENGTH || ! Stamps::valid($stamp)) {
            return Errors::make(422, 'slow_mail_bad_letter');
        }

        $recipient = User::query()->find(Input::id($body['to'] ?? null));
        if ($code = $this->rules->refusal($actor, $recipient)) {
            return Errors::make(422, $code);
        }
        /** @var User $recipient */

        // A reply answers a letter that arrived for you from this same person.
        $replyTo = Input::id($body['replyTo'] ?? null) ?: null;
        if ($replyTo && ! Letter::query()->whereKey($replyTo)->where('sender_id', $recipient->id)->where('recipient_id', $actor->id)->whereNotNull('delivered_at')->exists()) {
            return Errors::make(422, 'slow_mail_bad_letter');
        }

        // One send at a time per member, so two quick clicks can't slip past the limits together.
        $lock = $this->cache->lock('slow-mail.send.'.$actor->id, 10);
        if (! $lock->get()) {
            return Errors::make(429, 'slow_mail_daily_limit');
        }

        try {
            if ($limit = $this->rules->limit($actor, $recipient)) {
                return Errors::make($limit[0], $limit[1]);
            }

            try {
                $now = Carbon::now();
                $plan = $this->journey->plan($actor, $recipient, $now);
                $sealed = $this->vault->seal($text, Letter::contextFor($actor->id, $recipient->id));
            } catch (KeyMissing) {
                return Errors::make(503, 'slow_mail_key_missing');
            }

            $letter = new Letter();
            $letter->sender_id = $actor->id;
            $letter->recipient_id = $recipient->id;
            $letter->reply_to_id = $replyTo;
            $letter->body = $sealed;
            $letter->words = Input::words($text);
            $letter->stamp = $stamp === Stamps::HOME && ! $plan['origin'] ? Stamps::THEMES[0] : $stamp;
            $letter->postmark = $plan['postmark'];
            $letter->origin_country = $plan['origin'];
            $letter->destination = $plan['destination'];
            $letter->travel_hours = $plan['hours'];
            $letter->sent_at = $now;
            $letter->arrives_at = $plan['arrives'];
            $letter->save();
        } finally {
            $lock->release();
        }

        $this->mailroom->expect($letter->arrives_at);

        $letter->setRelation('sender', $actor);
        $letter->setRelation('recipient', $recipient);

        return new JsonResponse(['letter' => $this->present->envelope($letter, $actor)], 201);
    }
}
