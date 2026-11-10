<?php

/*
 * Slow Mail: pen-pal letters that take time to arrive.
 *
 * A letter is encrypted before it reaches the database (Vault: libsodium,
 * key outside the database) and travels for a while (Journey: by distance
 * through Member Map when both members can see each other there, rounded and
 * jittered; else a set time). Until it arrives it exists only in its
 * sender's outbox: no API resource, no include, no search, no notification.
 * Access is the one gate and Present the one place a letter is decrypted.
 *
 * Mailroom delivers on the scheduler every minute, idempotently, and on the
 * next visit when no scheduler runs (DeliverOnVisit: a cache read between
 * arrivals, no query).
 *
 * 🚨 String literals only below: reading this extension's class constants
 * here autoloads during boot and fatals every request mid-deploy.
 */

use ErnestDefoe\SlowMail\Api;
use ErnestDefoe\SlowMail\Console\DeliverCommand;
use ErnestDefoe\SlowMail\Forget;
use ErnestDefoe\SlowMail\Gdpr\ExportActor;
use ErnestDefoe\SlowMail\Gdpr\SlowMailData;
use ErnestDefoe\SlowMail\Http\DeliverOnVisit;
use ErnestDefoe\SlowMail\Http\PageContent;
use ErnestDefoe\SlowMail\Notification\LetterArrivedBlueprint;
use ErnestDefoe\SlowMail\Notification\LetterDeliveredBlueprint;
use ErnestDefoe\SlowMail\Notification\LetterReportedBlueprint;
use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\Gdpr\Events\Exported;
use Flarum\Gdpr\Events\Exporting;
use Flarum\Gdpr\Extend\UserData;
use Flarum\User\Event\Deleted;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // 🚨 Not optional: the page and the writing desk are chunks in here, and undeclared chunks 404.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        ->route('/slow-mail', 'slow-mail', PageContent::class)
        ->route('/slow-mail/outbox', 'slow-mail.outbox', PageContent::class)
        ->route('/slow-mail/pals', 'slow-mail.pals', PageContent::class)
        ->route('/slow-mail/pals/{id:\d+}', 'slow-mail.pal', PageContent::class)
        ->route('/slow-mail/letters/{id:\d+}', 'slow-mail.letter', PageContent::class)
        ->route('/slow-mail/reports', 'slow-mail.reports', PageContent::class),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\View())
        ->namespace('ernestdefoe-slow-mail', __DIR__.'/views'),

    (new Extend\Routes('api'))
        ->get('/slow-mail', 'slow-mail.index', Api\PageController::class)
        ->get('/slow-mail/quote', 'slow-mail.quote', Api\QuoteController::class)
        ->post('/slow-mail/letters', 'slow-mail.send', Api\SendController::class)
        ->get('/slow-mail/letters/{id:\d+}', 'slow-mail.show', Api\LetterController::class)
        ->post('/slow-mail/letters/{id:\d+}/read', 'slow-mail.read', Api\LetterController::class)
        ->post('/slow-mail/letters/{id:\d+}/report', 'slow-mail.report', Api\ReportController::class)
        ->get('/slow-mail/reports', 'slow-mail.reports', Api\ReportController::class)
        ->post('/slow-mail/reports/{id:\d+}/resolve', 'slow-mail.reports.resolve', Api\ReportController::class)
        ->delete('/slow-mail/letters/{id:\d+}', 'slow-mail.hide', Api\LetterController::class)
        ->get('/slow-mail/pals/{id:\d+}', 'slow-mail.correspondence', Api\CorrespondenceController::class)
        ->post('/slow-mail/blocks', 'slow-mail.block', Api\BlockController::class)
        ->get('/slow-mail/matches', 'slow-mail.matches', Api\SeekerController::class)
        ->post('/slow-mail/seeking', 'slow-mail.seeking', Api\SeekerController::class)
        ->delete('/slow-mail/seeking', 'slow-mail.seeking.delete', Api\SeekerController::class),

    (new Extend\Middleware('api'))->add(DeliverOnVisit::class),
    (new Extend\Middleware('forum'))->add(DeliverOnVisit::class),

    (new Extend\Console())
        ->command(DeliverCommand::class)
        ->schedule(DeliverCommand::class, function (ScheduledEvent $event): void {
            $event->everyMinute()->withoutOverlapping();
        }),

    (new Extend\Notification())
        ->type(LetterArrivedBlueprint::class, ['alert', 'email'])
        ->type(LetterDeliveredBlueprint::class, ['alert'])
        ->type(LetterReportedBlueprint::class, ['alert']),

    (new Extend\User())
        ->registerPreference('slowMailAccept', fn ($value) => in_array($value, ['everyone', 'pals', 'nobody'], true) ? $value : 'everyone', 'everyone'),

    // The viewer's own permission: already loaded, so no query.
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('slowMailCanSend')
                ->get(fn ($forum, Context $context) => $context->getActor()->can('slow-mail.send')),
            Schema\Boolean::make('slowMailCanModerate')
                ->get(fn ($forum, Context $context) => $context->getActor()->can('slow-mail.moderate')),
        ]),

    (new Extend\Settings())
        ->default('slow-mail.nav', true)
        ->default('slow-mail.km_per_day', 2000)
        ->default('slow-mail.min_hours', 12)
        ->default('slow-mail.max_hours', 168)
        ->default('slow-mail.default_hours', 24)
        ->default('slow-mail.daily_limit', 10)
        ->default('slow-mail.new_pals_daily', 3)
        ->serializeToForum('slowMailNav', 'slow-mail.nav', 'boolval'),

    (new Extend\Event())
        ->listen(Deleted::class, fn (Deleted $event) => Forget::user((int) $event->user->id)),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new UserData())->addType(SlowMailData::class),
            (new Extend\Event())
                ->listen(Exporting::class, [ExportActor::class, 'exporting'])
                ->listen(Exported::class, [ExportActor::class, 'exported']),
        ]),
];
