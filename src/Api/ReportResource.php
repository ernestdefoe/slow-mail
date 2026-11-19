<?php

namespace ErnestDefoe\SlowMail\Api;

use Carbon\Carbon;
use ErnestDefoe\SlowMail\Block;
use ErnestDefoe\SlowMail\Job\NotifyModerators;
use ErnestDefoe\SlowMail\Letter;
use ErnestDefoe\SlowMail\Refused;
use ErnestDefoe\SlowMail\Report;
use ErnestDefoe\SlowMail\Settings;
use ErnestDefoe\SlowMail\Vault;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tobyz\JsonApiServer\Context as BaseContext;

/**
 * The `slow-mail-reports` type: a letter its recipient chose to show the
 * moderators. The only way a letter's words ever reach anyone else.
 *
 * POST  /api/slow-mail-reports        {reason?, block?, relationships.letter}: the recipient of an arrived letter
 * GET   /api/slow-mail-reports        moderators: open reports, words included
 * PATCH /api/slow-mail-reports/{id}   moderators: {resolved: true}
 *
 * @extends AbstractDatabaseResource<Report>
 */
class ReportResource extends AbstractDatabaseResource
{
    public function __construct(private readonly Vault $vault, private readonly Queue $queue)
    {
    }

    public function type(): string
    {
        return 'slow-mail-reports';
    }

    public function model(): string
    {
        return Report::class;
    }

    /** @param Builder<Report> $query */
    public function scope(Builder $query, BaseContext $context): void
    {
        $actor = $context->getActor();
        if (! $actor->hasPermission(Settings::MODERATE)) {
            $query->where('slow_mail_reports.reporter_id', $actor->isGuest() ? 0 : $actor->id);
        } elseif ($context->endpoint instanceof Endpoint\Index) {
            $query->whereNull('slow_mail_reports.resolved_at');
        }
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->authenticated()
                ->can(Settings::MODERATE)
                ->defaultInclude(['reporter', 'sender'])
                ->defaultSort('-reportedAt')
                ->paginate(20, 50),
            Endpoint\Create::make()
                ->authenticated(),
            Endpoint\Update::make()
                ->authenticated()
                ->can(Settings::MODERATE),
        ];
    }

    public function fields(): array
    {
        $moderator = fn (Report $report, Context $context) => $context->getActor()->hasPermission(Settings::MODERATE);

        return [
            Schema\Str::make('reason')
                ->writableOnCreate()
                ->nullable()
                ->maxLength(500)
                ->set(fn (Report $report, mixed $value) => $report->reason = trim((string) $value)),
            // Also stop letters between the two, in the same step. Not stored on the report.
            Schema\Boolean::make('block')
                ->writableOnCreate()
                ->hidden()
                ->set(fn (Report $report, mixed $value) => $report->blockSender = (bool) $value),
            Schema\Str::make('body')
                ->visible($moderator)
                ->get(fn (Report $report) => $this->vault->open($report->body, $report->context())),
            Schema\DateTime::make('sentAt')->property('letter_sent_at'),
            Schema\DateTime::make('reportedAt')->property('created_at'),
            Schema\Boolean::make('resolved')
                ->get(fn (Report $report) => $report->resolved_at !== null)
                ->writable($moderator)
                ->set(function (Report $report, mixed $value, Context $context) {
                    $report->resolved_at = $value ? Carbon::now() : null;
                    $report->resolved_by = $value ? $context->getActor()->id : null;
                }),
            // Which letter: written once, never read back (moderators get its words above, not the letter).
            Schema\Relationship\ToOne::make('letter')
                ->type('slow-mail-letters')
                ->writableOnCreate()
                ->requiredOnCreate()
                ->hidden(),
            Schema\Relationship\ToOne::make('reporter')
                ->type('users')
                ->includable(),
            Schema\Relationship\ToOne::make('sender')
                ->type('users')
                ->includable()
                ->visible($moderator),
        ];
    }

    /** @return list<SortColumn> */
    public function sorts(): array
    {
        return [SortColumn::make('reportedAt')->column('created_at')];
    }

    /** @param Context $context */
    public function creating(object $model, BaseContext $context): ?object
    {
        $actor = $context->getActor();
        // Found through LetterResource's scope already; only its recipient, once it has arrived, may report it.
        $letter = Letter::query()->find($model->letter_id);
        if (! $letter || $letter->recipient_id !== $actor->id || ! $letter->isDelivered() || $letter->recipient_hidden) {
            throw new ModelNotFoundException();
        }
        if (Report::query()->where('letter_id', $letter->id)->where('reporter_id', $actor->id)->exists()) {
            throw new Refused(Refused::ALREADY_REPORTED);
        }
        if (Report::query()->where('reporter_id', $actor->id)->where('created_at', '>', Carbon::now()->subDay())->count() >= Report::PER_DAY) {
            throw new Refused(Refused::SLOW_DOWN);
        }

        $model->reporter_id = $actor->id;
        $model->sender_id = $letter->sender_id;
        $model->reason ??= '';
        $model->letter_sent_at = $letter->sent_at;
        $model->created_at = Carbon::now();
        // The words are copied, encrypted, so the report stands even if the letter is put away.
        $model->body = $this->vault->seal($this->vault->open($letter->body, $letter->context()) ?? '', $model->context());

        return $model;
    }

    /** @param Context $context */
    public function created(object $model, BaseContext $context): ?object
    {
        if ($model->blockSender) {
            Block::put($model->reporter_id, $model->sender_id);
        }
        $this->queue->push(new NotifyModerators($model->id));

        return $model;
    }
}
