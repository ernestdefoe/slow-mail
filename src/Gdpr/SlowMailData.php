<?php

namespace ErnestDefoe\SlowMail\Gdpr;

use ErnestDefoe\SlowMail\Block;
use ErnestDefoe\SlowMail\Forget;
use ErnestDefoe\SlowMail\Letter;
use ErnestDefoe\SlowMail\Report;
use ErnestDefoe\SlowMail\Seeker;
use ErnestDefoe\SlowMail\Vault;
use Flarum\Gdpr\Data\Type;

/**
 * A member's letters in a data export: the ones they wrote, and the ones that
 * have arrived for them (never one still on its way: it hasn't reached them).
 * The words, who they wrote to and where from only when the member exports
 * their own data (ExportActor): a moderator's export of someone else says
 * how many letters, when, and how long, nothing more. Plus their pen-pal
 * listing, blocks and the reports they filed.
 *
 * Erasure (and anonymising) deletes every letter they wrote or received,
 * their listing and their blocks: a letter is between two people, and one of
 * them asked to be forgotten. Registered only when flarum/gdpr is enabled.
 */
class SlowMailData extends Type
{
    public static function exportDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-slow-mail.lib.gdpr.export');
    }

    public static function anonymizeDescription(): string
    {
        return self::deleteDescription();
    }

    public static function deleteDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-slow-mail.lib.gdpr.delete');
    }

    public function export(): ?array
    {
        $self = ExportActor::isSelf((int) $this->user->id);
        $export = $this->letters($self);

        return $self ? array_merge($export, $this->ownRecords()) : $export;
    }

    /**
     * Their own export: what they sent (on its way or not) and what reached them, words included. Anyone
     * else's export of them: only letters that have arrived, and only when and how long, never who with.
     *
     * @return list<array<string, string>>
     */
    private function letters(bool $self): array
    {
        $id = (int) $this->user->id;
        // flarum/gdpr builds export types with `new` and fixed arguments, so the Vault can't be injected here.
        $vault = $self ? resolve(Vault::class) : null;
        $export = [];

        $letters = Letter::query()
            ->where(fn ($q) => $q->where('sender_id', $id)->when(! $self, fn ($q) => $q->whereNotNull('delivered_at')))
            ->orWhere(fn ($q) => $q->where('recipient_id', $id)->whereNotNull('delivered_at'));

        foreach ($letters->lazyById(200) as $letter) {
            $row = [
                'direction' => $letter->sender_id === $id ? 'sent' : 'received',
                'sent_at' => $letter->sent_at->toIso8601String(),
                'delivered_at' => $letter->delivered_at?->toIso8601String(),
                'words' => $letter->words,
            ];
            if ($vault) {
                $row += [
                    'with_user_id' => $letter->sender_id === $id ? $letter->recipient_id : $letter->sender_id,
                    'stamp' => $letter->stamp,
                    'postmark' => $letter->postmark,
                    'letter' => $vault->open($letter->body, $letter->context()),
                ];
            }
            $export[] = ["slow-mail/letter-{$letter->id}.json" => $this->encodeForExport($row)];
        }

        return $export;
    }

    /**
     * Their pen-pal listing, the reports they filed and who they blocked: their own export only.
     *
     * @return list<array<string, string>>
     */
    private function ownRecords(): array
    {
        $id = (int) $this->user->id;
        $export = [];

        if ($seeker = Seeker::query()->find($id)) {
            $export[] = ['slow-mail/pen-pal-listing.json' => $this->encodeForExport(['interests' => $seeker->interestList(), 'region' => $seeker->region])];
        }

        $reports = Report::query()->where('reporter_id', $id)->get(['reason', 'created_at', 'letter_sent_at']);
        if ($reports->isNotEmpty()) {
            $export[] = ['slow-mail/reports.json' => $this->encodeForExport($reports->map(fn (Report $r) => [
                'reason' => $r->reason, 'reported_at' => $r->created_at->toIso8601String(), 'letter_sent_at' => $r->letter_sent_at->toIso8601String(),
            ])->all())];
        }

        $blocks = Block::query()->where('user_id', $id)->pluck('blocked_id')->all();
        if ($blocks) {
            $export[] = ['slow-mail/blocked.json' => $this->encodeForExport(['user_ids' => $blocks])];
        }

        return $export;
    }

    public function anonymize(): void
    {
        $this->delete();
    }

    public function delete(): void
    {
        Forget::user((int) $this->user->id);
    }
}
