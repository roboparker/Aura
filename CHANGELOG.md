# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/), and this project uses [date-based build numbers](docs/branching-and-releases.md) for versioning (`YYYY.MM.DD.N`).

## [Unreleased]

### Added
- Groups feature: reusable named user-sets with title, description, owner, and members. Owner has full control (edit, transfer ownership, delete, manage membership); non-owner members are read-only. Surfaced in the PWA at `/groups` and the API at `/groups`.
- Group invitations: owners can invite any email address. Existing users join immediately; unknown emails get a signup-link email (`UserInvite` + `GroupInvite` entities). Single signup joins every group the email has been invited to. Owners can view and revoke pending invites from the group detail page.
