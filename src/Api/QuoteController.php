<?php

namespace ErnestDefoe\SlowMail\Api;

use Carbon\Carbon;
use ErnestDefoe\SlowMail\Journey;
use ErnestDefoe\SlowMail\KeyMissing;
use ErnestDefoe\SlowMail\Rules;
use ErnestDefoe\SlowMail\Settings;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/slow-mail/quote?to=ID: before writing, whether this member takes
 * your letters and how long one would take. The same answer sending gives
 * (Journey is the same for the same two places), so it tells nothing more;
 * capped at 30 a minute anyway.
 */
class QuoteController implements RequestHandlerInterface
{
    private const PER_MINUTE = 30;

    public function __construct(private readonly Rules $rules, private readonly Journey $journey, private readonly Cache $cache)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan(Settings::SEND);

        $key = 'slow-mail.quotes.'.$actor->id.'.'.intdiv(time(), 60);
        $count = (int) $this->cache->get($key, 0) + 1;
        $this->cache->put($key, $count, 120);
        if ($count > self::PER_MINUTE) {
            return Errors::make(429, 'slow_mail_slow_down');
        }

        $recipient = User::query()->find(Input::id($request->getQueryParams()['to'] ?? null));
        if ($code = $this->rules->refusal($actor, $recipient)) {
            return new JsonResponse(['ok' => false, 'reason' => $code]);
        }
        /** @var User $recipient */

        try {
            $plan = $this->journey->plan($actor, $recipient, Carbon::now());
        } catch (KeyMissing) {
            return Errors::make(503, 'slow_mail_key_missing');
        }

        return new JsonResponse([
            'ok' => true,
            'hours' => $plan['hours'],
            'arrivesAt' => $plan['arrives']->toIso8601String(),
            'postmark' => $plan['postmark'],
            'origin' => $plan['origin'],
            'destination' => $plan['destination'],
            'mapped' => $plan['mapped'],
            'limit' => ($limit = $this->rules->limit($actor, $recipient)) ? $limit[1] : null,
        ]);
    }
}
