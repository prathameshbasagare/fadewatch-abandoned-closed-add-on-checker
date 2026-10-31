# Abandoned Plugin Checker: Product & Technical Spec

_Draft v0.1 · 31 Oct 2026_

Working name: **Abandoned Plugin Checker** (the phrase people search for). Final name and slug need to be checked on WordPress.org for availability and trademark rules. Don't start the name with "WordPress" or "WP".

---

## 1. Competitor research (updated)

I read the code of the small competitors and of WPVulnerability, and read the reviews and support threads for all of them. I relied on public documentation for Wordfence.

### 1.1 Feature comparison

| Feature | Vendi Abandoned Plugin Check | Plugins Last Updated Column | Outdated Plugin Notifier | k-OutDated Checker | **WPVulnerability** | **Wordfence** (scan) |
|---|---|---|---|---|---|---|
| Installs · rating | 1k · 82% | 600 · 98% | 400 · 100% | 60 · 60% | **10k · 100%** | 5M · 94% |
| Last update | Apr 2026 | **Apr 2025 (stale, has a CVE)** | Sep 2026 | Jan 2026 | Aug 2026 | Sep 2026 |
| "Last updated" shown in Plugins list | ✅ (row text) | ✅ (column) | ✅ (column) | ❌ | ✅ (column) | ❌ (scan results) |
| Stale threshold | 365 days (only changeable with code) | Colours | Unknown | Unknown | 1 year, fixed | **2 years** |
| Detects **closed** plugins | ❌ (silently skipped, see code) | ✅ | ✅ ("expelled") | ? | ⚠️ "may no longer be available (closed?)" from its own API; users report **false positives** | ✅ (critical alert) |
| Shows closure **reason** (e.g. Security Issue) | ❌ | ❌ (reads it, doesn't show it) | ❌ | ❌ | ❌ | ? (not verified) |
| Themes | ❌ | ❌ | ❌ | ❌ | ✅ | ✅ |
| Email alerts | ❌ | ❌ | ❌ (requested) | ✅ (twice a day) | ✅ | ✅ |
| Dashboard widget | ❌ | ❌ | ❌ | ❌ | ✅ | ✅ |
| Site Health | ❌ | ❌ | ❌ | ❌ | ✅ | ? (not verified) |
| WP-CLI | ❌ | ❌ | ❌ | ❌ | ✅ | ✅ (premium/CLI) |
| Known vulnerabilities | ❌ | ❌ | ❌ | ❌ | ✅ | ✅ |
| Highlights old plugins in Add New search | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Filter views ("show only abandoned") | ❌ (requested) | ❌ | ❌ | ❌ | ✅ (vulnerable only) | ❌ |
| Makes API calls in the background (not while the page loads) | ✅ (cron, batches of 10) | ❌ (one call per plugin on page load → slow) | ? (fetches `.json` while the page loads, users see 404 errors) | ✅ | ✅ | ✅ |

Other related plugins: **Jetpack Protect** (100k) and **Patchstack** (100k) scan for vulnerabilities but don't check maintenance status. **WP Updates Notifier** (20k) emails about available updates only. **MainWP / ManageWP** offer abandoned-plugin checks for agencies across many sites.

### 1.2 What this means

- **WPVulnerability already covers most of the original idea** (last-updated column, a closed hint, themes, email, dashboard widget, Site Health, WP-CLI) **and adds vulnerability data**. It's free and rated 100%. This is the real competitor.
- Our plugin is only worth building if it is **the most accurate and focused tool for maintenance status**, not a weaker vulnerability scanner. Clear gaps across all competitors:
  1. **Closure reason and date** from WordPress.org (e.g. "Closed on 27 Jul 2023: Security Issue"). No competitor shows this, and it is the most useful thing to know.
  2. **No false positives.** WPVulnerability users report "closed?" on live plugins. Outdated Plugin Notifier users report "not found" on plugins that exist. Both seem to treat API failures or other data sources as "closed". Our plugin must tell apart *closed*, *not on WordPress.org*, and *couldn't check*.
  3. **Correctly identifying which WordPress.org plugin is installed** (Update URI header, plugins whose folder name differs from their WordPress.org slug, single-file plugins, and custom plugins whose name happens to match one on WordPress.org).
  4. **Configurable thresholds and severity levels**, instead of a fixed 1 or 2 years.
  5. **Acknowledge / snooze** a warning ("I know, we maintain a fork").
  6. **Suggested alternatives** for closed plugins (popular plugins with similar tags).
  7. **Reliable scans:** a "Check now" button and fallbacks, because Vendi's 1★ reviews come from its scheduled checks never running.
- **Market size is small:** about 2k installs across the four dedicated plugins, 10k for WPVulnerability. That's acceptable for a reputation/portfolio project, but not a big market. **Decision for you:** build it standalone, or as a module inside the planned "environment badge + staging guard" plugin (see §11).

---

## 2. Goals and non-goals

**Goals**
1. Within one minute of activation, show every installed plugin's (and theme's) **maintenance status** on WordPress.org: active, ageing, stale, **closed (with reason)**, not on WordPress.org, or couldn't check.
2. **Alert the admin when something changes**, especially when a plugin gets closed.
3. **No false alarms**, no slow admin pages, no ads.

**Non-goals (v1)**
- Scanning for vulnerabilities (Patchstack, WPVulnerability, Jetpack Protect and Wordfence already do it; optional integration later, see §10).
- Code scanning / PHP compatibility analysis.
- Auto-deleting or auto-deactivating plugins.
- Checking premium plugins sold outside WordPress.org (shown as "Not on WordPress.org"; maybe later via a filter).

## 3. Users

| User | Need |
|---|---|
| Site owner | "Is anything on my site dangerous or dead?" in plain language |
| Freelancer / agency | Check a client site quickly, export a report, get an email when something changes |
| Developer | WP-CLI output, hooks, JSON output for scripts and CI |

---

## 4. Functional requirements

### 4.1 Status model

Each installed plugin or theme gets exactly one status:

| Status | Rule (defaults, all configurable) | Severity |
|---|---|---|
| 🟢 **Maintained** | Updated within **12 months** | none |
| 🟡 **Ageing** | 12–24 months since last update | low |
| 🟠 **Possibly abandoned** | > 24 months since last update (same rule as Wordfence) | medium |
| 🔴 **Closed** | WordPress.org says `closed: true`. Show the **reason** and **closed date** | high; **critical** if the reason is `security-issue` |
| ⚪ **Not on WordPress.org** | API returns "not found" (premium, custom, removed long ago), or the plugin opted out via the Update URI header | info |
| ⚫ **Couldn't check** | Network error, timeout, rate limit or malformed response. **Never reported as closed or not found** | info, retry |

Extra signals, shown but never used to set the status:
- **"Tested up to" more than 3 major WordPress releases behind the current one** (the same rule WordPress.org uses for its own warning)
- Active installs and rating
- Support threads resolved in the last 2 months (e.g. "3 of 25"), a rough sign of whether the developer is still answering
- Installed version vs. latest version (core already shows when an update is available, so this is only displayed for context)

### 4.2 Working out which WordPress.org plugin is installed

1. If the plugin header has an **Update URI** that isn't `wordpress.org` / `w.org`, mark it **Not on WordPress.org** and don't make an API call. Core treats such plugins the same way since WP 5.8.
2. Otherwise use the plugin's folder name as the slug. **Single-file plugins** in the plugins folder (e.g. `hello.php`) use the file name, and Hello Dolly is a special case (`hello-dolly`).
3. **Name-collision guard:** if the plugin name or author in the installed plugin's header differs strongly from the WordPress.org result, mark it **"Possible slug collision: not verified"** instead of showing WordPress.org data. Matching rule: normalised name similarity or matching author URI.
4. **Must-use plugins and drop-ins:** listed in the report as "Not checked (must-use plugin or drop-in)".
5. Filters: `apc_plugin_slug` (developers can map a plugin to a slug) and `apc_skip_plugin` (exclude a plugin).

### 4.3 Data fetching

- Plugins: `GET https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=…` with only the fields we need (no sections, versions or screenshots).
  - **Closed plugins:** HTTP 404 with JSON `{"error":"closed","closed":true,"closed_date":"YYYY-MM-DD","reason":"security-issue","reason_text":"Security Issue",…}`. Confirmed with `wp-hide-post`.
  - **Not found:** HTTP 404 with `{"error":"Plugin not found."}`. Both cases return **404**, so the plugin must read the response body.
- Themes: `GET https://api.wordpress.org/themes/info/1.2/?action=theme_information&request[slug]=…`. Returns `last_updated`, `requires` and `requires_php`; "not found" is a 404 with `{"error":"Theme not found"}`. **Still to check:** how the theme API represents a closed or suspended theme.
- Use `wp_remote_get()` over HTTPS only, with a 10 s timeout and a user agent of `AbandonedPluginChecker/x.y; <site URL>`. Unlike Vendi, never fall back to plain HTTP.
- **Background only:** checks run through WP-Cron in **batches of 10**, one batch per cron run with up to 1 s between requests. A full scan happens daily. Nothing is fetched while the Plugins screen loads.
- **"Check now" button:** runs the batches through AJAX with a progress bar. This is the fallback when WP-Cron is disabled or broken, which explains Vendi's "did nothing" reviews.
- **Smart re-checks:** re-check a plugin right away after it is installed or updated (`upgrader_process_complete`) or activated. Plugins that were closed or couldn't be checked are re-checked more often (every 6 h).
- **Storage:** one site option (network option on multisite), `apc_results`, holding per-slug data (status, last_updated, closed_date, reason, tested, installs, rating, checked_at, error_count). Autoload is off. Results expire after 7 days; after that they're shown as "data out of date".
- **Errors:** after 3 failed checks in a row the plugin stays "Couldn't check" with the error message. A failed check never overwrites a good earlier result as "closed" or "not found".

### 4.4 Admin interface

**A. Plugins screen (`plugins.php`, also network admin)**
- New sortable **"Maintenance"** column: a status label with colour *and* icon (so colour isn't the only signal), "Updated 3 years ago (12 Mar 2023)", and the closure reason when closed.
- **Filter links above the table:** `Closed (2) | Possibly abandoned (5) | Not on WordPress.org (3)`. Requested by Vendi users.
- Closed and possibly-abandoned plugins get a coloured left border (requested by Vendi users).
- Row links: "Why?" (opens the details popup), "Acknowledge" (snooze), "Find alternatives".

**B. Themes screen**
- A label on each theme card plus a section in the report. Phase 2 if it takes too long.

**C. Report page (Tools → Plugin Maintenance)**
- Summary counts, the full table (plugins + themes), last full check time, "Check now".
- Export to **CSV** and **Markdown** for client reports, plus a printable view.
- **Acknowledged items** list (with optional note and expiry) where items can be un-acknowledged.

**D. Dashboard widget**
- "2 closed · 5 possibly abandoned · last checked 3 h ago", with a link to the report. Hidden when everything is fine (configurable).

**E. Site Health**
- Two tests: "Installed plugins closed on WordPress.org" (critical if closed for security, recommended otherwise) and "Plugins not updated in over 2 years" (recommended). Site Health is where WordPress itself tells admins to look.

**F. Plugins → Add New (search results and plugin cards)**
- A small label on old or "not tested" plugins **before installing**: "Last updated 4 years ago". Vendi has this; nobody else does.

**G. Suggested alternatives (closed / possibly-abandoned plugins)**
- Fetch up to 3 plugins with the same tags from WordPress.org (`query_plugins` with `tag=`), sorted by active installs and updated within the last 6 months. Labelled "Similar plugins (not reviewed)". Cached for 7 days. This is new; no competitor does it.

### 4.5 Notifications

- **Email digest only when something changes** (new closures, newly abandoned plugins), not a daily repeat. Optional **weekly summary**. Requested by Outdated Plugin Notifier users. WP Updates Notifier users disliked repeated emails.
- **Closed for a security issue = immediate email**, without waiting for the digest.
- Recipients: the admin email by default; any comma-separated list.
- Links in the email go to the **report page in wp-admin**, not the homepage (requested by WPVulnerability users).
- Optional **webhook URL** (POST JSON) so people can connect Slack, Teams or Discord. WP Updates Notifier dropped Slack and users missed it. Phase 2.
- Plain-text and simple HTML emails, with no large images (a WPVulnerability complaint).

### 4.6 Settings (Settings → Plugin Maintenance, or a tab on the report page)

- Thresholds: "Ageing" after N months (default 12), "Possibly abandoned" after N months (default 24).
- Include themes: on/off.
- Include inactive plugins: on (default). Inactive plugins can still be exploited.
- Notifications: off / on change / weekly; recipients; webhook URL.
- Dashboard widget: always / only when there are issues / off.
- Show the label on Add New: on/off.
- "Delete all data on uninstall" (default on).

No top-level admin menu (a complaint found in the first research round). Everything lives under Tools and Settings, with a "Settings" link on the Plugins screen row.

### 4.7 WP-CLI

```
wp abandoned-plugins check [--format=table|json|csv] [--status=closed,abandoned] [--themes]
wp abandoned-plugins refresh [--slug=<slug>]
wp abandoned-plugins acknowledge <slug> [--until=<date>] [--note=<text>]
```
Exit code is **1 when anything is closed** (and optionally when anything is possibly abandoned, with `--fail-on=abandoned`), so it can be used in CI and deployment scripts.

### 4.8 Multisite

- Network-activated: one shared scan of all plugins, shown in **Network Admin → Plugins** and in a Network Admin report page.
- Single-site admins see only the Themes data and nothing about network plugins they can't manage.
- Settings at network level.

### 4.9 Hooks for developers

- Filters: `apc_thresholds`, `apc_plugin_slug`, `apc_skip_plugin`, `apc_status` (override the computed status), `apc_email_recipients`, `apc_alternatives`.
- Actions: `apc_status_changed( $slug, $old, $new, $data )` and `apc_scan_completed( $results )`.

---

## 5. Non-functional requirements

| Area | Requirement |
|---|---|
| Performance | **Zero extra HTTP requests while an admin page loads.** Plugins-screen overhead < 5 ms for 100 plugins (one option read). No front-end code at all |
| Size | < 150 KB zip; no Composer runtime dependencies; plain JS (no jQuery dependency) for "Check now" and filters |
| Compatibility | WP ≥ 6.4 (to verify), PHP ≥ 7.4; tested on PHP 8.4 / 8.5; no `_load_textdomain_just_in_time` notice (translations load on `init`) |
| Security | Every admin action checks `manage_options` (`manage_network_options` on multisite) plus a nonce. CSRF in its admin action is the cause of the CVE in Plugins Last Updated Column. All output escaped; webhook URL validated with `wp_http_validate_url()` |
| Privacy | Only plugin/theme slugs are sent, and only to api.wordpress.org (core already does this). No tracking. A privacy-policy text is added through `wp_add_privacy_policy_content()` |
| Accessibility | Status is never shown by colour alone; filters and buttons work with the keyboard; progress bar uses `aria-live` |
| i18n | Text domain = slug; all strings translatable; dates via `wp_date()`; relative times via `human_time_diff()` |
| Reliability | Works with `DISABLE_WP_CRON` (manual check and WP-CLI); schedules a fallback check when an admin visits the Plugins screen and the data is over 48 h old |
| Uninstall | `uninstall.php` removes options, transients and scheduled cron events (Hide Admin Bar users complained about leftover requests after uninstall) |
| Code quality | WordPress coding standards, PHPStan level 6+, Plugin Check (PCP) clean |

---

## 6. Edge cases (things that are easy to miss)

1. **Closed temporarily, then reopened.** Re-check closed plugins every 6 h and send a "reopened" notice.
2. **Closed because the author asked** (`author-request`) vs **security issue** vs **guideline violation**. These need different severity and wording; don't scare people about author requests.
3. **Plugin replaced by a WordPress core feature** (e.g. some features merged into core). Explain this in the details popup.
4. **A custom plugin with the same folder name as a WordPress.org plugin.** This is the slug-collision guard in §4.2. Without it you get false "abandoned" warnings.
5. **Premium version of a WordPress.org plugin** (e.g. a `-pro` folder): usually "not found", so show "Not on WordPress.org (premium?)".
6. **Plugin renamed / slug changed on WordPress.org:** the old slug returns "not found". Allow a manual slug mapping in the details popup.
7. **API rate limits or outages:** exponential backoff; the status stays at its last known good value and is marked "out of date".
8. **Large sites (100+ plugins):** batching keeps each cron run short; the progress bar shows N of M.
9. **Staging copies of the same site:** many identical scans. Acceptable, but add an optional "skip if environment is local" setting (`wp_get_environment_type()`).
10. **Plugins installed via Composer / Git** with no WordPress.org link: they may still match a WordPress.org slug. Covered by the Update URI rule plus the collision guard.
11. **Themes:** child themes have no WordPress.org entry. Check the parent theme instead and mark the child as "child theme of X".
12. **Times and dates:** WordPress.org returns `last_updated` like `2026-10-21 4:06pm GMT`. Parse it as UTC.
13. **Multisite plugins enabled per site** vs network-activated: show both in the network report with their activation scope.

---

## 7. What we were missing before this spec

Compared with the original one-paragraph idea, these items came out of the competitor review:

1. **WPVulnerability already exists** and covers most of the idea, plus vulnerabilities. Our positioning has to be accuracy and focus on maintenance status (§1.2).
2. **Themes**, not just plugins.
3. **Closure reason and date** (available from the API, shown by nobody).
4. **Telling "closed", "not found" and "couldn't check" apart.** All competitors that tried got this wrong in some cases.
5. **Slug collisions and the Update URI header.**
6. **Acknowledge / snooze** a warning.
7. **Labels on Plugins → Add New** (check before installing).
8. **Site Health integration**, which is where WordPress tells admins to look.
9. **"Check now" + reliable fallback** for when WP-Cron doesn't run.
10. **CI-friendly WP-CLI exit codes.**
11. **Suggested alternatives** for closed plugins.
12. **Re-checking closed plugins** (they can reopen).

---

## 8. Release plan

| Version | Scope | Estimate |
|---|---|---|
| **1.0 (MVP)** | Status model; slug resolution with Update URI and collision guard; background batch scan + "Check now"; Plugins-screen column + filter links + row highlighting; closure reason and date; report page with CSV export; email on change; Site Health tests; uninstall cleanup | 5–7 days |
| 1.1 | Themes; dashboard widget; acknowledge/snooze; Add New labels; WP-CLI | 3–4 days |
| 1.2 | Suggested alternatives; webhook; multisite network report; Markdown/print report | 3–4 days |
| Later (optional) | Free vulnerability data (e.g. Wordfence Intelligence feed or WPVulnerability API), shown only as a link/label to avoid copying a whole scanner. Check each data source's licence and terms first | – |

> This is more than the "2–3 days" estimated earlier. The extra time goes on accuracy, edge cases and reliability, which is what would make it better than the competitors.

## 9. Testing plan

- **Unit tests (PHPUnit):** status calculation from threshold dates; parsing 404 bodies (closed / not found / unexpected); slug resolution (folder, single file, Update URI, collision); date parsing.
- **Integration fixtures:** saved API responses for: an active plugin (`akismet`), a security-closed plugin (`wp-hide-post`), a non-existent slug, a timeout, a 5xx error, malformed JSON.
- **Manual matrix:** single site and multisite; WP-Cron on/off; 5 / 50 / 150 plugins; PHP 7.4 / 8.3 / 8.5; latest WordPress and the previous 2 major versions.
- **Plugin Check (PCP)** and WordPress coding standards before every release.

## 10. Readme positioning (draft)

> **Know which plugins are dead before they become a security problem.**
> Shows which installed plugins and themes have been **closed on WordPress.org, and why** (e.g. "Security Issue"), which haven't been updated in years, and which can't be checked. No false alarms, no slow admin pages, no ads, no account. Works alongside your security plugin.

Make clear it is **not** a vulnerability scanner and works alongside Wordfence, Patchstack or WPVulnerability.

## 11. Open decisions for you

1. **Standalone plugin or a module** in the "environment badge + staging guard" plugin? Standalone means a clearer name and better search ranking. A module makes the bigger plugin more valuable.
2. **Include themes in 1.0** or wait for 1.1?
3. **Default thresholds:** 12/24 months (in line with Wordfence's 2 years) or stricter?
4. **Vulnerability data later:** never (stay focused), or as an optional integration?
5. **Pro version** someday (multi-site dashboard, scheduled PDF reports, Slack), or keep it free for reputation?

---

### Sources

- Code read directly: Vendi Abandoned Plugin Check 5.0.4, Plugins Last Updated Column, Outdated Plugin Notifier, k-OutDated Checker, WPVulnerability 5.1.6 (downloaded from downloads.wordpress.org).
- Reviews and support feeds on wordpress.org/support/plugin/<slug>/.
- API behaviour tested directly against api.wordpress.org (plugins and themes info 1.2).
- Wordfence abandoned/removed plugin alerts: [Wordfence blog: Abandoned and Removed Plugin Alerts (2017)](https://www.wordfence.com/blog/2017/06/abandoned-removed-plugin-alerts/), [WordPress.org support: Plugin flagged by Wordfence scan](https://wordpress.org/support/topic/plugin-flagged-by-wordfence-scan/), [WordPress.org support: Plugin has been removed](https://wordpress.org/support/topic/plugin-has-been-removed/), [the215guys: Wordfence "plugin removed" explained](https://www.the215guys.com/blog/wordfence-plugin-removed-from-wordpress-org-should-i-remove-it/).
