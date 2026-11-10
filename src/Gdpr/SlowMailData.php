<?php

namespace ErnestDefoe\SlowMail\Gdpr;

use ErnestDefoe\SlowMail\Forget;
use ErnestDefoe\SlowMail\Letter;
use ErnestDefoe\SlowMail\Seeker;
use ErnestDefoe\SlowMail\Vault;
use Flarum\Gdpr\Data\Type;
use Illuminate\Database\ConnectionInterface;

/**
 * A member's letters in a data export: the ones they wrote, and the ones that
 * have arrived for them (never one still on its way: it hasn't reached them).
 * The words, who they wrote to and where from only when the member exports
 * their own data (ExportActor): a moderator's export of someone else says
 * how many letters, when, and how long, nothing more. Plus their pen-pal
 * listing and blocks.
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
        $vault = resolve(Vault::class);
        $id = (int) $this->user->id;
        $self = ExportActor::isSelf($id);
        $export = [];

        $letters = Letter::query()->where('sender_id', $id)
            ->orWhere(fn ($q) => $q->where('recipient_id', $id)->whereNotNull('delivered_at'))
            ->orderBy('id')->get();

        foreach ($letters as $letter) {
            $row = [
                'direction' => $letter->sender_id === $id ? 'sent' : 'received',
                'sent_at' => $letter->sent_at->toIso8601String(),
                'delivered_at' => $letter->delivered_at?->toIso8601String(),
                'words' => $letter->words,
            ];
            // Who they wrote to, where from and the words: only in their own export.
            if ($self) {
                $row += [
                    'with_user_id' => $letter->sender_id === $id ? $letter->recipient_id : $letter->sender_id,
                    'stamp' => $letter->stamp,
                    'postmark' => $letter->postmark,
                    'letter' => $vault->open($letter->body, $letter->context()),
                ];
            }
            $export[] = ["slow-mail/letter-{$letter->id}.json" => $this->encodeForExport($row)];
        }

        if (! $self) {
            return $export;
        }

        if ($seeker = Seeker::query()->find($id)) {
            $export[] = ['slow-mail/pen-pal-listing.json' => $this->encodeForExport(['interests' => $seeker->interestList(), 'region' => $seeker->region])];
        }

        $blocks = resolve(ConnectionInterface::class)->table('slow_mail_blocks')->where('user_id', $id)->pluck('blocked_id')->all();
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
