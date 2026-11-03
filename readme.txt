=== Fadewatch – Abandoned & Closed Add-on Checker ===
Contributors: curiouswpdev
Tags: abandoned plugins, plugin maintenance, plugins, site health, security
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Find out which of your plugins are closed on WordPress.org (and why), which haven't been updated in years, and which can't be checked.

== Description ==

**Know which plugins are dead before they become a security problem.**

Fadewatch shows the maintenance status of every installed plugin, right on the Plugins screen:

* **Closed** on WordPress.org, with the **date and reason** (for example "Closed on 27 Jul 2023: Security Issue").
* **Possibly abandoned**: no update in over 24 months (configurable).
* **Ageing**: no update in 12–24 months (configurable).
* **Maintained**: updated recently.
* **Not on WordPress.org**: premium or custom plugins, and plugins that get their updates elsewhere (Update URI header).
* **Couldn't check**: WordPress.org could not be reached. This is never reported as "closed" or "not found".

= Built for accuracy =

* A failed request never overwrites an earlier good result.
* "Closed", "not found" and "couldn't check" are kept strictly apart.
* If a custom plugin happens to use the same folder name as an unrelated WordPress.org plugin, its data is marked "Not verified" instead of showing the wrong plugin's status.

= Lightweight =

All lookups run in the background (WP-Cron), in small batches. Opening the Plugins screen reads one stored option and makes no external requests. The plugin loads no code on the front end of your site.

= Features =

* "Maintenance" column on the Plugins screen, sortable, with filter links such as "Closed (2) | Possibly abandoned (5)".
* Report page (Tools → Plugin Maintenance) with a summary, "Check now" button, and CSV export for client reports.
* Two Site Health tests: closed plugins (critical when closed for a security issue) and plugins without updates for a long time.
* E-mail only when something changes. A plugin closed for a security issue triggers an immediate e-mail. Optional weekly summary.
* Closed plugins and failed checks are re-checked every 6 hours, so a reopened plugin is noticed quickly.
* Plugins are re-checked right after they are installed, updated or activated.
* Works when WP-Cron is disabled: "Check now" runs the batches from your browser.
* Multisite: one shared check for the whole network, in Network Admin → Plugins.
* Asks first: nothing is sent to WordPress.org until an administrator allows checks, and checks can be turned off at any time.
* No ads, no account, no tracking.

= Not a vulnerability scanner =

This plugin checks **maintenance status**, not known vulnerabilities. It can be used alongside any security plugin.

= For developers =

Filters:

* `fadewatch_plugin_slug` – map a plugin file to a different WordPress.org slug.
* `fadewatch_skip_plugin` – exclude a plugin from checks.
* `fadewatch_thresholds` – change the "ageing" and "possibly abandoned" thresholds (in months).
* `fadewatch_status` – override the computed status.
* `fadewatch_email_recipients` – change notification recipients.

Actions:

* `fadewatch_status_changed( $plugin_file, $old_status, $new_status, $evaluation )`
* `fadewatch_scan_started( $source )`
* `fadewatch_scan_completed( $results )`

== External services ==

This plugin connects to the **WordPress.org Plugins API** (`https://api.wordpress.org/plugins/info/1.2/`) to look up the maintenance status of installed plugins: last update date, whether the plugin was closed and why, "tested up to" version, rating and active installs.

* **What is sent:** only the slug (folder name) of each installed plugin, one request per plugin. The request's user agent is `Fadewatch/<version>` and does not include your site address.
* **Only after consent:** nothing is sent until an administrator clicks "Allow checks and scan now" on the Plugin Maintenance page (or enables "WordPress.org checks" in the settings). Turning that setting off stops all requests.
* **When:** after consent, once a day in the background, every 6 hours for closed plugins and failed checks, right after a plugin is installed, updated or activated, and when an administrator clicks "Check now".
* No personal data about your visitors or users is sent.

This is the same service WordPress itself uses to check for plugin updates. It is provided by WordPress.org: [Privacy Policy](https://wordpress.org/about/privacy/).

== Installation ==

1. Install the plugin from Plugins → Add New, or upload the folder to `/wp-content/plugins/`.
2. Activate it. On multisite, network-activate it.
3. Go to Tools → Plugin Maintenance (Network Admin → Plugins → Plugin Maintenance on multisite) and click "Allow checks and scan now".
4. Results appear in the new "Maintenance" column on the Plugins screen and on the Plugin Maintenance page.

== Frequently Asked Questions ==

= Why does Fadewatch ask for permission first? =

Checking a plugin means sending its slug to api.wordpress.org. Fadewatch only does that after an administrator agrees. You can withdraw consent at any time under Settings → "WordPress.org checks"; Fadewatch then makes no external requests.

= Why does a plugin show "Not on WordPress.org"? =

The plugin was not found on WordPress.org. It is usually a premium plugin, a custom plugin, or a plugin whose Update URI header says it gets updates from somewhere else. Its maintenance status can't be checked.

= What does "Not verified" mean? =

A plugin on WordPress.org uses the same slug (folder name) as your installed plugin, but its name and author look different. To avoid a false alarm, its data is not used. Developers can map the plugin to the correct slug with the `fadewatch_plugin_slug` filter.

= What does "Couldn't check" mean? =

WordPress.org could not be reached, or returned an unexpected response. The check is retried automatically. If an earlier check succeeded, that result is still shown, marked as out of date once it is older than 7 days.

= Does it slow down my site? =

No. Nothing runs on the front end. In the admin, the Plugins screen reads one stored option. All requests to WordPress.org happen in the background.

= What happens to my data when I delete Fadewatch? =

Scheduled checks are always removed. If "Delete all data of this plugin when it is deleted" is ticked in the settings (the default), all of Fadewatch's settings and previous scan results are removed too. If you untick it, your settings and results are kept for a later reinstall.

= Will it delete or deactivate plugins? =

No. It only reports. You decide what to do.

= Why did I not get an e-mail after the first check? =

The first check sets the baseline: its results are shown in the report and in Site Health. E-mails are sent when something changes after that.

= My site has WP-Cron disabled. =

Automatic checks then depend on your server's real cron job calling `wp-cron.php`. You can always click "Check now" on the report page; it runs the check from your browser.

== Screenshots ==

1. The Maintenance column and filter links on the Plugins screen.
2. The report page with summary, "Check now" and CSV export.
3. Site Health test for a plugin closed because of a security issue.

== Changelog ==

= 1.0.2 =
* Changed: the permission dialog opens by itself only once, right after activation. After that it opens only when you click "Allow checks".
* New: while checks are off, the Plugin Maintenance page explains what the checks do, and the Maintenance column on the Plugins screen offers "Allow checks".
* New: the permission dialog says where checks can be turned off again.

= 1.0.1 =
* New: opt-in. Fadewatch asks an administrator before contacting WordPress.org, and checks can be turned off in the settings.
* New: right after activation, the permission prompt is shown once on the Plugins screen.

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.0.2 =
The permission dialog no longer opens by itself except once after activation.

= 1.0.1 =
Fadewatch now asks for permission before contacting WordPress.org. Open Tools → Plugin Maintenance and click "Allow checks and scan now".

= 1.0.0 =
First release.
