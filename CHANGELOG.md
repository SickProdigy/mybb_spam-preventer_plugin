# Changelog

## 1.0.1 - 2026-09-07

- Added a starter blocked-phrase list for recurring coupon, discount, promo, and referral spam campaigns.
- Added `default-blocked-phrases.txt` so the shipped phrase list can be reviewed outside the AdminCP.
- Included the default phrase list in release packages.
- Clarified that SickGaming's new-member promotion flow is handled by MyBB group promotions, not by Spam Preventer itself.
- Kept AdminCP setting descriptions generic for use on other boards.

## 1.0.0 - 2026-09-07

- Added per-rule actions for external links, blocked phrases, and quote-only replies: log only, reject, send to moderation queue, or ban user and reject.
- Added optional rule-hit logging with user, forum, rule, action, subject, excerpt, timestamp, and IP storage for administrator review.
- Added optional rapid-thread burst detection with a temporary cooldown for low-trust members, disabled by default.
- Added a configurable cooldown scope so rapid-thread penalties can block new threads only or all posting.
- Added activation-time table creation for Spam Preventer logs and rapid-thread cooldowns.
- Added a configurable ban target usergroup for rules using the ban action.
- Reorganized AdminCP settings with clearer labels for general options, eligibility, rules, rapid threads, ban action, and exemptions.
- Updated validation tests, documentation, and release packaging for the 1.0.0 release.

## 0.2.0 - 2026-08-21

- Added optional quote-only reply blocking for restricted members.
- Added Quick Reply inline validation error handling.
- Added tests for quote parsing, MyCode edge cases, trusted domains, phrase matching, and eligibility checks.

## 0.1.0 - 2026-08-19

- Added initial low-trust member protections for external links and administrator-managed spam phrases.
- Added trusted-domain, user, group, and forum exemption settings.
