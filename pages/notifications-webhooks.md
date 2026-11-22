# Notifications and webhooks

Open a form's **Notifications** tab to configure email notifications and webhooks. Save the form to publish changes. Neither delivery mechanism replaces checking the [entry inbox](./entries-privacy.md).

## Email notifications

1. Review the initial **Admin notification**, which is active by default and sends to `{admin_email}` with subject `New entry: {form:title}` and message `{all_fields}`.
2. Click **Add notification** for another recipient or acknowledgement. Give it a **Name (internal)** and enable **Active**.
3. Enter **To**; separate multiple addresses with commas. `{field:email}` sends to the value of a field whose data key is `email`.
4. Enter **Subject** and **Message**. Use **Insert placeholder** to select actual fields or general placeholders. `{all_fields}` inserts a table of fields.
5. Set **Reply to** to the visitor's email field if needed. **Sender name** can be set here; the sender address is configured globally in **SW Forms → Settings**. Do not use the visitor's address as your site's sender.
6. Optionally enable **Only send when conditions are met**. **Attach uploaded files (maximum 20 MB total)** attaches stored uploads; consider mailbox limits and the sensitivity of files.
7. Save and submit the published page with synthetic data. Check the actual mailbox and the entry's **Actions** log. Preview is not a delivery test.

Up to 20 notifications can be configured per form. Delivery relies on WordPress mail and the site's transport configuration. An accepted mail call does not establish inbox delivery. Restrict acknowledgements and sensitive data to appropriate recipients.

## Webhooks

1. Under **Webhooks**, click **Add webhook**. New webhooks start disabled; defaults are **POST** and **JSON**.
2. Set a **Name** and **Destination URL**. Use HTTPS to protect data in transit. Supported methods are **POST** and **PUT**, with **JSON** or **form-encoded** payloads.
3. Set a **Signing secret** known to the receiver. If empty on creation, the plugin generates and stores a random secret encrypted. Leaving the control empty later keeps the stored secret; enter a replacement to rotate it. Never put secrets in documentation or screenshots.
4. Add required **Headers**. Under **Field mapping**, map fields to **Receiver key** values containing letters, digits and underscores. With no mapping, all visible fields are sent. Select a repeatable group as a whole list.
5. Optionally enable **Only send when these conditions match**. Click **Send test** to send an immediate request with synthetic data and inspect the HTTP status and timing. This is a real request to the configured destination, even before normal delivery is enabled; use a test endpoint you control.
6. Enable **Enable webhook**, save and send a real test submission from the published page to verify queued delivery.

Up to 20 webhooks can be configured. Destinations must resolve to public addresses on ports 80 or 443 by default; private/reserved destinations and URL credentials are blocked. Do not weaken this protection to solve a delivery issue. A developer can consult the [documented hooks](https://github.com/sw-wordpress/sw-forms/blob/master/docs/hooks.md) for a narrowly scoped trusted integration.

The receiver must verify the signature and handle retries safely. The [webhook/API reference](https://github.com/sw-wordpress/sw-forms/blob/master/docs/rest-api.md) and [schema](https://github.com/sw-wordpress/sw-forms/blob/master/docs/schema.md) describe the technical contracts.

## Delivery and retries

Normal webhooks run in the background using Action Scheduler. Failed deliveries have at most six attempts, with retry delays of 60, 300, 1800, 7200 and 43200 seconds; actual execution depends on cron availability.

Open an entry to inspect **Webhook deliveries**, status, **Attempts** and **Next attempt**. After fixing the destination, **Send again** queues another delivery; the receiver may receive data more than once. Check its records before manually resending.

Turning off **Store entries** does not mean no local processing data: eligible queued webhooks use temporary entries until delivery finishes. See [privacy](./entries-privacy.md) and [background tasks](./configuration.md#integrations-and-scheduled-work).
