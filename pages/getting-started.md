# Getting started

SW Forms is for WordPress administrators creating forms and people managing their submissions. The administration interface is available in English and German; this guide uses its English labels.

## Requirements and installation

- WordPress 6.8 or newer; PHP 8.2 or newer with fileinfo, JSON and OpenSSL; MySQL or MariaDB.
- A WordPress administrator account for installation and initial configuration. Administrators receive the plugin's form, entry and settings permissions on activation.
- Working WordPress mail delivery if you want notifications, and WP-Cron for retention and background webhook work.
- No multisite-specific handling is provided; test your intended network setup separately.

1. Back up the site before installing or updating a plugin.
2. Download the built plugin ZIP from [GitHub Releases](https://github.com/sw-wordpress/sw-forms/releases), not GitHub's **Source code** archive. The built package contains the compiled interface and required libraries.
3. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**, select the release ZIP and install it.
4. Activate **SW Forms**. Open **SW Forms → Forms**.

For a source checkout, use the [development instructions](https://github.com/sw-wordpress/sw-forms#development) instead; installing source alone is not a complete plugin installation.

![SW Forms list with the synthetic Contact form and Job application examples](/screenshots/sw-forms-list.png)

*Forms list in the demonstration installation. Both sample forms have no collected entries.*

## Create and publish a contact form

1. Click **New form**, enter `Contact`, then **Create and open builder**.
2. In **Elements**, add an input for a name, an input with the email input type, and a textarea for the message. Click each field to set its **Label** and **Required field** in **Properties**. Set the email field's **Field name (data key)** to `email` for the example below.
3. Open **Notifications**. Review the default admin notification, especially **To**. Set a monitored recipient and use `{all_fields}` in **Message**. Set **Reply to** to `{field:email}` if replies should go to the visitor.
4. Under **Settings**, keep **Store entries** enabled for this test. Review the confirmation message and choose an appropriate retention period.
5. Open **Preview** and submit synthetic values to check validation. Preview validates only: it stores nothing and sends no emails or webhooks.
6. Click **Save** to validate and publish. Draft autosave is not publication. Ensure the form is **Active** in the forms list.
7. Add an **SW Forms** block to a WordPress page and select the form, or paste its shortcode from **Settings → Embed** into a Shortcode block. Publish the page.

```text
[sw_form id="3" title="yes"]
```

Replace `3` with your actual form ID. See [embedding and design](./configuration.md#embedding-and-design) for other options.

![Empty contact form rendered on the WordPress demonstration frontend](/screenshots/sw-forms-frontend.png)

*Frontend example with empty inputs. Replace the example privacy notice with your own information before collecting real data.*

## Verify the first real submission

Open the published page as a visitor, wait at least the configured minimum fill-in time (2 seconds by default), and send synthetic test data. Confirm all three independently:

- The expected confirmation is displayed.
- The entry appears under **Entries**, or **Show entries** in the forms list.
- The configured recipient receives the email. A successful form submission does not prove email delivery.

Remove test entries when finished. Configure [privacy and retention](./entries-privacy.md) before collecting real personal data. If something fails, use [Troubleshooting](./troubleshooting.md).
