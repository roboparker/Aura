# Resetting a forgotten password

If you can't remember your Madori password, request a reset link by email. The link sets a new password — you don't need to know the old one.

## Reset your password

1. Open the sign-in page and click **Forgot password?**.
2. Enter the email address you use for Madori and submit.
3. Madori always says "if an account exists, we sent a link" — this same message shows whether or not we recognised the address, so nobody can use the form to fish for valid accounts.
4. Open your email and look for **"Reset your Madori password"** from `no-reply@madori.test` (or your deployment's configured sender).
5. Click the link in the email. It takes you to a page where you can set a new password.
6. Type your new password twice and submit. You'll be bounced to the sign-in page with a confirmation banner.

The reset link is valid for **one hour** from the moment we sent it. After that it stops working and you'll need to request a new one.

## What makes a good password

Madori requires at least **8 characters** and rejects passwords that are too easy to guess. The strength meter under the new-password field shows how your choice scores. If it sits in the "too weak" range, the form won't submit.

Tips:

- **Length beats complexity.** A 14-character phrase like `correct horse battery staple` is much stronger than `P@ss1!` and easier to remember.
- **Don't reuse a password from another site.** If that other site is breached, attackers will try the same password here.
- **Use a password manager.** They generate and remember strong passwords for you. 1Password, Bitwarden, and your browser's built-in manager all work.

You can't pick the same password you currently use — the reset will reject it.

## What if the link doesn't work

If you click the email link and Madori tells you the link can't be used, it's one of four reasons:

**"This reset link expired"**
The one-hour window passed. Request a fresh link from the **Forgot password?** page and try again.

**"This link has already been used"**
You (or somebody else with access to your email) already used this link to set a password. If you set it intentionally, just sign in with the new password. If you didn't, request a fresh link — using it will invalidate any other outstanding ones — and consider changing your email password if you suspect your mailbox is compromised.

**"This reset link can't be used"**
Madori didn't recognise the link. Most commonly this means it was mangled by the email client (some clients break long URLs across lines), or you've requested another reset since this email landed (newer requests invalidate older ones). Request a fresh link.

**"This reset link is missing"**
You opened the reset page directly without clicking through an email — the URL needs the `?token=...` part from the email link. Start from the **Forgot password?** page instead.

## What if no email arrives

- **Check your spam folder.** Reset emails are short and link-heavy, which sometimes triggers filters.
- **Double-check the email address.** Madori's response is intentionally identical whether or not we recognised the email. If you mistyped or you actually signed up with a different address, you won't get an email.
- **Wait a minute.** Mail delivery isn't instant; give it 30-60 seconds.
- **Still nothing?** Contact your administrator — if your address really is on the account, they can confirm whether mail is being delivered at all.

## If you also have two-factor authentication on

Resetting your password does **not** turn off 2FA. After you set the new password and sign in, you'll still be asked for a code from your authenticator app (or a recovery code).

If you've also lost access to your authenticator, see [`two-factor-auth.md`](two-factor-auth.md) — sign in once with a recovery code first, then follow the recovery prompt to either re-enroll a new authenticator or turn 2FA off.

If you've lost everything — password, authenticator, *and* recovery codes — contact your administrator. They can disable 2FA on the database side so you can reset your password and sign in with just the new one.

## Why one hour?

Reset links carry the same weight as a password while they're alive — anyone who reads the link in your inbox can take over the account. We keep the window short so a casually-leaked email (forgotten on a shared computer, snapshotted in a backup, etc.) stops being useful quickly. If you don't manage to click within the hour, the workflow is the same: just request another one.

## Frequently asked questions

**Can I have more than one reset link active at once?**
No. Requesting a new link invalidates any previous outstanding ones for that account. The most recent email is always the only one that works.

**Will I get a notification when my password actually changes?**
Not currently. The flow you went through (forgot link → set new password) is its own confirmation. If you suspect your account was reset by someone else, sign in, change the password again immediately, and contact your administrator.

**Can I change the email Madori sends reset links to?**
Yes — sign in, open **Account settings → Email**, and change the address there (this requires confirming your current password). Future reset links will go to the new address.

**I clicked the link from a different device than the one I requested it on. Will it still work?**
Yes. The link works from any device, any browser. It's tied to the request, not the session.
