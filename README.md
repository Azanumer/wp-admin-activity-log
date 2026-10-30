# Admin Activity Log

A lightweight WordPress plugin that records what happens in wp-admin — so when
something changes unexpectedly, you can see who did it and when.

## What it logs

| Action | Trigger |
|---|---|
| `post_published` / `post_updated` / `post_trashed` / `post_deleted` | Post and page status changes |
| `plugin_activated` / `plugin_deactivated` | Plugin toggles |
| `theme_switched` | Theme changes |
| `user_created` / `user_deleted` / `user_role_changed` | User administration |
| `comment_approved` / `comment_spammed` / `comment_trashed` | Comment moderation |
| `option_updated` | Settings changes (transients, cron storage and this plugin's own option excluded) |

Each event stores the user, IP address, object type/ID/label and timestamp in
`{prefix}waal_events`.

## Viewing the log

**Tools → Activity Log** — paged table (50 per page), filterable by action,
with a one-click clear button (nonce-protected) and a retention setting
(default 90 days, enforced by a daily cron job).

## Filters

- `waal_retention_days` — change the retention window (days).
- `waal_ignored_actions` — return an array of action names to skip logging.

## Installation

Upload the plugin folder to `wp-content/plugins/`, activate it, and the table
is created automatically. Uninstalling drops the table and its options.

## License

MIT — see [LICENSE](LICENSE).
