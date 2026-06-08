# Two-factor authentication — internals

This document describes how 2FA is wired up in the codebase: which packages, where state lives, how the firewall handles the second step, how the lost-device recovery flow works, and the trade-offs behind the design.

End users should read [../user/two-factor-auth.md](../user/two-factor-auth.md) instead.

## Packages

- [`scheb/2fa-bundle`](https://symfony.com/bundles/SchebTwoFactorBundle/current/index.html) — base bundle (firewall listener, token wrapping, event dispatcher).
- `scheb/2fa-totp` — TOTP provider (delegates to [`spomky-labs/otphp`](https://github.com/Spomky-Labs/otphp)).
- `scheb/2fa-backup-code` — backup-code provider; calls into our entity's `isBackupCode()` / `invalidateBackupCode()` methods.

Config: [api/config/packages/scheb_2fa.yaml](../../api/config/packages/scheb_2fa.yaml). TOTP `leeway` is 15s (covers the "started typing at the rollover" race without exceeding the 30-second TOTP period).

## Entity model

`App\Entity\User` implements `Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface` and `Scheb\TwoFactorBundle\Model\BackupCodeInterface`. Storage fields:

- `totpEnabled: bool` — flips true only after `/me/2fa/verify` confirms the first code. False during setup, false after `disable()`.
- `totpSecretEncrypted: ?string` — sodium-secretbox-encrypted TOTP secret. Key is derived from `APP_SECRET` by [`App\Service\TwoFactorSecretCipher`](../../api/src/Service/TwoFactorSecretCipher.php).
- `totpSecretCache: ?string` — transient (not persisted) plaintext, populated on Doctrine `postLoad` by [`App\EventListener\UserTotpCipherInjector`](../../api/src/EventListener/UserTotpCipherInjector.php). Scheb reads this through `getTotpAuthenticationConfiguration()`.
- `totpEnabledAt: ?DateTimeImmutable` — telemetry only.
- `totpRecoveryCodes: list<array{hash: string, encrypted?: string, consumedAt: ?string, consumedCode?: ?string}>` — JSON. `hash` is the source of truth for verification; `encrypted` is the sodium-encrypted plaintext used by the GitHub-style "reveal codes" flow. Spent entries are kept (with `consumedAt`) so the UI can show "X of 10 used."

Keeping the secret encrypted at rest means a database leak (without `APP_SECRET`) doesn't yield usable TOTP secrets. The plaintext only lives in process memory between `postLoad` and the next `EntityManager::clear()`.

## Endpoints

All under the `main` firewall (authenticated session required) except the login challenge, which has its own firewall.

| Route | Method | Auth | Purpose |
| - | - | - | - |
| `/me/2fa/setup` | POST | session | First-time enrollment. Generates a fresh TOTP secret, stores it encrypted, returns the secret + `otpauth://` provisioning URI for QR rendering. 409 if 2FA is already on. |
| `/me/2fa/verify` | POST | session | Confirms a code against the just-generated secret. On success: flips `totpEnabled=true`, mints 10 fresh recovery codes, returns them in plaintext (one shot). |
| `/me/2fa` | DELETE | session + step-up | Turns 2FA off and clears the secret + recovery codes. |
| `/me/2fa/recovery-codes` | GET | session | List the current recovery codes (with `consumed` flag). Plaintext is null for still-spendable entries; spent entries show theirs struck through. |
| `/me/2fa/recovery-codes` | POST | session + step-up | Regenerate a fresh batch of 10 codes (invalidates the old set). |
| `/me/2fa/recovery-codes/reveal` | POST | session + step-up | Decrypts and returns every code's plaintext for the "reveal codes" flow. Gated separately because unused codes are real credentials. |
| `/me/2fa/reenroll` | POST | session + step-up | Lost-device path. Rotates the TOTP secret, flips `totpEnabled=false`, clears recovery codes. User then completes via `/me/2fa/verify` as if it were initial setup. |
| `/me/2fa/status` | GET | session | Light status check (`{enabled, recoveryCodesRemaining, enabledAt}`). The user payload at `/api/me` already inlines `twoFactor`, so this endpoint exists mostly for completeness. |
| `/auth/2fa-check` | POST | half-auth (`TwoFactorToken`) | The login challenge. Owned by Scheb's firewall listener — we configure it but don't route here ourselves. |

All routes live in [`App\Controller\TwoFactorController`](../../api/src/Controller/TwoFactorController.php). The login challenge is owned by Scheb but its JSON request/response shape is shimmed by [`App\Security\TwoFactorJsonHandler`](../../api/src/Security/TwoFactorJsonHandler.php).

## Login flow

```
POST /auth/login {email, password}
        │
        ├─► password wrong → 401 { error }
        │
        ├─► password right, totpEnabled=false → 200 { user }
        │
        └─► password right, totpEnabled=true
                │
                │  Scheb's AuthenticationTokenListener wraps the
                │  PostAuthenticationToken in a TwoFactorToken.
                │  AuthController detects this and returns:
                │
                ▼
            401 { requiresTwoFactor: true, providers: ["totp", "backup_code"] }
                │
                │  Cookie session is set ("half-authenticated").
                │
                ▼
POST /auth/2fa-check {code}
        │
        ├─► CheckBackupCodeListener (priority +16)
        │       runs first; if `code` matches a recovery hash,
        │       fires BackupCodeEvents::VALID + consumes the code
        │
        ├─► CheckTwoFactorCodeListener
        │       falls through to the TOTP authenticator
        │
        ├─► both reject → AuthenticationException
        │       → TwoFactorJsonHandler::onAuthenticationFailure
        │       → 401 { error }
        │
        └─► accepted → token upgraded to full PostAuthenticationToken
                → TwoFactorJsonHandler::onAuthenticationSuccess
                → 200 { user (with twoFactor.recoveryPending if backup) }
```

`security_tokens` in the Scheb config lists both `UsernamePasswordToken` (test-only, used by `loginUser()`) and `PostAuthenticationToken` (production json_login). Without `PostAuthenticationToken` the production flow would never trigger the wrap.

## Rate limiting

The `two_factor_verify` limiter (5/minute token bucket, per user) is shared across:

- The `/auth/2fa-check` login challenge, enforced by [`App\EventSubscriber\TwoFactorChallengeRateLimiter`](../../api/src/EventSubscriber/TwoFactorChallengeRateLimiter.php) on `TwoFactorAuthenticationEvents::ATTEMPT`. Throws `TooManyTwoFactorAttemptsException`, which `TwoFactorJsonHandler::onAuthenticationFailure` maps to a 429 + `Retry-After` header.
- The setup-confirm path (`/me/2fa/verify`) inside the controller itself.
- The step-up path in [`App\Service\SensitiveActionVerifier`](../../api/src/Service/SensitiveActionVerifier.php).

One bucket across all three so a password-compromised attacker can't double their budget by alternating endpoints.

## Step-up: SensitiveActionVerifier

Disable, regenerate recovery codes, reveal recovery codes, change password, and re-enroll all route through [`App\Service\SensitiveActionVerifier`](../../api/src/Service/SensitiveActionVerifier.php). It branches on the user's 2FA status and the recovery flag:

```
isTotpEnabled && !recoveryPending → expects `totpCode` (TOTP authenticator)
                                    rate-limited via two_factor_verify

otherwise                         → expects `currentPassword`
```

The recovery branch is what makes the lost-device flow workable: if the verifier always demanded TOTP when `isTotpEnabled` was true, a user who signed in with a backup code would be soft-locked out of `disable()` and `reenroll()`. Falling through to the password check is safe because (a) the user has just proven they don't have the authenticator, and (b) a stolen backup code alone isn't enough — the password floor stays.

A stolen *cookie* still can't rotate the second factor: the cookie session alone passes none of these branches, since both require either a fresh TOTP from the authenticator or the explicit password.

## Recovery flow

Triggered when a backup code is accepted at `/auth/2fa-check`:

```
CheckBackupCodeListener
        │
        │ dispatches BackupCodeEvents::VALID
        │       with TwoFactorCodeEvent(user, code)
        ▼
TwoFactorRecoveryListener::onBackupCodeAccepted
        │
        ├─► TwoFactorRecoveryState::markPending()
        │       sets `_2fa_recovery_pending = true` on the session
        │
        └─► TwoFactorRecoveryMailer::sendBackupCodeUsed()
                fires the "a recovery code was used" notification email
                (TransportException → log + swallow, never block login)
```

The flag is exposed on the user payload at every entry point:

- `TwoFactorJsonHandler::onAuthenticationSuccess` (so the body returned by `/auth/2fa-check` shows `recoveryPending: true` on the same response).
- `AuthController::serializeUser` (so `/api/me` keeps surfacing it after the page reloads).

The PWA's [`TwoFactorRecoveryInterstitial`](../../pwa/components/auth/TwoFactorRecoveryInterstitial.tsx) — mounted in [`Layout.tsx`](../../pwa/components/common/Layout.tsx) — reads `user.twoFactor.recoveryPending` and renders a forced modal (no close X, Escape blocked, outside-click blocked) until the user chooses one of two paths:

### Re-enroll

```
POST /me/2fa/reenroll { currentPassword }
        │ verifier → password check (recovery override)
        ▼
setup->startSetup(user)
        │  generates new secret
        │  totpEnabled = false
        │  recovery codes cleared
        ▼
returns { secret, provisioningUri }
        │
        │  PWA renders QR, user scans, types new TOTP
        ▼
POST /me/2fa/verify { code }
        │  Scheb checks the code against the new secret
        ▼
totpEnabled = true
new recovery codes minted (returned plaintext, one shot)
recoveryState->clear()  ← flag drops, interstitial unmounts
TwoFactorRecoveryMailer::sendReconfigured()
```

If the user abandons after `reenroll` but before `verify`, `totpEnabled` stays false but the recovery flag stays set. Next page load remounts the interstitial at the chooser step.

### Disable

```
DELETE /me/2fa { currentPassword }
        │  verifier → password check (recovery override)
        ▼
setup->disable(user)
        │  totpEnabled = false
        │  secret cleared
        │  recovery codes cleared
        ▼
recoveryState->clear()
TwoFactorRecoveryMailer::sendDisabled()
```

The user can re-enable later from settings via the normal flow.

### Flag lifecycle

[`App\Service\TwoFactorRecoveryState`](../../api/src/Service/TwoFactorRecoveryState.php) is a thin wrapper over a session attribute (`_2fa_recovery_pending`). Reasons for storing it on the session rather than the entity:

- It's a property of *this login*, not a permanent account state. A parallel session on another device is unaffected.
- Logout invalidates the session and the flag with it. There's no way for a half-completed recovery to leak to the next sign-in.
- A user signing in fresh with TOTP after a backup-code session expired starts clean (no spurious interstitial).

## Notification emails

[`App\Service\TwoFactorRecoveryMailer`](../../api/src/Service/TwoFactorRecoveryMailer.php) ships three messages:

| Method | Trigger | Why |
| - | - | - |
| `sendBackupCodeUsed` | `BackupCodeEvents::VALID` | Out-of-band alert. If a recovery code is being used by an attacker, the legitimate user sees it before the recovery interstitial can be completed. |
| `sendDisabled` | `disable()` succeeds with recovery flag set | Confirms the rotation. Important because the disable path during recovery doesn't go through TOTP step-up — the email is the user's last line of "wait, did *I* do this?" |
| `sendReconfigured` | `verify()` succeeds with recovery flag set | Confirms that a new authenticator is now bound to the account. |

All three are best-effort: `TransportExceptionInterface` is caught and logged at the call site so a flaky SMTP can't deny a legitimate user access to their account. The `MessageEvent` still fires before the transport attempts delivery, which is what `assertEmailCount` in the test suite reads against.

`APP_FRONTEND_URL` and `MAILER_FROM` are autowired into the mailer; the emails embed an `/account` deep-link.

We deliberately do *not* send a notification on regular setup/disable/regenerate from settings — the user is logged in and explicitly initiated the action, so it would be noise. Recovery is the exception because the trigger is harder to attribute.

## PWA components

| Component | File | Purpose |
| - | - | - |
| `TwoFactorSection` | [pwa/components/account/TwoFactorSection.tsx](../../pwa/components/account/TwoFactorSection.tsx) | Account-settings card. Shows enable/disable, recovery-code count, reveal + regenerate dialogs. All step-up dialogs render a TOTP input because they're only reachable when 2FA is on. |
| `TwoFactorSetupDialog` | [pwa/components/account/TwoFactorSetupDialog.tsx](../../pwa/components/account/TwoFactorSetupDialog.tsx) | Two-step modal for initial enrollment (scan → verify → show recovery codes). QR is rendered client-side from `qrcode/lib/browser`. |
| `TwoFactorChallengeForm` | [pwa/components/auth/TwoFactorChallengeForm.tsx](../../pwa/components/auth/TwoFactorChallengeForm.tsx) | The post-login 6-digit code input. Accepts both TOTP and recovery codes. Handles the 429 rate-limit response with a countdown. |
| `TwoFactorRecoveryInterstitial` | [pwa/components/auth/TwoFactorRecoveryInterstitial.tsx](../../pwa/components/auth/TwoFactorRecoveryInterstitial.tsx) | The forced modal that mounts when `user.twoFactor.recoveryPending` is true. Re-enroll vs Disable. No close button, no outside-click, Escape blocked. |
| `ChangePasswordForm` | [pwa/components/account/ChangePasswordForm.tsx](../../pwa/components/account/ChangePasswordForm.tsx) | Swaps the password field for a TOTP field when 2FA is enabled. |

`AuthContext` exposes the relevant state:

```ts
interface TwoFactorStatus {
  enabled: boolean;
  recoveryCodesRemaining: number;
  recoveryPending?: boolean;  // optional on the wire — pre-recovery clients won't include it
}
```

The interstitial reads `user.twoFactor.recoveryPending` directly. Other components read `user.twoFactor.enabled` to branch on TOTP vs password step-up.

## Threat model decisions

| Concern | Decision |
| - | - |
| Stolen cookie session | Can't disable or rotate 2FA without TOTP (or password during recovery). The cookie is enough to *use* the app but not to weaken the second factor. |
| Stolen backup code | Logs in once (codes are single-use). Triggers the "recovery code used" email immediately. Can't escalate to disable/reenroll without the password — the recovery override accepts password, not session-cookie-only. |
| Stolen authenticator | User uses a backup code to sign in, follows the recovery interstitial to re-enroll on a new device. The old secret stops working the moment `startSetup()` runs. |
| Lost authenticator AND lost recovery codes | Admin-side reset required. We do not implement an email-based or security-question-based bypass — both factors must be intentionally re-established. |
| Half-finished recovery flow | Interstitial blocks navigation; logout drops the session and the flag. Server-side `_2fa_recovery_pending` is the authority — the PWA can't clear it without completing one of the two paths. |
| Email-mediated takeover (e.g. compromised mailbox) | Notification emails are alerts, not authorization. The recovery flow itself does not rely on an email link, so a compromised mailbox alone cannot reset 2FA. |
| TOTP brute force | 5-attempt-per-minute per-user limiter shared across login + step-up + setup-verify. 6-digit code space (1M) × 5/minute = ~3.8 years expected to brute one code window before it rolls over. |
| Recovery-code brute force | Same limiter applies. Codes are 12 hex chars (~10^14 space). |
| Replay of consumed code | Backup codes are hashed; the listener calls `invalidateBackupCode()` on accept, which removes the matching hash. TOTP relies on `otphp`'s window-tracking. |

## Testing

- [`api/tests/Api/TwoFactorTest.php`](../../api/tests/Api/TwoFactorTest.php) — setup, verify, login challenge, disable, regenerate, reveal. All with a working authenticator.
- [`api/tests/Api/TwoFactorRecoveryTest.php`](../../api/tests/Api/TwoFactorRecoveryTest.php) — backup-code login flags the session, `/api/me` reflects it, disable accepts password during recovery, wrong password still rejected, reenroll rotates the secret and verify clears the flag + fires the reconfigured email, reenroll outside recovery still demands TOTP.

`assertEmailCount` resets between requests in Symfony tests — assert email counts immediately after the request that should send. The tests pin this with the email assertion right after the relevant POST/DELETE.

`createTestUser` + `enableTwoFactor` test helpers in both files: enable 2FA by directly persisting through `TwoFactorSetupService` (skipping the verify endpoint), so tests don't depend on TOTP timing.

## Local dev

- `MAILER_DSN=smtp://mailpit:1025` (default in `compose.yaml`) — emails land in Mailpit at http://localhost:8025 (or the worktree's `mailpit UI` port from `scripts/worktree-env.sh`).
- TOTP needs the system clock to be roughly right. If the host's clock drifts (e.g. after a laptop suspend) tests can fail; restart the `php` container to recover.
- After running `bin/console` commands, restart the `php` container — the FrankenPHP worker hangs onto the previous kernel state and can deadlock the next request.

## Adding a new step-up-protected action

1. Decide whether the recovery override should apply. For "this action is dangerous, but a lost-device user must still be able to perform it" (like changing password), yes. For "this action requires an actively trusted second factor" (like minting an API token), no — leave `SensitiveActionVerifier` in place and let the recovery user fix their authenticator first.
2. Inject `SensitiveActionVerifier` into the controller and call `verify($user, $body)` before the side effect.
3. If the action is a recovery completion (clears the flag), call `TwoFactorRecoveryState::isPending()` *before* doing the side effect, then `clear()` + send the relevant notification email after.
4. Add a test that confirms (a) wrong code/password is rejected with 400, (b) right code/password proceeds, (c) the rate limiter eventually fires 429.
