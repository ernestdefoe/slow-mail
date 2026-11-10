<?php

namespace ErnestDefoe\SlowMail\Api;

use Carbon\Carbon;
use ErnestDefoe\SlowMail\KeyMissing;
use ErnestDefoe\SlowMail\Letter;
use ErnestDefoe\SlowMail\Members;
use ErnestDefoe\SlowMail\Notification\LetterReportedBlueprint;
use ErnestDefoe\SlowMail\Present;
use ErnestDefoe\SlowMail\Report;
use ErnestDefoe\SlowMail\Settings;
use ErnestDefoe\SlowMail\Vault;
use Flarum\Http\RequestUtil;
use Flarum\Notification\NotificationSyncer;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * POST /api/slow-mail/letters/{id}/report   { reason, block }
 *      The recipient shows one letter to the moderators: its words are
 *      copied into the report (encrypted), moderators are notified, and the
 *      sender is blocked if asked. Nothing else about the member's letters
 *      is shared. One report per letter; 20 a day.
 * GET  /api/slow-mail/reports                 moderators: open reports, words included
 * POST /api/slow-mail/reports/{id}/resolve    moderators: done with it
 */
class ReportController implements RequestHandlerInterface
{
    private const SHOW = 50;
    private const NOTIFY = 50;

    public function __construct(
        private readonly Vault $vault,
        private readonly Present $present,
        private readonly Members $members,
        private readonly NotificationSyncer $notifications,
        private readonly ConnectionInterface $db,
        private readonly LoggerInterface $log,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $route = (string) $request->getAttribute('routeName');
        $id = (int) Arr::get($request->getQueryParams(), 'id');

        if ($route === 'slow-mail.reports') {
            $actor->assertCan(Settings::MODERATE);

            return $this->list();
        }

        if ($route === 'slow-mail.reports.resolve') {
            $actor->assertCan(Settings::MODERATE);
            Report::query()->whereKey($id)->whereNull('resolved_at')->update(['resolved_at' => Carbon::now(), 'resolved_by' => $actor->id]);

            return new EmptyResponse(204);
        }

        // Reporting: only the recipient, only a letter that has arrived.
        $letter = Letter::query()->with('sender')->find($id);
        if (! $letter || $letter->recipient_id !== $actor->id || ! $letter->isDelivered() || $letter->recipient_hidden) {
            return Errors::make(404, 'not_found');
        }
        if (Report::query()->where('letter_id', $letter->id)->where('reporter_id', $actor->id)->exists()) {
            return Errors::make(422, 'slow_mail_already_reported');
        }
        if (Report::query()->where('reporter_id', $actor->id)->where('created_at', '>', Carbon::now()->subDay())->count() >= Report::PER_DAY) {
            return Errors::make(429, 'slow_mail_slow_down');
        }

        $words = $this->vault->open($letter->body, $letter->context());
        $body = Input::body($request);

        $report = new Report();
        $report->letter_id = $letter->id;
        $report->reporter_id = $actor->id;
        $report->sender_id = $letter->sender_id;
        $report->reason = mb_substr(Input::text($body, 'reason'), 0, 500);
        $report->letter_sent_at = $letter->sent_at;
        $report->created_at = Carbon::now();
        try {
            $report->body = $this->vault->seal($words ?? '', $report->context());
        } catch (KeyMissing) {
            return Errors::make(503, 'slow_mail_key_missing');
        }
        $report->save();

        if (! empty($body['block'])) {
            $this->db->table('slow_mail_blocks')->insertOrIgnore(['user_id' => $actor->id, 'blocked_id' => $letter->sender_id, 'created_at' => Carbon::now()]);
        }

        try {
            $report->setRelation('reporter', $actor);
            $moderators = $this->members->withPermission(Settings::MODERATE)->where('users.id', '<>', $letter->sender_id)->limit(self::NOTIFY)->get();
            $this->notifications->sync(new LetterReportedBlueprint($report), $moderators->all());
        } catch (Throwable $e) {
            $this->log->error('[slow-mail] notifying moderators of report '.$report->id.': '.$e->getMessage());
        }

        return new JsonResponse(['reported' => true], 201);
    }

    private function list(): ResponseInterface
    {
        $reports = Report::query()->whereNull('resolved_at')->with(['reporter', 'sender'])->orderByDesc('created_at')->limit(self::SHOW)->get();

        return new JsonResponse(['reports' => $reports->map(fn (Report $r) => [
            'id' => $r->id,
            'reporter' => $this->present->user($r->reporter),
            'sender' => $this->present->user($r->sender),
            'reason' => $r->reason,
            'body' => $this->vault->open($r->body, $r->context()),
            'sentAt' => $r->letter_sent_at->toIso8601String(),
            'reportedAt' => $r->created_at->toIso8601String(),
        ])->values()]);
    }
}
