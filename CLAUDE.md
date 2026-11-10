# Slow Mail: working rules

- **Commit straight to `main` and push. Never open a pull request on this repo.** Commit subjects are conventional (`feat:`, `fix:`); the release drafter bumps from the subject.
- `js/dist` is committed and is what forums load. After any change under `js/src`, run `npm run build` in `js/` (from a fresh `npm ci`) and commit the bundle; CI fails if it is stale.
- Publishing a release is Ernest's click. Nothing is announced until he says so.

## Design rules that exist for a reason

- **A letter on its way doesn't exist for its recipient.** Not in any answer, include, search or notification until Mailroom delivers it. `Access::canSee()` / `Access::scope()` are the one gate, `Present::letter()` the one place words are decrypted for output. No JSON:API resource for letters, so no include can pull one in. Notification subjects are Users (a subject without an API resource 500s every notification list); the letter id rides in the data. Add any new endpoint to `PrivacyTest::assertNowhere()`.
- **Nobody else reads letters, admins included.** Encrypted at rest (`Vault`, XChaCha20-Poly1305, sender:recipient as associated data); the key lives in `storage/slow-mail/key` or config.php `slow-mail.key`, never in the database, and is only created while there are no letters (lose it and letters are unreadable; sending stops with 503).
- **Travel times never place anyone more precisely than Member Map does.** `Journey` uses a distance only when EACH member can see the other on the map (`Places::seenBy`, Member Map's own `MemberQuery`); otherwise the admin's set time. The distance is banded by the coarser precision (city 500 km, region 1,000, country 2,000; never band 0), then jittered by up to a whole band from an HMAC over the key, the pair and their places (stable, so repeats can't average it away), then arrival rounds up to the hour. `JourneyTest` solves exactly which distances fit an observed time: keep it passing when touching the formula. Postmarks name a region or a country, never a city.
- **Refusals all look the same** (`slow_mail_not_accepting`): blocks (ours and Parley's, both ways), "who can send me letters", fof/byobu's blocked private discussions, Parley's who-can-message, flarum/messages' `message` ability, unconfirmed or without the permission. Pen-pals-only counts only DELIVERED letters (one in transit must not be given away).
- **Delivery is idempotent**: a conditional UPDATE claims each letter; only the winner notifies; `notified_at` retries a failed notify. Scheduler every minute + `DeliverOnVisit` middleware, which costs one cache read (`Mailroom::NEXT`) between arrivals. A block since sending returns the letter.
- State changes are POST/DELETE only (reading a letter is `POST …/read`: a GET could be fired by an `<img>`).
- A moderator's GDPR export of a member has counts and dates only; who they wrote to, postmarks and words only in their own export (`Gdpr\ExportActor`).
- Letters render as text nodes (`white-space: pre-line`), never HTML. Stamps and postmarks are SVG drawn in `js/src/forum/art.tsx`, called as functions (Mithril treats a function used as a JSX tag as a closure component).
- extend.php uses string literals only (no class constants at load time).

## Testing

- `composer test:setup && composer test` (integration; prefixed SQLite and MySQL both pass), `composer analyse:phpstan`.
- `cd js && npm ci && npm run build && npm run format-check`.
