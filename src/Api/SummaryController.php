<?php

namespace ErnestDefoe\SlowMail\Api;

use Carbon\Carbon;
use ErnestDefoe\SlowMail\Letter;
use ErnestDefoe\SlowMail\Pals;
use ErnestDefoe\SlowMail\PlaceSource;
use ErnestDefoe\SlowMail\Rules;
use ErnestDefoe\SlowMail\Settings;
use ErnestDefoe\SlowMail\Stamps;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/slow-mail: the member's post office at a glance (pen pals, counts
 * for the tabs, their listing, their own postmark, limits). A plain handler
 * because it is a summary of aggregates, not a record: the letters
 * themselves are the `slow-mail-letters` resource.
 */
class SummaryController implements RequestHandlerInterface
{
    public function __construct(
        private readonly Pals $pals,
        private readonly PlaceSource $places,
        private readonly Rules $rules,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $home = $this->places->seenBy($actor, [$actor->id])[$actor->id] ?? null;
        $mine = Letter::query()->where('sender_id', $actor->id);

        return new JsonResponse([
            'pals' => $this->pals->of($actor),
            'unread' => Letter::query()->where('recipient_id', $actor->id)->whereNotNull('delivered_at')
                ->whereNull('read_at')->where('recipient_hidden', false)->count(),
            'transit' => $mine->clone()->whereNull('delivered_at')->whereNull('returned_at')->where('sender_hidden', false)->count(),
            'home' => $home ? ['postmark' => $home['postmark'], 'country' => $home['country']] : null,
            'can' => ['send' => $actor->can(Settings::SEND), 'map' => $this->places->enabled()],
            'limits' => [
                'daily' => $this->rules->daily(),
                'today' => $mine->clone()->where('sent_at', '>', Carbon::now()->subDay())->count(),
                'maxLength' => Settings::MAX_LENGTH,
            ],
            'stamps' => Stamps::all(),
            'accept' => (string) $actor->getPreference(Settings::ACCEPT),
        ]);
    }
}
