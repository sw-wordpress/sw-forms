# Configuration

## Global settings

Open **SW Forms → Settings**, change the relevant controls and click **Save**. These apply across forms.

| Setting | Default and effect |
| --- | --- |
| **Privacy** | Store a keyed hash of the IP address, not the raw IP. Choose **Do not store anything about the IP address** to omit it from entries. Abuse protection still uses temporary keyed identifiers. |
| **Sender address** / **Sender name** | Empty: WordPress defaults or your mail plugin. Prefer a sender address on your own domain; configure and test SMTP separately if needed. |
| **Load the SW Forms stylesheet** | Enabled. Disable only if your theme supplies the required form styling. |
| **CAPTCHA** | Configure site/secret keys for Cloudflare Turnstile or hCaptcha, then enable the provider per form. Leave an existing secret empty to keep it. |
| **Delete all forms and entries when the plugin is deleted** | Disabled. This is destructive when enabled; see [data removal](./entries-privacy.md#retention-and-removal). |

The plugin has no configuration constants of its own in `wp-config.php`.

## Per-form settings

In the builder's **Settings** tab:

| Control | Default and meaning |
| --- | --- |
| **Submit button text** | `Submit`; change the visitor-facing label as needed. |
| **After submitting** | **Message**, initially “Thank you! Your message has been sent.” Alternatives are a WordPress **Page** or a **Web address**. |
| **Store entries** | Enabled. Decide whether submissions should appear in the inbox. Background webhooks may still require temporary storage when this is off. |
| **Delete entries automatically after** | `0` days means keep forever, not immediate deletion. Set the shortest suitable period; maximum 3650 days. |
| **Honeypot (invisible trap field)** | Enabled. Leave the invisible field empty. |
| **Minimum time to fill in** | 2 seconds; configurable from 0 to 60. Faster submissions are asked to try again. |
| **Max. submissions per visitor and 10 minutes** | 10; `0` disables the limit. Visitors sharing an IP can affect one another. |
| **CAPTCHA** | **None**. A configured provider is optional and loads an external script; JavaScript is required when enabled. |

Use **Insert placeholder** where offered, for example `{field:name}` in a confirmation. Redirect placeholders are URL-encoded; avoid putting personal answers in destination URLs because URLs can be logged or shared. Click **Save** to publish changes.

## Embedding and design

Use the **SW Forms** block or copy the shortcode from **Settings → Embed**. The ID must identify an active, published form. `title="yes"` displays the title.

```text
[sw_form id="3"]
```

For a PHP template (not a post's content):

```php
<?php echo swf_render_form( 3 ); ?>
```

**Settings → Design** offers **Classic**, **Floating labels**, **Minimal lines**, **Dark focus**, **Guided card**, **Animated float** and **Surprise motion**. Choose a colour scheme (**Light**, **Dark**, **Follow system**); Dark focus is always dark. **Own colours** overrides the variant's colours; empty values retain defaults. Check contrast warnings and the preview with your site theme. Changing the design does not change the form's fields or data.

The design gallery and per-embed design overrides are described in the [design reference](https://github.com/sw-wordpress/sw-forms/blob/master/docs/schema.md#design-060).

## Integrations and scheduled work

- **WPML:** visitor-facing form texts can be translated through WPML String Translation.
- **WPBakery Page Builder:** select a form in the **SW Forms** element.
- **WP Armour:** its honeypot check is applied when the plugin is active.
- **Cloudflare Turnstile / hCaptcha:** review the external provider's privacy implications and update your site's privacy information before enabling.

WP-Cron removes expired entries daily and checks stuck webhook work every five minutes. Action Scheduler processes webhook delivery through WP-Cron. Low-traffic sites and sites using `DISABLE_WP_CRON` need a working server cron. Ask the site operator to schedule, for example:

```text
*/5 * * * * wp cron event run --due-now --path=/path/to/wordpress
```

Replace the path and ensure the job runs as the appropriate site user with WP-CLI installed. Retention and retry timing depend on scheduled work actually running.
