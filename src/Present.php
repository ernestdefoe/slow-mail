<?php

namespace ErnestDefoe\SlowMail;

use Flarum\Http\SlugManager;
use Flarum\User\User;

/**
 * Letters as the browser gets them. Callers check Access first; this is the
 * only place a letter's words are decrypted for output (letter()).
 */
class Present
{
    public function __construct(private readonly Vault $vault, private readonly SlugManager $slugs)
    {
    }

    /** @return array<string, mixed> */
    public function envelope(Letter $letter, User $actor): array
    {
        $sent = $actor->id === $letter->sender_id;
        $other = $sent ? $letter->recipient : $letter->sender;

        $data = [
            'id' => $letter->id,
            'box' => $sent ? 'out' : 'in',
            'with' => $this->user($other),
            'stamp' => $letter->stamp,
            'postmark' => $letter->postmark,
            'origin' => $letter->origin_country,
            'sentAt' => $letter->sent_at->toIso8601String(),
            'words' => $letter->words,
            'replyTo' => $letter->reply_to_id,
            'deliveredAt' => $letter->delivered_at?->toIso8601String(),
            'travelHours' => $letter->travel_hours,
        ];

        if ($sent) {
            $data += [
                'status' => $letter->delivered_at ? 'delivered' : ($letter->returned_at ? 'returned' : 'transit'),
                'arrivesAt' => $letter->arrives_at->toIso8601String(),
                'destination' => $letter->destination,
            ];
        } else {
            $data['read'] = $letter->read_at !== null;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function letter(Letter $letter, User $actor): array
    {
        $body = $this->vault->open($letter->body, $letter->context());

        return $this->envelope($letter, $actor) + ['body' => $body, 'unreadable' => $body === null];
    }

    /** @return array<string, mixed>|null */
    public function user(?User $user): ?array
    {
        return $user ? [
            'id' => $user->id,
            'slug' => $this->slugs->forResource(User::class)->toSlug($user),
            'displayName' => $user->display_name,
            'avatarUrl' => $user->avatar_url,
        ] : null;
    }
}
