<?php

namespace ErnestDefoe\SlowMail\Api;

use ErnestDefoe\SlowMail\Access;
use ErnestDefoe\SlowMail\Letter;
use ErnestDefoe\SlowMail\Members;
use ErnestDefoe\SlowMail\Present;
use ErnestDefoe\SlowMail\Rules;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/slow-mail/pals/{id}: every letter between you and one member that
 * you may see (Access::scope), as envelopes, newest first, and whether you
 * can write to them now.
 */
class CorrespondenceController implements RequestHandlerInterface
{
    public function __construct(private readonly Present $present, private readonly Rules $rules, private readonly Members $members)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $user = User::query()->whereVisibleTo($actor)->find((int) Arr::get($request->getQueryParams(), 'id'));
        if (! $user || $user->id === $actor->id) {
            return Errors::make(404, 'not_found');
        }

        $letters = Access::scope($actor)
            ->where(fn ($q) => $q->where('sender_id', $user->id)->orWhere('recipient_id', $user->id))
            ->orderByDesc('sent_at')->limit(100)->get();
        $letters->each(function (Letter $letter) use ($actor, $user) {
            $letter->setRelation('sender', $letter->sender_id === $actor->id ? $actor : $user);
            $letter->setRelation('recipient', $letter->recipient_id === $actor->id ? $actor : $user);
        });

        return new JsonResponse([
            'user' => $this->present->user($user),
            'letters' => $letters->map(fn (Letter $l) => $this->present->envelope($l, $actor))->values(),
            'canWrite' => $this->rules->refusal($actor, $user) === null,
            'blocked' => isset($this->members->blockedBy($actor->id)[$user->id]),
        ]);
    }
}
