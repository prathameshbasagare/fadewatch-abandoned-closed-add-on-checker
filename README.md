# Fadewatch – Abandoned & Closed Add-on Checker

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![WordPress](https://img.shields.io/badge/WordPress-6.4%2B-blue.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-green.svg)](https://php.net)

Find out which of your WordPress plugins have been **closed on WordPress.org (and why)**, which haven't been updated in years, and which can't be checked.

> `readme.txt` is the official readme used by the WordPress.org plugin directory. This file is for developers browsing the source.

## Statuses

Every installed plugin gets exactly one status:

| Status | Meaning |
|---|---|
| **Closed** | Closed on WordPress.org. The closure date and reason are shown, e.g. *"Closed on July 27, 2023: Security Issue"*. |
| **Possibly abandoned** | No release in over 24 months (configurable). |
| **Ageing** | No release in 12–24 months (configurable). |
| **Maintained** | Released recently. |
| **Not on WordPress.org** | Premium or custom plugin, or the `Update URI` header points elsewhere. |
| **Not verified** | A different WordPress.org plugin uses the same slug (slug-collision guard). |
| **Couldn't check** | WordPress.org couldn't be reached. Never reported as closed or not found. |

## Features

- **Maintenance** column on the Plugins screen: sortable, with filter links and a coloured border on closed or possibly abandoned rows.
- Report page (**Tools → Plugin Maintenance**) with summary counts, a **Check now** button and CSV export.
- Two Site Health tests: closed plugins (critical when closed for a security issue) and plugins without updates for a long time.
- E-mail only when something changes, an immediate e-mail for a security closure, and an optional weekly summary.
- Multisite: one shared check for the whole network (**Network Admin → Plugins → Plugin Maintenance**).

## How it works

- **Opt-in first.** Nothing is sent to WordPress.org until an administrator clicks "Allow checks and scan now" (or enables it in Settings). Turning it off stops all requests.
- **Background only.** WordPress.org is queried through WP-Cron in batches of 10. Each batch stops after 15 seconds to stay under PHP time limits. Admin pages only read stored data and never make HTTP requests. Nothing runs on the front end.
- **Check now.** Runs the same batches from the browser over AJAX, so checks still work when WP-Cron is disabled or broken.
- **Re-checks.** Closed plugins and failed checks are re-checked every 6 hours. Plugins are re-checked right after they are installed, updated or activated.
- **Accuracy.**
  - A failed request never overwrites an earlier good result.
  - Closed and not-found responses are both HTTP 404, so they are told apart by the response body.
  - The installed plugin's name and author are compared with the WordPress.org entry before its data is used.

## Requirements

- WordPress 6.4 or later
- PHP 7.4 or later

## Developer hooks

### Filters

| Filter | Purpose |
|---|---|
| `fadewatch_plugin_slug( $slug, $file, $data )` | Map a plugin file to a different WordPress.org slug. |
| `fadewatch_skip_plugin( $skip, $file, $data )` | Exclude a plugin from checks. |
| `fadewatch_thresholds( $thresholds )` | Change the `ageing` / `abandoned` thresholds (in months). |
| `fadewatch_status( $evaluation, $item, $record )` | Override the computed status. |
| `fadewatch_email_recipients( $recipients )` | Change notification recipients. |

### Actions

| Action | Fires when |
|---|---|
| `fadewatch_status_changed( $plugin_file, $old_status, $new_status, $evaluation )` | A plugin's status changes. |
| `fadewatch_scan_started( $source )` | A full scan is queued (`activation`, `cron`, `manual`, `fallback`, `settings`). |
| `fadewatch_scan_completed( $results )` | The scan queue has been fully processed. |

### Example

Map a plugin whose folder name differs from its WordPress.org slug:

```php
add_filter( 'fadewatch_plugin_slug', function ( $slug, $file ) {
	return 'my-renamed-folder/plugin.php' === $file ? 'original-wporg-slug' : $slug;
}, 10, 2 );
```

## Project structure

```
fadewatch.php                      Bootstrap, constants, activation hooks
uninstall.php                      Removes options and scheduled events
includes/
  class-fadewatch-api.php               WordPress.org Plugins API client and response parsing
  class-fadewatch-resolver.php          Installed plugin → WordPress.org slug (Update URI, single-file plugins)
  class-fadewatch-status.php            Status model, thresholds, slug-collision guard
  class-fadewatch-scanner.php           Queue, batches, locking, cron, change detection
  class-fadewatch-store.php             Persistent storage (non-autoloaded network options)
  class-fadewatch-report.php            Evaluated rows for every screen
  class-fadewatch-notifier.php          Plain-text e-mails
  class-fadewatch-admin.php             Report and settings page, CSV export
  class-fadewatch-plugins-screen.php    Plugins screen column, filters and sorting
  class-fadewatch-site-health.php       Site Health tests
  class-fadewatch-ajax.php              "Check now" endpoints
  class-fadewatch-render.php            Shared output helpers
assets/                            Admin CSS and dependency-free JS
```

## Branches and releasing

- `dev`: development, including dev-only files (`.gitignore`, `.distignore`, the product spec).
- `release`: only the files that ship to WordPress.org.

Build the release zip from the `release` branch. `git archive` includes committed files only, so the `.git` folder stays out:

```sh
git archive --format=zip --prefix=fadewatch-abandoned-closed-add-on-checker/ release -o ../fadewatch-abandoned-closed-add-on-checker.zip
```

Run [Plugin Check](https://wordpress.org/plugins/plugin-check/) before every release.

## Privacy

Nothing is sent before an administrator allows checks. After that, only plugin slugs are sent, and only to `api.wordpress.org` over HTTPS. The user agent does not include the site URL. There is no tracking and no account. See the *External services* section in `readme.txt`.

## License

This plugin is free software, licensed under the [GNU General Public License v3 or later (GPLv3+)](https://www.gnu.org/licenses/gpl-3.0.html). See [LICENSE](LICENSE) for the full text.
