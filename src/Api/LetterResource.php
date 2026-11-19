<?php

namespace ErnestDefoe\SlowMail\Api;

use Carbon\Carbon;
use ErnestDefoe\SlowMail\Letter;
use ErnestDefoe\SlowMail\LetterWriter;
use ErnestDefoe\SlowMail\Refused;
use ErnestDefoe\SlowMail\Settings;
use ErnestDefoe\SlowMail\Stamps;
use ErnestDefoe\SlowMail\Vault;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Tobyz\JsonApiServer\Context as BaseContext;
use Tobyz\JsonApiServer\Schema\Field\Field;

/**
 * The `slow-mail-letters` type: a member's letters, and nobody else's.
 *
 * GET    /api/slow-mail-letters?filter[box]=in|out&filter[with]=<user id>
 * GET    /api/slow-mail-letters/{id}      words included (the one place they are decrypted for output)
 * POST   /api/slow-mail-letters           {body, stamp, replyTo?, relationships.recipient}: send
 * PATCH  /api/slow-mail-letters/{id}      {read: true}: its recipient opened it
 * DELETE /api/slow-mail-letters/{id}      put it away, for you only (the row goes when both have)
 *
 * Every query goes through ScopeLetterVisibility: what you sent, and what has
 * ARRIVED for you. A letter on its way is in no answer to its recipient, and
 * no other member or admin sees any letter. Nothing else in Flarum relates to
 * this type, so no include can reach it either.
 *
 * @extends AbstractDatabaseResource<Letter>
 */
class LetterResource extends AbstractDatabaseResource
{
    public function __construct(private readonly LetterWriter $writer, private readonly Vault $vault)
    {
    }

    public function type(): string
    {
        return 'slow-mail-letters';
    }

    public function model(): string
    {
        return Letter::class;
    }

    /** @param Builder<Letter> $query */
    public function scope(Builder $query, BaseContext $context): void
    {
        $query->whereVisibleTo($context->getActor());
    }

    public function endpoints(): array
    {
        return [
            // Both members come with each letter: LetterSearcher loads them in one query each.
            Endpoint\Index::make()
                ->authenticated()
                ->defaultInclude(['sender', 'recipient'])
                ->defaultSort('-sentAt')
                ->paginate(50, 100),
            Endpoint\Show::make()
                ->authenticated()
                ->defaultInclude(['sender', 'recipient']),
            Endpoint\Create::make()
                ->authenticated()
                ->can(Settings::SEND)
                ->defaultInclude(['sender', 'recipient']),
            Endpoint\Update::make()
                ->authenticated(),
            Endpoint\Delete::make()
                ->authenticated(),
        ];
    }

    public function fields(): array
    {
        return [...$this->letterFields(), ...$this->journeyFields(), ...$this->memberFields()];
    }

    /** @return list<Field> what the envelope says, and the words (a single letter only) */
    private function letterFields(): array
    {
        return [
            Schema\Str::make('box')
                ->get(fn (Letter $letter, Context $context) => $this->mine($letter, $context) ? 'out' : 'in'),
            // Write-only on create; read back only from a single letter, by its two members (the scope).
            // null there means it can't be decrypted on this forum any more (its key is missing).
            Schema\Str::make('body')
                ->writableOnCreate()
                ->requiredOnCreate()
                ->maxLength(Settings::MAX_LENGTH)
                ->set(fn (Letter $letter, mixed $value) => $letter->plainText = LetterWriter::clean($value))
                ->visible(fn (Letter $letter, Context $context) => $context->endpoint instanceof Endpoint\Show)
                ->get(fn (Letter $letter) => $this->vault->open($letter->body, $letter->context())),
            Schema\Str::make('stamp')
                ->writableOnCreate()
                ->requiredOnCreate()
                ->in(Stamps::all()),
            Schema\Integer::make('replyTo')
                ->property('reply_to_id')
                ->nullable()
                ->writableOnCreate(),
            Schema\Str::make('postmark')->nullable(),
            Schema\Str::make('origin')->property('origin_country')->nullable(),
            Schema\Integer::make('words'),
            Schema\DateTime::make('sentAt'),
        ];
    }

    /** @return list<Field> the sender's view of the journey (never sent to the recipient) and the arrival */
    private function journeyFields(): array
    {
        $mine = fn (Letter $letter, Context $context) => $this->mine($letter, $context);

        return [
            Schema\Integer::make('travelHours'),
            Schema\DateTime::make('deliveredAt')->nullable(),
            Schema\Str::make('status')
                ->visible($mine)
                ->get(fn (Letter $letter) => $letter->delivered_at ? 'delivered' : ($letter->returned_at ? 'returned' : 'transit')),
            Schema\DateTime::make('arrivesAt')->visible($mine),
            Schema\Str::make('destination')->nullable()->visible($mine),
        ];
    }

    /** @return list<Field> whether the recipient opened it (the sender is never told), and the two members */
    private function memberFields(): array
    {
        $theirs = fn (Letter $letter, Context $context) => ! $this->mine($letter, $context);

        return [
            Schema\Boolean::make('read')
                ->visible($theirs)
                ->get(fn (Letter $letter) => $letter->read_at !== null)
                ->writable($theirs)
                ->set(function (Letter $letter, mixed $value) {
                    if ($value && ! $letter->read_at) {
                        $letter->read_at = Carbon::now();
                    }
                }),
            Schema\Relationship\ToOne::make('sender')
                ->type('users')
                ->includable(),
            Schema\Relationship\ToOne::make('recipient')
                ->type('users')
                ->includable()
                ->writableOnCreate()
                ->requiredOnCreate(),
        ];
    }

    /** @return list<SortColumn> */
    public function sorts(): array
    {
        return [
            SortColumn::make('sentAt'),
            SortColumn::make('deliveredAt'),
        ];
    }

    /** @param Context $context */
    public function creating(object $model, BaseContext $context): ?object
    {
        $model->setRelation('sender', $context->getActor());
        $model->pendingRecipient = $this->writer->check($model, $context->getActor(), (string) $model->plainText);

        return $model;
    }

    protected function saveModel(Model $model, BaseContext $context): void
    {
        if (! $model->exists && $model instanceof Letter && $model->pendingRecipient) {
            $this->writer->send($model, $context->getActor(), $model->pendingRecipient, (string) $model->plainText, fn () => parent::saveModel($model, $context));

            return;
        }

        parent::saveModel($model, $context);
    }

    /** Putting a letter away: hidden for you; deleted once its other member has put it away too. */
    public function delete(object $model, BaseContext $context): void
    {
        if ($model->inTransit()) {
            throw new Refused(Refused::IN_TRANSIT);
        }

        $mine = $this->mine($model, $context);
        $other = $mine ? $model->recipient_hidden || $model->returned_at : $model->sender_hidden;
        if ($other) {
            $model->delete();
        } else {
            $model->forceFill([$mine ? 'sender_hidden' : 'recipient_hidden' => true])->save();
        }
    }

    private function mine(Letter $letter, BaseContext $context): bool
    {
        return $letter->sender_id === $context->getActor()->id;
    }
}
