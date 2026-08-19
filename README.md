# MyBB Spam Preventer Plugin

A configurable spam-prevention plugin for MyBB 1.8 that limits the damage new and low-trust accounts can cause without unnecessarily restricting established members.

The plugin is intended to complement MyBB built-in anti-spam and usergroup controls. It will focus on safeguards that MyBB does not provide directly, including restricting external links below a configurable post-count or account-age threshold, limiting new threads over a rolling period, and detecting recurring spam phrases or patterns.

## Goals

- Restrict external links for new members, with a default threshold of 10 approved posts.
- Recognize raw URLs, MyCode links, common URL obfuscation, and shortened links.
- Allow trusted domains, forums, usergroups, and individual users to be exempted.
- Limit how many threads low-trust accounts can create during a configurable period.
- Match configurable plain-text phrases and regular expressions in thread subjects and post bodies.
- Support rule actions such as logging, rejecting, unapproving for moderator review, deleting, or purging a spammer.
- Record which rule matched and what action was taken in an administrator-accessible log.
- Provide a test-only mode so new rules can be evaluated safely before enforcement.

## Safety

Content rules should default to sending suspicious posts to MyBB moderation queue rather than deleting them. Destructive actions should be explicitly enabled per rule and reserved for patterns that have already been proven reliable.

Administrators and moderators, trusted usergroups, approved forums, and configured domains should be exempt where appropriate. Server-side validation will be authoritative so restrictions cannot be bypassed by disabling JavaScript or submitting requests directly.

## MyBB Features Used Alongside This Plugin

Before enabling plugin rules, administrators should configure the protections already supplied by MyBB:

- Registration security questions and CAPTCHA.
- Stop Forum Spam registration checks.
- New-member or first-post moderation using usergroups and group promotions.
- Per-usergroup maximum posts per day.
- Purge Spammer settings for moderator cleanup.

This plugin will not duplicate MyBB per-usergroup daily post limit. Its posting-rate feature will concentrate on limits MyBB does not provide adequately, particularly configurable new-thread limits for low-trust accounts.

## Status

This project is in the planning stage. No installable release is available yet.

## License

Copyright (C) 2026 SickProdigy.

This project is licensed under the GNU General Public License, version 3 or any later version.
