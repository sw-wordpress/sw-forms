# SW Forms

[![Release](https://img.shields.io/github/v/release/sw-wordpress/sw-forms?sort=semver)](https://github.com/sw-wordpress/sw-forms/releases)
[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=sw-wordpress_sw-forms&metric=coverage)](https://sonarcloud.io/summary/overall?id=sw-wordpress_sw-forms)
[![SonarQube Issues](https://sonarcloud.io/api/project_badges/measure?project=sw-wordpress_sw-forms&metric=vulnerabilities)](https://sonarcloud.io/project/issues?id=sw-wordpress_sw-forms&impactSoftwareQualities=SECURITY&resolved=false)

Build forms for your WordPress site with [drag and drop](https://en.wikipedia.org/wiki/Drag_and_drop).
Collect entries, send e-mail notifications and forward submissions to other systems via
[webhooks](https://en.wikipedia.org/wiki/Webhook).

## Features

- **Visual builder** — Columns, repeatable groups, multi-step forms, templates and version history.
- **Conditional logic** — Show, require or disable fields based on answers, checked again on the server.
- **Entries and notifications** — Inbox with search, editing and CSV export, plus e-mail notifications.
- **Signed webhooks** — Delivered in the background with retries and an [HMAC](https://en.wikipedia.org/wiki/HMAC) signature.
- **Spam protection without captcha** — Honeypot, minimum fill-in time and rate limit, also on cached pages.
- **Privacy-friendly** — IP addresses hashed or not stored; entries deleted automatically after a set number of days.

## Languages

English, German.

## Usage

1. Open **SW Forms → Forms**, click **New form**, enter a name and click **Create and open builder**
   (or start from a template under **Templates / import**).
2. Drag fields from **Elements** onto the form and adjust them under **Properties**.
3. Set up e-mails in the **Notifications** tab, then click **Save** to publish the form.
4. Embed it with the **SW Forms** block, the shortcode or PHP (shown under **Settings → Embed**):
   `[sw_form id="3" title="yes"]` or `<?php echo swf_render_form( 3 ); ?>`
5. Read submissions in the **Entries** tab or via **Show entries** in the form list.

## Configuration

### Requirements

PHP 8.2+ with fileinfo, JSON and OpenSSL, WordPress 6.4+, MySQL or MariaDB; no multisite-specific handling.

### Settings

Global settings are under **SW Forms → Settings** (IP address handling, sender address and name,
stylesheet, CAPTCHA keys, data removal). Per-form options are in the builder's **Settings** tab.

The plugin reads no `wp-config.php` constants of its own.

### Background tasks

WP-Cron deletes expired entries daily and checks every five minutes for stuck webhooks.
Webhooks are delivered by [Action Scheduler](https://actionscheduler.org/), which also runs via WP-Cron.
On sites with little traffic or with `DISABLE_WP_CRON`, run cron from the server, e.g.
`*/5 * * * * wp cron event run --due-now --path=/path/to/wordpress`.

### Integrations

- **WPML** — Visitor-facing form texts can be translated with WPML String Translation.
- **WPBakery Page Builder** — Adds an **SW Forms** element with form selection.
- **Cloudflare Turnstile / hCaptcha** — Optional CAPTCHA per form; loads the provider's script.
- **WP Armour** — Its honeypot check is applied to SW Forms submissions when active.

### Deactivation and removal

Deactivating keeps all forms, entries and settings and only stops scheduled tasks. Deleting the plugin
removes forms, entries, uploaded files, tables, capabilities and settings only if
**Delete all forms and entries when the plugin is deleted** is enabled under **SW Forms → Settings**.
Entries can be exported or erased with the WordPress privacy tools (matched by e-mail fields).

## Troubleshooting

| Problem | Solution |
|---|---|
| The form does not appear on the page. | Save the form, make sure it is **Active** and check the ID in the shortcode. |
| Notice that the admin interface has not been built or the vendor directory is missing. | Install the release ZIP instead of the source code (or build it, see Development). |
| Notification e-mails land in spam. | Set a **Sender address** of your own domain under **SW Forms → Settings**. |
| Webhooks arrive late. | Set up a server cron job (see Background tasks). |

## Built with AI

This plugin was implemented with AI assistance. Why it exists and how AI is used:
[sw-wordpress manifest](https://github.com/sw-wordpress/.github/blob/main/profile/README.md).

AI agents must follow the project rules before changing code:

- Rules and mandatory coverage: [AGENTS.md](AGENTS.md)
- Current status: [TODO.md](TODO.md), roadmap: [docs/roadmap.md](docs/roadmap.md)
- Form document, API and hooks: [docs/](docs/) ([schema.md](docs/schema.md), [extending.md](docs/extending.md), [hooks.md](docs/hooks.md), [rest-api.md](docs/rest-api.md))
- README structure: [README-STANDARD.md](https://github.com/sw-wordpress/sw-securelogin/blob/master/docs/README-STANDARD.md)

## Development

Requirements: PHP 8.2+ with Composer, Node.js 18.18+ with npm; Docker for the release dependency isolation.

```sh
composer install
(cd apps/admin && npm ci && npm run build)             # admin app and frontend runtime

composer check                                         # PHPCS (core + WordPress), PHPStan, PHPUnit
WP_ROOT=/path/to/test-wordpress composer test:wp       # integration tests, disposable WordPress only
(cd apps/admin && npm run lint && npm run typecheck && npm run test:coverage)
bin/i18n.sh update && bin/i18n.sh compile && bin/i18n.sh check   # PHP translations
```

Every change needs tests; line coverage must stay above 80 % for PHP and JavaScript/TypeScript.
Details: [docs/testing.md](docs/testing.md); release build and SonarQube: [docs/release.md](docs/release.md);
full feature list: [docs/features.md](docs/features.md). The GitHub release workflow builds the ZIP.

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html) — see [LICENSE](LICENSE). Provided by Stefan Warnat.

Bundled third-party components (license texts: Tom Select in `licenses/`, Action Scheduler in
`libraries/action-scheduler/` of the release build):

- [Action Scheduler](https://github.com/woocommerce/action-scheduler) — GPL-3.0-or-later
- [Tom Select](https://github.com/orchidjs/tom-select) — Apache-2.0
- [Vue](https://github.com/vuejs/core), [Vue Router](https://github.com/vuejs/router), [Pinia](https://github.com/vuejs/pinia) — MIT
- [Vue I18n](https://github.com/intlify/vue-i18n) — MIT
- [Quasar](https://github.com/quasarframework/quasar) — MIT
- [SortableJS](https://github.com/SortableJS/Sortable) — MIT
- [Material Icons](https://github.com/google/material-design-icons) (via @quasar/extras) — Apache-2.0
- [Inter](https://github.com/rsms/inter) font (via @fontsource-variable/inter) — OFL-1.1
