<?php

namespace ErnestDefoe\SlowMail\Api;

use Carbon\Carbon;
use ErnestDefoe\SlowMail\Access;
use ErnestDefoe\SlowMail\Letter;
use ErnestDefoe\SlowMail\Present;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET    /api/slow-mail/letters/{id}: one letter, words included, for its
 *        sender, or its recipient once it has arrived (the first read marks it).
 * DELETE /api/slow-mail/letters/{id}: put it away, for you only. The row goes
 *        when both have put it away. A letter still on its way can't be.
 *
 * Anyone else gets 404, admins included: as if it didn't exist.
 */
class LetterController implements RequestHandlerInterface
{
    public function __construct(private readonly Present $present)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $letter = Letter::query()->with(['sender', 'recipient'])->find((int) Arr::get($request->getQueryParams(), 'id'));
        if (! $letter || ! Access::canSee($letter, $actor)) {
            return Errors::make(404, 'not_found');
        }

        $mine = $actor->id === $letter->sender_id;

        if ($request->getMethod() === 'DELETE') {
            if ($letter->inTransit()) {
                return Errors::make(422, 'slow_mail_in_transit');
            }
            $other = $mine ? $letter->recipient_hidden || $letter->returned_at : $letter->sender_hidden;
            if ($other) {
                $letter->delete();
            } else {
                Letter::query()->whereKey($letter->id)->update([$mine ? 'sender_hidden' : 'recipient_hidden' => true]);
            }

            return new EmptyResponse(204);
        }

        if (! $mine && ! $letter->read_at) {
            $letter->read_at = Carbon::now();
            Letter::query()->whereKey($letter->id)->whereNull('read_at')->update(['read_at' => $letter->read_at]);
            $firstRead = true;
        }

        return new JsonResponse(['letter' => $this->present->letter($letter, $actor) + ['firstRead' => $firstRead ?? false]]);
    }
}
