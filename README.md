# MyBB Spam Preventer Plugin

A configurable MyBB 1.8 plugin that blocks external links, recurring spam phrases, and quote-only replies for new and low-trust members without restricting established members.

Version 0.2.0 adds optional quote-only reply blocking to the server-side link rejection and administrator-managed phrase protections.

## Features

- Applies only to configured restricted usergroups; MyBB group ID `2` is selected by default.
- Keeps members restricted until they meet both the configured post-count and account-age thresholds.
- Defaults to 25 posts and three days to match SickGaming's `New-Members` promotion policy.
- Detects HTTP, HTTPS, FTP, `www`, bare-domain, MyCode, `hxxp`, and common `[dot]` link formats.
- Allows configured trusted domains and all of their subdomains.
- Blocks case-insensitive plain-text phrases in thread subjects and post bodies.
- Optionally requires original text outside complete MyBB quote blocks in new replies.
- Supports user, usergroup, and forum exemptions.
- Always exempts administrators and forum moderators.
- Validates through MyBB's server-side post data handler, so direct requests cannot bypass the rules.
- Preserves the submitted post and displays a clear validation error when content is rejected.
- Includes the configured post-count and account-age requirements in validation errors.
- Shows Quick Reply errors inline above the form instead of using MyBB's corner popup.

## Installation

Copy the contents of `Upload/` into the MyBB installation root:

```text
Upload/inc/plugins/spam_preventer.php -> public_html/inc/plugins/spam_preventer.php
Upload/inc/languages/english/spam_preventer.lang.php -> public_html/inc/languages/english/spam_preventer.lang.php
Upload/jscripts/spam-preventer/quick-reply-errors.js -> public_html/jscripts/spam-preventer/quick-reply-errors.js
```

Then install and activate **Spam Preventer** under **Admin CP → Configuration → Plugins**.

## Configuration

The plugin creates a **Spam Preventer** settings group under **Admin CP → Configuration → Settings**.

### Eligibility

The default restricted group is ID `2`, MyBB's built-in Registered group. SickGaming has renamed this group `New-Members`. Change the configured ID if the restricted group on your board is different.

With the default thresholds, a member in a restricted group remains subject to the rules while either of these conditions is true:

- The member has fewer than 25 posts.
- The account is younger than three days.

The member becomes exempt after meeting both thresholds. Setting either threshold to `0` disables that individual condition. Setting both to `0` makes the rules apply to every member of a restricted group regardless of post count or account age.

### Trusted Domains

Enter one domain per line without a protocol or path:

```text
sickgaming.net
github.com
*.edu
*.gov
```

Subdomains are trusted automatically, so `cdn.sickgaming.net` is covered by `sickgaming.net`.
A leading wildcard trusts all domains under a suffix. For example, `*.edu` allows
`mit.edu` and `engineering.mit.edu`, but not `example.education`. The default list
allows SickGaming, GitHub, `.edu` domains, and `.gov` domains.

### Blocked Phrases

Enter one case-insensitive plain-text phrase per line. Blank lines and lines beginning with `#` are ignored:

```text
# Recurring coupon spam
shein coupon code
coupon code for new users
```

The initial release deliberately uses literal phrases instead of regular expressions. This makes rules easier to review and reduces the risk of invalid or overly broad patterns.

### Quote-Only Replies

Enable **Block Quote-Only Replies** to require restricted members to add meaningful original text outside quoted content. The rule handles attributed, multiple, and nested MyBB quote blocks and ignores whitespace or empty formatting tags left around them. It applies to Full Reply and Quick Reply, but not to new threads or edited posts.

### Exemptions

Usergroup IDs, user IDs, and forum IDs accept comma-separated values. Administrators and moderators of the current forum are exempt automatically. A future release may add named VIP and donor bypass controls; those groups can be entered in **Exempt Usergroup IDs** now.

## Relationship to MyBB Controls

This plugin complements rather than replaces MyBB's existing protections. Before using it, configure Cloudflare Turnstile or another CAPTCHA, Stop Forum Spam, group promotions, Purge Spammer, and the per-usergroup maximum-posts-per-day setting.

The plugin does not duplicate MyBB's daily post limit. Planned later milestones include rolling thread limits, moderation actions, regular-expression rules, audit logging, test-only mode, and an account reconciliation tool.

## Uninstall

Uninstalling removes the Spam Preventer setting group and all plugin settings. It does not alter users, usergroups, posts, threads, or unrelated MyBB configuration.

## Testing

Run the focused standalone checks with:

```bash
php tests/spam_preventer_test.php
```

Production testing should use a non-staff account in the configured restricted group. Verify normal text, trusted links, external links, blocked phrases, full replies, Quick Reply inline errors, new threads, and edited posts before relying on the plugin.

## License

Copyright (C) 2026 SickProdigy.

This project is licensed under the GNU General Public License, version 3 or any later version.
