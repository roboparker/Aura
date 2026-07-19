# Exporting your data

You can download a copy of everything you've created in Madori — your profile, your settings, the content you authored, and the files you uploaded. This supports your right to a copy of your personal data under regulations like the GDPR and CCPA.

## Request your export

1. Go to **Settings → Danger zone**.
2. Under **Export my data**, click **Request export**.
3. Confirm it's you: enter your **authentication code** if you have two-factor enabled, otherwise your **current password**.
4. Madori starts building your archive in the background and shows a confirmation. You can close the dialog — you don't need to wait on the page.
5. When the archive is ready, we email you a **download link**. Open the email and click through; you'll need to be signed in to Madori as yourself to download the file.

Building can take anywhere from a few seconds to a few minutes depending on how much content and how many files you have.

## The download link

- The link works **only for your account** — being signed in as you is required, so a forwarded email alone is useless to anyone else.
- The link is valid for **7 days**. After that the archive is deleted and the link stops working; just request a new export.
- You can only have **one export at a time**. If you request another while one is still being prepared, we'll point you back to the export already in flight. Requesting a fresh export after the previous one finished replaces it.

## What's in the archive

A zip file containing:

- **`account.json`** — your profile and preferences, plus the tasks, boards, pages, comments, tags, and API tokens you created.
- **`attachments/`** — the files you uploaded, including your avatar.

The export only contains **your own** data. Other people's content is referenced by an internal id where it overlaps with yours (for example, who else was assigned to one of your tasks), never their personal details.

## If the link has expired

If you open the link and Madori says the export has expired, the 7-day window has passed and the archive has been deleted. Go back to **Settings → Danger zone** and request a fresh export.

## Still waiting for the email?

- Check your spam or junk folder for a message from `no-reply@madori.test` (or your deployment's configured sender).
- Large archives take longer to build. Give it a few minutes before assuming something went wrong.
- If it never arrives, request the export again — and if that still fails, contact your administrator.
