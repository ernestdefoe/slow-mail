<?php

namespace ErnestDefoe\SlowMail\Api;

use ErnestDefoe\SlowMail\Refused;
use ErnestDefoe\SlowMail\Seeker;
use ErnestDefoe\SlowMail\Settings;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as BaseContext;

/**
 * The `slow-mail-seekers` type: a member's own "looking for a pen pal"
 * listing, its id their user id. Only ever their own: other listings reach a
 * member only as suggestions (MatchesController), never as a list to browse.
 *
 * GET/PATCH/DELETE /api/slow-mail-seekers/{userId}, POST /api/slow-mail-seekers {interests, region}
 *
 * @extends AbstractDatabaseResource<Seeker>
 */
class SeekerResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'slow-mail-seekers';
    }

    public function model(): string
    {
        return Seeker::class;
    }

    /** @param Builder<Seeker> $query */
    public function scope(Builder $query, BaseContext $context): void
    {
        $actor = $context->getActor();
        $query->where('slow_mail_seekers.user_id', $actor->isGuest() ? 0 : $actor->id);
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Show::make()->authenticated(),
            Endpoint\Create::make()->authenticated()->can(Settings::SEND),
            Endpoint\Update::make()->authenticated()->can(Settings::SEND),
            Endpoint\Delete::make()->authenticated(),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Arr::make('interests')
                ->writable()
                ->get(fn (Seeker $seeker) => $seeker->interestList())
                ->set(fn (Seeker $seeker, mixed $value) => $seeker->interests = implode(', ', Seeker::parseInterests($value))),
            Schema\Str::make('region')
                ->writable()
                ->in(Seeker::REGIONS),
        ];
    }

    /** @param Context $context */
    public function creating(object $model, BaseContext $context): ?object
    {
        $actor = $context->getActor();
        if (Seeker::query()->whereKey($actor->id)->exists()) {
            throw new Refused(Refused::ALREADY_LISTED);
        }
        $model->user_id = $actor->id;
        $model->interests ??= '';
        $model->region ??= 'anywhere';

        return $model;
    }
}
