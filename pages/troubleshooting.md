# Troubleshooting

Start with a synthetic test on a disposable/staging site where possible. Do not send private entry content, credentials or signing secrets with a support report.

## The form is missing or changes are not visible

1. Open **SW Forms → Forms** and verify the form exists and is **Active**.
2. Open it and click **Save**. **Draft stored, not published yet** is not publication.
3. Check the ID in the block or shortcode. A deleted form cannot be rendered.
4. Clear relevant page caches and check the published page, not only the editor or builder canvas.
5. If WordPress reports a missing vendor directory or unbuilt admin interface, install the built release ZIP rather than the source archive.

## No access to the administration interface

Try with an administrator account. SW Forms grants its capabilities to administrators on activation. Other roles need appropriate permissions assigned by the site administrator; do not share administrator credentials. Fine-grained permissions are described in the [REST API reference](https://github.com/sw-wordpress/sw-forms/blob/master/docs/rest-api.md).

## A submission is rejected or seems to disappear

- **Please correct the marked fields:** inspect the error summary and required/conditional fields. Test both visible and hidden paths.
- **That was very fast:** wait for the configured minimum fill-in time and submit again.
- **The page was open for a long time** or verification errors: reload the page for a fresh submission token; repeated failures warrant checking caching and blocked frontend requests.
- **This form has already been submitted:** check the inbox before trying again; submission tokens are single-use.
- **Too many submissions:** wait before retrying; review the form's 10-minute rate limit. Shared IPs can affect multiple visitors.
- CAPTCHA failure: check provider keys, permitted domain, selected per-form provider and blocked scripts; CAPTCHA requires JavaScript.
- Confirmation with no entry: ensure **Store entries** is enabled. Honeypot/WP Armour spam checks can deliberately return a normal confirmation without storing or sending anything. Do not disable protection globally merely to make one test pass.

Preview never stores or sends data. Use the published page to test the inbox and delivery.

## Upload failure

Check the field's allowed extensions, file count and maximum size, WordPress MIME restrictions, PHP upload/post-size limits and uploads-directory permissions. Empty extensions blocks all uploads. Upload fields require entry storage. After a reload, select the file again. Public upload URLs are not access control; see [upload privacy](./entries-privacy.md#uploads-and-media).

## Email is missing

Check **To**, **Active** and notification conditions; confirm placeholder keys match the actual field keys. Inspect the entry's **Actions** log, the destination spam folder and your mail/SMTP logs. Configure a **Sender address** on your own domain under global **Settings**. A successful submission or accepted mail call does not establish delivery to the mailbox.

## Webhooks are delayed or failing

Check **Enable webhook**, the public destination URL, method, format, mapping and the receiver's response. **Send test** sends synthetic data immediately; normal submissions are queued. Inspect **Webhook deliveries**, **Attempts** and **Next attempt** for the entry. Check Action Scheduler and WP-Cron with the site operator, especially on low-traffic sites. Private/reserved addresses are blocked by default.

After fixing the cause, **Send again** queues a delivery and can create a duplicate at the receiver. See [delivery and retries](./notifications-webhooks.md#delivery-and-retries).

## Retention does not remove entries

Verify the per-form retention is greater than zero and WP-Cron is running. Cleanup is daily, not immediate. Copies in mailboxes, CSV downloads, external systems and backups need separate cleanup.

## Reporting an issue

Check [existing issues](https://github.com/sw-wordpress/sw-forms/issues), then include plugin, WordPress and PHP versions, relevant setting names, the expected result and reproducible steps with synthetic data. Redact recipients, entry content, private URLs and secrets from logs or screenshots. For source builds and test infrastructure, use [testing documentation](https://github.com/sw-wordpress/sw-forms/blob/master/docs/testing.md).
