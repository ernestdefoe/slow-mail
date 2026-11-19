<?php

namespace ErnestDefoe\SlowMail;

use Flarum\Http\SlugManager;
use Flarum\User\User;

/** A member as the plain endpoints (summary, matches) send them: name, profile slug and picture only. */
class Present
{
    public function __construct(private readonly SlugManager $slugs)
    {
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
