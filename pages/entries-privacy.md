# Entries and privacy

## Read and process entries

Open a form's **Entries** tab or click **Show entries** in **SW Forms → Forms**. Use **Inbox**, **Unread**, **Starred**, **Spam** and **Trash**, or **Search entries** to find submissions. Select entries for bulk status actions, or open an entry to inspect its answers, **Details**, original **Form version** and **Actions** log.

Use **Star**, **Mark as read**, **Mark as unread**, **Spam**, **Trash** or **Restore** as appropriate. **Delete permanently** cannot be undone. Deleting an entire form also deletes its entries and published versions and makes embeds show nothing; export or back up needed data first.

**Edit values** uses the entry's original form version. Uploaded files and repeater row counts are preserved. Editing does not replay notifications or webhooks. Use **Internal note** / **Add note** for processing notes; these notes can be included in WordPress personal-data exports, so avoid unnecessary sensitive information.

Use **Export CSV** and the available **From** / **To** date controls to export entries. Store exports securely, restrict access and delete them when no longer needed. A downloaded CSV is a separate copy, not covered by the plugin's retention setting.

## Uploads and media

Uploads require **Store entries**. File upload defaults are a single file, a 10 MB per-file limit and extensions `pdf,jpg,jpeg,png,txt`; WordPress and server restrictions also apply. When **Allow multiple files** is enabled, the default maximum is five. Configure **Allowed file extensions**, **Maximum size per file (MB)** and **Maximum files** in the field's properties. Empty allowed extensions means no uploads are allowed.

::: warning Public file URLs
Files are stored under the WordPress uploads directory in `swforms`, using random filenames and public, unguessable URLs. They are not protected private downloads. Anyone who obtains a URL may access it. Do not use this as a confidential document portal.
:::

Uploaded files are removed when their owning entry is permanently deleted. Moving an entry to Trash alone is not permanent deletion. Browser reloads require visitors to select files again. Media capture/enhancements depend on browser support and permissions; test on the devices visitors use. Mail attachments create further copies outside the site.

## Storage and personal data

In **SW Forms → Settings → Privacy**, choose a keyed IP hash (default) or no stored IP information. Raw IP addresses are never stored by the plugin's entry IP setting; temporary keyed rate-limit identifiers still exist. This setting does not control your server logs or external services.

Decide what to collect and disclose the purpose, retention and recipients in your own privacy information. Optional CAPTCHA loads a third-party script. Email providers and webhook recipients receive configured submission data, and their copies must be handled separately. A hidden or password field is not an encrypted data vault.

WordPress **Tools → Export Personal Data** and **Erase Personal Data** include matching SW Forms entries, identified by email input values. Entries without a matching email field are not discoverable by that mechanism; locate and handle them manually where appropriate.

Disabling **Store entries** removes normal inbox storage, but background webhooks can retain temporary entries while delivery completes. Avoid equating this switch with zero data storage.

## Retention and removal

In each form's **Settings → Entries**, set **Delete entries automatically after**. The default `0` keeps entries forever; choose a suitable positive number of days. Cleanup runs daily through WP-Cron, not exactly at an entry's expiry time. Verify [scheduled work](./configuration.md#integrations-and-scheduled-work) is running.

Deactivation keeps forms, entries and settings and stops scheduled work. Deleting the plugin removes forms, entries, uploaded files, tables, plugin capabilities and settings only when **Delete all forms and entries when the plugin is deleted** was enabled beforehand. Back up or export what you need before enabling it. External deliveries, exports and backups are not erased by plugin deletion.
