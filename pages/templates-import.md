# Templates, import and history

## Start from a template

Open **SW Forms → Forms → Templates / import**. Search or filter templates, open one and inspect **Preview** and **Fields**. Preview uses the real form renderer and conditions but does not store or send your answers.

Click **Create form** to create a new inactive form. Review field keys, recipients, webhooks, uploads and privacy text, then save/publish and activate it. A template is a starting point: later template changes do not update your copy.

## Transfer an SW Forms form as JSON

Use **Export JSON** in the builder, then choose or drop the exported file under **Templates / import → Import JSON**. The import limit is 1 MB. Import creates a new inactive form rather than replacing a live one; review and save it before activation.

Export transfers the form definition, not submitted entries. Credentials are removed; webhook headers and destination URLs are cleared and webhooks are disabled. Password-field defaults are cleared. Reconfigure destination-specific delivery and review remaining content before sharing JSON: sanitization is not a guarantee that free text contains no personal information.

## Import from Contact Form 7 or WPForms

Open **SW Forms → Import**. The source plugin must be active on the same WordPress installation. Select the importer and source form, then review the **Import report**, including transferred content and skipped fields or tags. Click **Confirm import as inactive form** only after reviewing the report. Source forms remain unchanged.

Supported transfers include common text/email/URL/phone/number/textarea/hidden fields, static choices, labels, required flags and submit-button text; Contact Form 7 date/consent and WPForms name/GDPR consent are also supported.

Not transferred: submissions, notifications, webhooks, HTML/layout/styles/embeds, conditions, advanced validation, dynamic/default choice selection, smart tags, CAPTCHA and other plugin settings. Field keys are newly assigned. Rebuild these settings, replace the original embed yourself and test the whole submission path before switching visitors to the imported form. Importing does not migrate existing entry history.

## Published versions

Open **Published versions** to inspect an earlier saved definition. **Restore as draft** replaces the current draft; save any current changes first. Restoration does not immediately change the published form. Review keys, fields, privacy settings and outgoing delivery before clicking **Save** to publish it.

Entries remain associated with the version used when they were submitted. A form's published version number is not the plugin's release version. Restoring an old definition does not roll back previously delivered email or webhooks.
