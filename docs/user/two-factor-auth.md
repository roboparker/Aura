# Two-factor authentication

Two-factor authentication (2FA) adds a second check at sign-in: along with your password, you also need a short numeric code from an app on your phone. Even if someone learns your password, they still can't sign in without your device.

Madori supports time-based one-time passwords (TOTP) — the same kind used by Google Authenticator, 1Password, Authy, Bitwarden, and most other authenticator apps.

## Set it up

You'll need an authenticator app installed on your phone. Any of these work:

- **Google Authenticator** (iOS, Android)
- **1Password** (paid)
- **Authy** (iOS, Android, desktop — has cloud backup)
- **Bitwarden** (free, has cloud backup)
- **Microsoft Authenticator** (iOS, Android)

Then in Madori:

1. Sign in and open **Settings → Security**.
2. Find the **Two-factor authentication** section.
3. Click **Enable**.
4. A QR code appears. Open your authenticator app, tap "Add account" (the exact wording varies — look for a `+` button), and scan the code. If your phone can't reach the screen, tap **Enter this key manually** under the QR and type it in.
5. The app now shows a six-digit code that changes every 30 seconds. Type the current code into Madori and click **Verify and enable**.
6. Madori shows you **ten recovery codes**. Save them now — see [recovery codes](#recovery-codes) below.

Two-factor is now on. The next time you sign in, you'll be asked for a code after entering your password.

## Sign in with 2FA on

1. Enter your email and password as usual.
2. Madori prompts for your six-digit code.
3. Open your authenticator app and type the current code for Madori.

If the code is rejected, double-check that:

- Your phone's clock is correct (TOTP codes depend on the time). Almost every authenticator app has a "sync time" option in settings if you're seeing repeated rejections.
- You typed the code for *Madori* — your app likely has codes for several other services that look identical.
- The code hasn't already rolled over. Each code is valid for 30 seconds; if it changes mid-typing, use the new one.

After five code attempts within a minute, Madori rate-limits you for a short window. Wait, then try again with a fresh code.

## Recovery codes

When you turn 2FA on, Madori gives you a list of ten recovery codes. Each one works **once** instead of an authenticator code — they're your way back in if you lose your phone.

Treat them like passwords:

- **Save them somewhere safe.** A password manager is ideal. Printing them and putting them in a desk drawer also works.
- **Don't email them to yourself** or save them in a note synced to a cloud you can't reach without the phone you're protecting.
- **Don't share them.** Anyone with a code can use it to sign in.

You can see how many codes you have left in **Settings → Security → Two-factor authentication**. If you've used several and want a fresh batch, click **Regenerate codes** — this invalidates the old set and gives you ten new ones.

To see your current codes again later (for example, to copy them into a new password manager), click **Reveal codes**. You'll be asked for a code from your authenticator app first.

## I lost my phone

If your authenticator app is gone, use a recovery code:

1. Sign in with your email and password.
2. When asked for the six-digit code, paste a **recovery code** instead. Recovery codes look like `a3f9-1c8b-22e0`.

Once you're in, Madori shows a one-page screen titled **"Recover your account"**. You can't close this window or navigate around — every page will keep landing back here until you finish the recovery. You have two choices:

### Set up a new authenticator (recommended)

This rotates your two-factor secret onto a new device while keeping 2FA on.

1. Click **Set up a new authenticator**.
2. Enter your account password.
3. A new QR code appears. Scan it with your new authenticator app — exactly like the initial setup.
4. Type the current six-digit code from the new app to confirm.
5. Madori issues a **fresh set of ten recovery codes**. Save these — your old codes no longer work.

### Turn off two-factor authentication

This removes 2FA from your account entirely. Use this if you don't have a new authenticator handy and want to restore it later from settings.

1. Click **Turn off two-factor authentication**.
2. Enter your account password.
3. Click **Disable 2FA**.

Your account is now password-only. You can turn 2FA back on any time from **Settings → Security**.

### Notification emails

Whenever something significant happens to your 2FA setup, Madori sends an email to your account address:

- **"A recovery code was used on your Madori account"** — fires the moment you sign in with a recovery code. If you didn't sign in, that's a strong signal that someone else is trying to get into your account; sign in and change your password right away.
- **"Two-factor authentication was disabled"** — sent when 2FA is turned off through the recovery flow.
- **"A new authenticator was enrolled for your account"** — sent when you re-enroll a new device through the recovery flow.

If you receive any of these and didn't trigger it, change your password and contact your administrator.

## I lost my phone *and* my recovery codes

Madori has no way to confirm your identity if you can't produce either a TOTP code or a recovery code. Contact your administrator — they can disable 2FA on your account from the database side. Once they do, you can sign in with just your password and turn 2FA back on with a new authenticator.

This is intentional: a backdoor that bypassed both factors would defeat the point of 2FA.

## Turning 2FA off voluntarily

You can disable 2FA from **Settings → Security → Two-factor authentication → Disable** at any time. You'll be asked for a current code from your authenticator (not your password — Madori assumes your authenticator still works) to confirm.

Once disabled, your TOTP secret and remaining recovery codes are deleted. If you re-enable later, you'll go through the QR-scan setup from scratch.

## Frequently asked questions

**Can I use SMS or email codes instead of an app?**
No. Madori only supports TOTP authenticator apps. SMS and email codes are widely considered weaker than TOTP and aren't on the roadmap.

**Can I have more than one authenticator?**
You can scan the same QR code into multiple apps during setup, and they'll all generate matching codes. Madori itself only knows about one secret at a time — if you re-enroll later, every device sharing the old secret stops working at once.

**Will I be asked for a code on every sign-in?**
Yes. Madori doesn't have a "remember this device" option — every fresh login is challenged.

**My code keeps getting rejected even though it looks right.**
Almost always a clock issue. Open your authenticator app's settings and look for "sync time" or "correct time" — that fixes the vast majority of these. If that doesn't help, generate a recovery code login to get back in and contact your administrator.
