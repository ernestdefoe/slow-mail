<?php

namespace ErnestDefoe\SlowMail\Api;

use ErnestDefoe\SlowMail\Matcher;
use ErnestDefoe\SlowMail\Seeker;
use ErnestDefoe\SlowMail\Settings;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST   /api/slow-mail/seeking   { interests, region }: list me as looking for a pen pal
 * DELETE /api/slow-mail/seeking: take me off
 * GET    /api/slow-mail/matches: who I might write to (only once I'm listed myself)
 */
class SeekerController implements RequestHandlerInterface
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

        if ($request->getMethod() === 'GET') {
            return new JsonResponse(['matches' => $mine ? $this->matcher->for($actor, $mine) : []]);
        }

        if ($request->getMethod() === 'DELETE') {
            $mine?->delete();

            return new EmptyResponse(204);
        }

        $body = Input::body($request);
        $region = is_string($body['region'] ?? null) && in_array($body['region'], Seeker::REGIONS, true) ? $body['region'] : 'anywhere';

        $mine ??= new Seeker();
        $mine->user_id = $actor->id;
        $mine->interests = implode(', ', Seeker::parseInterests($body['interests'] ?? ''));
        $mine->region = $region;
        $mine->save();

        return new JsonResponse(['seeker' => ['interests' => $mine->interestList(), 'region' => $mine->region]]);
    }
}
