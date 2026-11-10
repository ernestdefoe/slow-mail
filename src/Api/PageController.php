<?php

namespace ErnestDefoe\SlowMail\Api;

use Carbon\Carbon;
use ErnestDefoe\SlowMail\Letter;
use ErnestDefoe\SlowMail\Pals;
use ErnestDefoe\SlowMail\Places;
use ErnestDefoe\SlowMail\Present;
use ErnestDefoe\SlowMail\Rules;
use ErnestDefoe\SlowMail\Seeker;
use ErnestDefoe\SlowMail\Settings;
use ErnestDefoe\SlowMail\Stamps;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/slow-mail: the member's post office in one answer: the inbox
 * (letters that have arrived, as envelopes), the outbox (what they sent,
 * with each letter's journey), pen pals, their pen-pal listing and limits.
 * Words are never in here: a letter is opened through LetterController.
 * Due letters were already delivered by DeliverOnVisit before this ran.
 */
class PageController implements RequestHandlerInterface
{
    private const BOX = 100;

    public function __construct(
        private readonly Present $present,
        private readonly Pals $pals,
        private readonly Places $places,
        private readonly Rules $rules,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $inbox = Letter::query()->where('recipient_id', $actor->id)->whereNotNull('delivered_at')->where('recipient_hidden', false)
            ->with('sender')->orderByDesc('delivered_at')->limit(self::BOX)->get();
        $outbox = Letter::query()->where('sender_id', $actor->id)->where('sender_hidden', false)
            ->with('recipient')->orderByDesc('sent_at')->limit(self::BOX)->get();

        $seeker = Seeker::query()->find($actor->id);
        $home = $this->places->seenBy($actor, [$actor->id])[$actor->id] ?? null;
        $today = Letter::query()->where('sender_id', $actor->id)->where('sent_at', '>', Carbon::now()->subDay())->count();

        return new JsonResponse([
            'inbox' => $inbox->map(fn (Letter $l) => $this->present->envelope($l, $actor))->values(),
            'outbox' => $outbox->map(fn (Letter $l) => $this->present->envelope($l, $actor))->values(),
            'pals' => $this->pals->of($actor),
            'seeker' => $seeker ? ['interests' => $seeker->interestList(), 'region' => $seeker->region] : null,
            'home' => $home ? ['postmark' => $home['postmark'], 'country' => $home['country']] : null,
            'can' => ['send' => $actor->can(Settings::SEND), 'map' => $this->places->enabled()],
            'limits' => [
                'daily' => $this->rules->daily(),
                'today' => $today,
                'maxLength' => Settings::MAX_LENGTH,
            ],
            'stamps' => array_merge([Stamps::HOME], Stamps::THEMES),
            'accept' => (string) $actor->getPreference(Settings::ACCEPT, Settings::ACCEPT_EVERYONE),
        ]);
    }
}
