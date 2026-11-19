<?php

namespace ErnestDefoe\SlowMail\Api;

use ErnestDefoe\SlowMail\Matcher;
use ErnestDefoe\SlowMail\Seeker;
use ErnestDefoe\SlowMail\Settings;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/slow-mail/matches: who I might write to, only once I'm listed
 * myself. A plain handler because it is a ranking, not a stored list: both
 * members' wishes and places (Member Map, as far as each may see the other)
 * and shared interests decide who is suggested and in what order (Matcher).
 */
class MatchesController implements RequestHandlerInterface
{
    public function __construct(private readonly Matcher $matcher)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan(Settings::SEND);

        $mine = Seeker::query()->find($actor->id);

        return new JsonResponse(['matches' => $mine ? $this->matcher->for($actor, $mine) : []]);
    }
}
