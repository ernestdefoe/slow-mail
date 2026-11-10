<?php

namespace ErnestDefoe\SlowMail\Api;

use Carbon\Carbon;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/slow-mail/blocks   { user, blocked: bool }
 *
 * Stop (or allow again) letters with someone, both ways. Letters already on
 * their way between you are returned to their sender when they arrive.
 */
class BlockController implements RequestHandlerInterface
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $body = Input::body($request);
        $user = User::query()->find(Input::id($body['user'] ?? null));
        if (! $user || $user->id === $actor->id) {
            return Errors::make(404, 'not_found');
        }

        $row = $this->db->table('slow_mail_blocks')->where('user_id', $actor->id)->where('blocked_id', $user->id);
        $blocked = (bool) ($body['blocked'] ?? true);
        if (! $blocked) {
            $row->delete();
        } elseif (! $row->exists()) {
            $this->db->table('slow_mail_blocks')->insertOrIgnore(['user_id' => $actor->id, 'blocked_id' => $user->id, 'created_at' => Carbon::now()]);
        }

        return new JsonResponse(['blocked' => $blocked]);
    }
}
