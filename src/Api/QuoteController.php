<?php

namespace ErnestDefoe\SlowMail\Api;

use Carbon\Carbon;
use ErnestDefoe\SlowMail\Block;
use ErnestDefoe\SlowMail\Journey;
use ErnestDefoe\SlowMail\Refused;
use ErnestDefoe\SlowMail\Rules;
use ErnestDefoe\SlowMail\Settings;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/slow-mail/quote?to=ID: before writing, whether this member takes
 * your letters, how long one would take, and whether you blocked them. A
 * computed answer, not a record, so a plain handler. It is the same answer
 * sending gives (Journey is the same for the same two places), so it tells
 * nothing more; 30 a minute anyway.
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
        $count = (int) $this->cache->get($key) + 1;
        $this->cache->put($key, $count, 120);
        if ($count > self::PER_MINUTE) {
            throw new Refused(Refused::SLOW_DOWN);
        }

        $recipient = User::query()->find((int) Arr::get($request->getQueryParams(), 'to'));
        $blocked = $recipient && Block::query()->where('user_id', $actor->id)->where('blocked_id', $recipient->id)->exists();
        if ($code = $this->rules->refusal($actor, $recipient)) {
            return new JsonResponse(['ok' => false, 'reason' => $code, 'blocked' => $blocked]);
        }
        /** @var User $recipient */
        $plan = $this->journey->plan($actor, $recipient, Carbon::now());

        return new JsonResponse([
            'ok' => true,
            'blocked' => false,
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
