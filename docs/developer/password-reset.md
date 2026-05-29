# Password reset — internals

This document covers the forgot/reset flow, the in-session change-password path that shares its validation rules, and the trade-offs behind them.

End users should read [../user/password-reset.md](../user/password-reset.md) instead.

## Endpoints

All under the public firewall — no session required to start the flow.

| Route | Method | Purpose |
| - | - | - |
| `/auth/forgot-password` | POST | Accepts an email, mints a single-use token, sends the reset email. Always returns 200 — see [email enumeration](#email-enumeration). |
| `/auth/reset-password` | POST | Accepts `{token, newPassword}`, validates token + password, sets the new hash, marks the token used. |
| `/auth/change-password` | POST | In-session password rotation. Requires step-up via `SensitiveActionVerifier`. Same password-rule floor as reset. |

All three live in [`App\Controller\PasswordController`](../../api/src/Controller/PasswordController.php).

## Token model

[`App\Entity\PasswordResetToken`](../../api/src/Entity/PasswordResetToken.php) is the storage shape:

| Field | Type | Notes |
| - | - | - |
| `id` | uuid | Generated client-side via `doctrine.uuid_generator`. |
| `user` | FK → `User` | `ON DELETE CASCADE` so deleting a user reaps tokens. |
| `tokenHash` | string(64), unique, indexed | sha256 hex of the plaintext token. Plaintext is never stored. |
| `expiresAt` | datetime_immutable | Set to `+1 hour` at creation. |
| `usedAt` | datetime_immutable, nullable | NULL until the token is redeemed; set on first successful reset. |
| `createdAt` | datetime_immutable | Telemetry. |

Generation:

```php
$plainToken = bin2hex(random_bytes(32));       // 64 hex chars, 256 bits of entropy
$tokenHash  = hash('sha256', $plainToken);
$expiresAt  = new \DateTimeImmutable('+1 hour');
```

The plaintext token only ever lives in:

1. Process memory in `forgotPassword()` long enough to compose the email.
2. The email body itself (`/reset-password?token=<plaintext>`).
3. The browser address bar after the user clicks the link.

A database leak that doesn't include the active mailbox is therefore not enough to forge a valid reset — the attacker would have to guess a 256-bit random or break sha256. A leak that *does* include access to outgoing mail is enough for any active flow within the one-hour window; this is the same trust assumption every email-based reset has.

`PasswordResetToken::isValid()` returns `null === $this->usedAt && $this->expiresAt > $now`. Three rejection cases are surfaced as distinct `code` fields so the PWA can render the right "this link can't be used" card — see [error contract](#error-contract).

## Email enumeration

`/auth/forgot-password` always returns 200 with the message:

> If an account exists for that email, a reset link has been sent.

This response is byte-identical whether the email matched a `User` row or not. An attacker can't use the endpoint to confirm whether an address is registered.

Three implementation details keep the timing close to identical too:

- The mail send is `try`-free — it can throw — but the response object is constructed *before* the lookup branches, so a transport hiccup doesn't change the response body.
- We don't expose a per-account "you already requested one X seconds ago" hint.
- The token table's `invalidateAllForUser` only runs on a hit, but its cost is a single UPDATE indexed by `user_id`, well below the SMTP-dispatch cost — the timing gap doesn't reliably distinguish hits from misses.

> Caveat: there's no application-level rate limit on this endpoint today. Operators are expected to put a per-IP/per-email limit at the reverse proxy or CDN layer if abuse becomes a concern. See [open work](#open-work).

## Token invalidation on re-request

```php
$this->tokenRepository->invalidateAllForUser($user);
```

A fresh `/auth/forgot-password` UPDATEs every outstanding token for the user to `usedAt = now()`. Two reasons:

- **Most-recent-email is authoritative.** If a user keeps requesting links because the first one didn't arrive, only the latest one works — they can't be confused about which to click.
- **Mitigates leaked-link-then-request.** If an attacker is sitting on a leaked old token, the legitimate user requesting a new one shuts the attacker out (assuming the user clicks their new link, which marks it used).

## Reset endpoint validation

Order of rejections in `/auth/reset-password`:

1. **Token present** — 400 `Token is required.` if the body's `token` is missing/empty.
2. **Password length** — 422 if `< 8` chars. Mirrors the `Assert\Length(min: 8)` on `User::$plainPassword` so signup, reset, and change all gate at the same floor.
3. **Password strength** — 422 if below `app.password_min_strength` (env-driven: `VERY_WEAK` in dev/test, `MEDIUM` in staging/prod). Uses Symfony's `PasswordStrengthValidator::estimateStrength()` — same zxcvbn-style estimator the validator behind the User entity uses.
4. **Token match** — 400 `token_invalid` if no row matches the sha256 of the supplied plaintext.
5. **Token already used** — 400 `token_used` if `usedAt !== null`.
6. **Token expired** — 400 `token_expired` if `expiresAt <= now`.

Validation order is intentional: cheap input checks first, expensive DB lookup last, and the three token failure modes are tested in priority `invalid → used → expired`. Steps 4-6 always return 400 with a discriminating `code` (the PWA branches on `code`, the message is for fallbacks).

On success: hash the new password, mark the token used, single flush.

We deliberately do *not* clear other open reset tokens here — `invalidateAllForUser` runs at request time. A new token would only exist if the user requested one after this token was minted, which already invalidated it.

## Change-password (in-session)

`/auth/change-password` is separate because the trust model is different — the caller is already signed in, so we don't need a token, but we do need to step up:

```php
$err = $this->verifier->verify($user, $data);  // TOTP if 2FA on, password otherwise
```

See [docs/developer/two-factor-auth.md#step-up-sensitiveactionverifier](two-factor-auth.md#step-up-sensitiveactionverifier) for the verifier branching.

Same length + strength + "must differ from current" floor:

```php
if ($this->hasher->isPasswordValid($user, $newPassword)) {
    return $this->json(['error' => 'New password must be different from current password.'], 422);
}
```

The "must differ" check is server-side only — never trust a client-side guard for this since the hash is what we actually compare against.

## Error contract

`/auth/reset-password` rejections always include a `code` discriminator the PWA switches on:

```json
{ "error": "human message", "code": "token_invalid" | "token_used" | "token_expired" }
```

Validation rejections (length/strength/missing token) intentionally do *not* carry a `code` — the message is the contract. The PWA renders those as inline form errors instead of routing to a "this link can't be used" card.

This split is mirrored in [`pwa/contexts/AuthContext.tsx`](../../pwa/contexts/AuthContext.tsx):

```ts
export class PasswordResetTokenError extends Error {
  constructor(message: string, public readonly code: PasswordResetTokenCode) {...}
}

export type PasswordResetTokenCode = 'token_expired' | 'token_used' | 'token_invalid';
```

Only `code`-bearing failures throw `PasswordResetTokenError`. Validation errors throw plain `Error`s and the form alert handles them.

## Email

[`PasswordController::sendResetEmail`](../../api/src/Controller/PasswordController.php) composes a plain HTML + text email with a single CTA:

```
https://<APP_FRONTEND_URL>/reset-password?token=<plainToken>
```

- `APP_FRONTEND_URL` is autowired from env.
- `MAILER_FROM` falls back to `no-reply@aura.test` if unset (dev convenience; staging/prod should set it).
- The email is plain text + HTML, no attachments, no tracking pixels.

Local dev mail lands in Mailpit at the worktree's `mailpit UI` port (see [deployment.md](deployment.md)).

We send through `MailerInterface` directly here rather than a dedicated mailer service because there's only the one message type — the [`TwoFactorRecoveryMailer`](../../api/src/Service/TwoFactorRecoveryMailer.php) and [`InviteMailer`](../../api/src/Service/InviteMailer.php) wrap multiple methods, so the abstraction earns its keep there.

## PWA components

| Component / page | File | Purpose |
| - | - | - |
| `forgot-password.tsx` | [pwa/pages/forgot-password.tsx](../../pwa/pages/forgot-password.tsx) | The request-a-link form. POSTs to `/auth/forgot-password`, shows the same success message on either branch. |
| `reset-password.tsx` | [pwa/pages/reset-password.tsx](../../pwa/pages/reset-password.tsx) | The set-new-password page. Reads `?token=` from the URL, POSTs to `/auth/reset-password`, branches on `PasswordResetTokenError.code` to render one of four "this link can't be used" cards (`token_expired` / `token_used` / `token_invalid` / `no_token`). |
| `ChangePasswordForm.tsx` | [pwa/components/account/ChangePasswordForm.tsx](../../pwa/components/account/ChangePasswordForm.tsx) | In-app rotation. Swaps the password field for a TOTP field when 2FA is on. |
| `PasswordStrengthMeter.tsx` | [pwa/components/auth/PasswordStrengthMeter.tsx](../../pwa/components/auth/PasswordStrengthMeter.tsx) | Visual feedback. Estimator config in `pwa/lib/passwordStrength.ts` — `MIN_PASSWORD_STRENGTH` is sourced from `NEXT_PUBLIC_PASSWORD_MIN_STRENGTH` so the client floor stays in sync with `app.password_min_strength` on the API. |

The PWA's `no_token` synthesized state has no API counterpart — it's invoked when `?token=` is missing from the URL, which we want to handle without making the user click "submit" to find out the link is broken.

## Threat model

| Concern | Decision |
| - | - |
| Email enumeration via forgot endpoint | Always returns 200 with the same body; no per-account hints. |
| Token guess | 256-bit random, sha256-hashed at rest. Brute-force infeasible. |
| Token replay | `usedAt` set on first successful redeem; `isValid()` rejects used + expired. |
| Stale leaked link | Re-requesting a fresh link invalidates every outstanding token for that user. |
| Database leak | Plaintext token is never stored. An attacker who reads the table can't redeem; they'd need the active mailbox or to break sha256. |
| Compromised mailbox | Same as every email-based reset. We send a notification email on change-via-reset? **Not yet** — open work below. |
| 2FA bypass via password reset | The reset only changes the password. 2FA is unchanged — the user still has to clear the second factor at sign-in. A separate lost-device flow handles 2FA recovery. |
| Brute-force the reset endpoint | The token is unguessable, so attempting to reset random tokens has effectively zero success rate. The forgot endpoint is a different problem (volume), addressed at the proxy layer today. |
| Self-DoS via mass forgot requests | No per-account rate limit. An attacker who knows a valid email could mass-request links, invalidating each other in turn. The user can always request one more — the *latest* link always wins — but their inbox gets noisy. |

## Open work

- **Notification email on successful change-via-reset.** Today the only confirmation is the "Password updated successfully" page banner. A "your password was reset" alert email would be a useful out-of-band signal for a compromised account, mirroring the 2FA recovery notifications.
- **Per-IP / per-email rate limit on `/auth/forgot-password`.** Sized so a legitimate "I didn't get the email, let me try again" doesn't trip but a script abusing the endpoint does. The `two_factor_verify` limiter is the obvious model to clone — see [api/config/packages/framework.yaml](../../api/config/packages/framework.yaml).
- **Notification email on successful change-via-`/auth/change-password`.** Same shape as the reset-via-link notification.

## Testing

[`api/tests/Api/PasswordTest.php`](../../api/tests/Api/PasswordTest.php) covers:

- Change-password happy path, wrong current password, too-short / same-as-current, unauthenticated, 2FA-on requires TOTP.
- Forgot: valid email mints a token + sends email; unknown email still returns 200 and sends no mail (enumeration check); re-request invalidates prior tokens.
- Reset: happy path, expired token, used token, invalid token, short password.

`assertEmailCount` reads from the per-request `MessageLoggerListener` — see the same note in [two-factor-auth.md#testing](two-factor-auth.md#testing): assert immediately after the request that should send. The forgot endpoint requires Mailpit to be running because the SMTP transport actually attempts delivery before failing soft; if you're running tests against a stack without Mailpit, the forgot-password test will surface a 500 from the unreachable MX. `docker compose up -d` brings it up alongside everything else.

## Adding a new password-rotating action

If you need a new endpoint that sets a password (an admin-side "reset this user's password," a magic-link first-login flow, etc.):

1. **Reuse `PasswordController::MIN_PASSWORD_LENGTH` + `$minPasswordStrength`** — don't redefine the floor.
2. **Decide on the trust model.** Token-on-the-wire (forgot/reset) or in-session step-up (`SensitiveActionVerifier`)? Don't mix — a session is enough for one or the other, never both.
3. **If you mint a new token type**, follow the `PasswordResetToken` shape: hash at rest, short TTL, single-use via `usedAt`, invalidate-prior on re-issue.
4. **Always hash through `UserPasswordHasherInterface::hashPassword()`** — never `password_hash()` directly. The hasher honors the configured algorithm + cost; bypassing it is how rehash-on-login gets broken.
5. **Test** for: missing input, wrong-credential rejection, weak-password rejection, same-as-current rejection (if rotating an existing password), success.
