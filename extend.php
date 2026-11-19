<?php

/*
 * Slow Mail: pen-pal letters that take time to arrive.
 *
 * A letter is encrypted before it reaches the database (Vault: libsodium,
 * key outside the database) and travels for a while (Journey: by distance
 * through Member Map when both members can see each other there, rounded and
 * jittered; else a set time). Until it arrives it exists only in its
 * sender's outbox: ScopeLetterVisibility is the one gate on every letters
 * query, and LetterResource (a single letter, for its two members) the one
 * place a letter is decrypted for output. No notification carries its words.
 *
 * Mailroom delivers on the scheduler every minute, idempotently, and on the
 * next visit when no scheduler runs (DeliverOnVisit queues it: a cache read
 * between arrivals, no query).
 *
 * 🚨 String literals only below: reading this extension's class constants
 * here autoloads during boot and fatals every request mid-deploy.
 */

use ErnestDefoe\SlowMail\Access\ScopeLetterVisibility;
use ErnestDefoe\SlowMail\Api;
use ErnestDefoe\SlowMail\Console\DeliverCommand;
use ErnestDefoe\SlowMail\Extend\Integrate;
use ErnestDefoe\SlowMail\Forget;
use ErnestDefoe\SlowMail\Gdpr\ExportActor;
use ErnestDefoe\SlowMail\Gdpr\SlowMailData;
use ErnestDefoe\SlowMail\Http\DeliverOnVisit;
use ErnestDefoe\SlowMail\Http\PageContent;
use ErnestDefoe\SlowMail\Integration;
use ErnestDefoe\SlowMail\Letter;
use ErnestDefoe\SlowMail\MemberMapPlaces;
use ErnestDefoe\SlowMail\Notification\LetterArrivedBlueprint;
use ErnestDefoe\SlowMail\Notification\LetterDeliveredBlueprint;
use ErnestDefoe\SlowMail\Notification\LetterReportedBlueprint;
use ErnestDefoe\SlowMail\Search;
use ErnestDefoe\SlowMail\SlowMailServiceProvider;
use Flarum\Api\Resource\ForumResource;
use Flarum\Extend;
use Flarum\Foundation\Paths;
use Flarum\Gdpr\Events\Exported;
use Flarum\Gdpr\Events\Exporting;
use Flarum\Gdpr\Extend\UserData;
use Flarum\Search\Database\DatabaseSearchDriver;
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

    // Letters, reports and pen-pal listings are JSON:API resources. Only Letter has a visibility scope
    // that matters to anyone else, and nothing in Flarum relates to these types, so no include reaches them.
    new Extend\ApiResource(Api\LetterResource::class),
    new Extend\ApiResource(Api\ReportResource::class),
    new Extend\ApiResource(Api\SeekerResource::class),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(Api\ForumFields::class),

    (new Extend\ModelVisibility(Letter::class))
        ->scope(ScopeLetterVisibility::class),

    (new Extend\SearchDriver(DatabaseSearchDriver::class))
        ->addSearcher(Letter::class, Search\LetterSearcher::class)
        ->addFilter(Search\LetterSearcher::class, Search\Filter\BoxFilter::class)
        ->addFilter(Search\LetterSearcher::class, Search\Filter\WithFilter::class),

    // The few answers that aren't records (each handler says why).
    (new Extend\Routes('api'))
        ->get('/slow-mail', 'slow-mail.summary', Api\SummaryController::class)
        ->get('/slow-mail/quote', 'slow-mail.quote', Api\QuoteController::class)
        ->get('/slow-mail/matches', 'slow-mail.matches', Api\MatchesController::class)
        ->post('/slow-mail/blocks', 'slow-mail.block', Api\BlockController::class),

    (new Extend\ErrorHandling())
        ->status('slow_mail_not_accepting', 422)
        ->status('slow_mail_bad_letter', 422)
        ->status('slow_mail_too_many_in_transit', 422)
        ->status('slow_mail_wait_for_delivery', 422)
        ->status('slow_mail_in_transit', 422)
        ->status('slow_mail_already_reported', 422)
        ->status('slow_mail_already_listed', 422)
        ->status('slow_mail_daily_limit', 429)
        ->status('slow_mail_new_pals_limit', 429)
        ->status('slow_mail_slow_down', 429)
        ->status('slow_mail_key_missing', 503),

    (new Extend\Filesystem())
        ->disk('ernestdefoe-slow-mail', fn (Paths $paths): array => [
            'root' => $paths->storage.'/slow-mail',
            'visibility' => 'private',
        ]),

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

    (new Extend\Settings())
        ->default('slow-mail.nav', true)
        ->default('slow-mail.km_per_day', 2000)
        ->default('slow-mail.min_hours', 12)
        ->default('slow-mail.max_hours', 168)
        ->default('slow-mail.default_hours', 24)
        ->default('slow-mail.daily_limit', 10)
        ->default('slow-mail.new_pals_daily', 3)
        ->serializeToForum('slowMailNav', 'slow-mail.nav', 'boolval'),

    (new Extend\ServiceProvider())
        ->register(SlowMailServiceProvider::class),

    (new Extend\Event())
        ->listen(Deleted::class, [Forget::class, 'handle']),

    // Other extensions, each wired in only while it is enabled. Nothing else checks what's installed.
    (new Extend\Conditional())
        ->whenExtensionEnabled('ernestdefoe-member-map', fn () => [
            (new Integrate())->places(MemberMapPlaces::class),
        ])
        ->whenExtensionEnabled('ernestdefoe-parley', fn () => [
            (new Integrate())->with(Integration\Parley::class),
        ])
        ->whenExtensionEnabled('fof-byobu', fn () => [
            (new Integrate())->with(Integration\Byobu::class),
        ])
        ->whenExtensionEnabled('flarum-messages', fn () => [
            (new Integrate())->with(Integration\Messages::class),
        ])
        ->whenExtensionEnabled('flarum-suspend', fn () => [
            (new Integrate())->with(Integration\Suspend::class),
        ])
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new UserData())->addType(SlowMailData::class),
            (new Extend\Event())
                ->listen(Exporting::class, [ExportActor::class, 'exporting'])
                ->listen(Exported::class, [ExportActor::class, 'exported']),
        ]),
];
