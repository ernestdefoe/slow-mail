<?php

namespace ErnestDefoe\SlowMail\Api;

use ErnestDefoe\SlowMail\Block;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tobyz\JsonApiServer\Exception\NotFoundException;

/**
 * POST /api/slow-mail/blocks   { user, blocked: bool }
 *
 * A toggle, so a plain handler: stop (or allow again) letters with someone,
 * both ways. Letters already on their way between you are returned to their
 * sender when they arrive (Mailroom).
 */
class BlockController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $body = (array) $request->getParsedBody();
        $user = User::query()->find((int) Arr::get($body, 'user'));
        if (! $user || $user->id === $actor->id) {
            throw new NotFoundException();
        }

        $blocked = (bool) Arr::get($body, 'blocked', true);
        $blocked ? Block::put($actor->id, $user->id) : Block::lift($actor->id, $user->id);

        return new JsonResponse(['blocked' => $blocked]);
    }
}
