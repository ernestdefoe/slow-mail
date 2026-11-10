<?php

namespace ErnestDefoe\SlowMail;

use Carbon\Carbon;
use Flarum\Extension\ExtensionManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Who may write to whom, and how much.
 *
 * Every reason a member can't be written to answers with the same code
 * (`slow_mail_not_accepting`), so a refusal never says WHICH it was: a
 * block, letters turned off, private messages turned off, or a recipient
 * without Slow Mail. Blocks count both ways, ours and Parley's; fof/byobu's
 * "block private discussions", Parley's "who can message me" and
 * flarum/messages' per-member rule (`message` ability, e.g. anonymised
 * accounts) are respected when those are enabled.
 */
class Rules
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ExtensionManager $extensions,
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    /** null when $sender may write to $recipient now, else an error code. */
    public function refusal(User $sender, ?User $recipient): ?string
    {
        if (! $recipient || $recipient->id === $sender->id) {
            return 'slow_mail_not_accepting';
        }

        if (! $this->accepts($sender, $recipient)) {
            return 'slow_mail_not_accepting';
        }

        return null;
    }

    /** Whether $recipient takes letters from $sender (everything but the sender's own limits). */
    public function accepts(User $sender, User $recipient): bool
    {
        if (! $recipient->is_email_confirmed || ! $recipient->hasPermission(Settings::SEND)) {
            return false;
        }

        if (! User::query()->whereVisibleTo($sender)->whereKey($recipient->id)->exists()) {
            return false;
        }

        $accept = (string) $recipient->getPreference(Settings::ACCEPT, Settings::ACCEPT_EVERYONE);
        if ($accept === Settings::ACCEPT_NOBODY || ($accept === Settings::ACCEPT_PALS && ! $this->wroteTo($recipient->id, $sender->id))) {
            return false;
        }

        if ($this->blocked($sender->id, $recipient->id)) {
            return false;
        }

        if ($this->extensions->isEnabled('fof-byobu') && $recipient->getAttribute('blocks_byobu_pd')) {
            return false;
        }

        if ($this->extensions->isEnabled('flarum-messages') && ! $sender->can('message', $recipient)) {
            return false;
        }

        // Parley's "who can message me": nobody, or only people they follow.
        if ($this->extensions->isEnabled('ernestdefoe-parley')) {
            $parley = (string) $recipient->getPreference('parleyWhoCanMessage', 'everyone');
            if ($parley === 'nobody' || ($parley === 'following' && ! $this->db->table('parley_follows')->where('user_id', $recipient->id)->where('followed_id', $sender->id)->exists())) {
                return false;
            }
        }

        return true;
    }

    /** A block either way: ours, or Parley's when it's enabled. */
    public function blocked(int $a, int $b): bool
    {
        $either = fn ($q) => $q->where(fn ($q) => $q->where('user_id', $a)->where('blocked_id', $b))
            ->orWhere(fn ($q) => $q->where('user_id', $b)->where('blocked_id', $a));

        if ($this->db->table('slow_mail_blocks')->where($either)->exists()) {
            return true;
        }

        return $this->extensions->isEnabled('ernestdefoe-parley') && $this->db->getSchemaBuilder()->hasTable('parley_blocks')
            && $this->db->table('parley_blocks')->where($either)->exists();
    }

    /** $from has written to $to, and the letter has arrived (one still on its way must not be given away). */
    public function wroteTo(int $from, int $to): bool
    {
        return Letter::query()->where('sender_id', $from)->where('recipient_id', $to)->whereNotNull('delivered_at')->exists();
    }

    /** The sender's own limits: null, or an error code with its HTTP status. */
    public function limit(User $sender, User $recipient): ?array
    {
        $now = Carbon::now();
        $sent = Letter::query()->where('sender_id', $sender->id);

        if ($sent->clone()->whereNull('delivered_at')->whereNull('returned_at')->count() >= Settings::IN_TRANSIT) {
            return [422, 'slow_mail_too_many_in_transit'];
        }

        if ($sent->clone()->where('recipient_id', $recipient->id)->whereNull('delivered_at')->whereNull('returned_at')->count() >= Settings::IN_TRANSIT_TO_ONE) {
            return [422, 'slow_mail_wait_for_delivery'];
        }

        $today = $sent->clone()->where('sent_at', '>', $now->copy()->subDay());
        if ($today->clone()->count() >= $this->daily()) {
            return [429, 'slow_mail_daily_limit'];
        }

        // Letters to someone new (never written to, never had a letter from) are the spam vector: fewer of those.
        if (! $this->pals($sender->id, $recipient->id) && ! $sent->clone()->where('recipient_id', $recipient->id)->exists()) {
            $cold = $today->clone()->whereNotExists(fn ($q) => $q->selectRaw('1')->from('slow_mail_letters as earlier')
                ->whereColumn('earlier.recipient_id', 'slow_mail_letters.sender_id')
                ->whereColumn('earlier.sender_id', 'slow_mail_letters.recipient_id')
                ->whereNotNull('earlier.delivered_at'))
                ->distinct()->count('recipient_id');
            if ($cold >= Settings::int($this->settings->get(Settings::NEW_PALS), Settings::DEFAULT_NEW_PALS, 1, 100)) {
                return [429, 'slow_mail_new_pals_limit'];
            }
        }

        return null;
    }

    public function daily(): int
    {
        return Settings::int($this->settings->get(Settings::DAILY), Settings::DEFAULT_DAILY, 1, 500);
    }

    /** They have a delivered letter between them, either way. */
    public function pals(int $a, int $b): bool
    {
        return $this->wroteTo($a, $b) || $this->wroteTo($b, $a);
    }
}
