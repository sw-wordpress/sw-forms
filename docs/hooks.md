# Hooks

All hooks use the prefix `swf_`. Unless stated otherwise exceptions of callbacks are not caught.

## Actions

| Hook | Arguments | When |
|------|-----------|------|
| `swf_register_field_types` | `FieldRegistry $registry` | Once, when the registry is built. Call `$registry->register( new My_Type() )`. Invalid slugs are ignored, an existing slug is replaced. |
| `swf_form_created` | `int $form_id` | After a form was created. |
| `swf_form_saved` | `int $form_id, int $version, array $document` | After a form was validated and published. |
| `swf_form_deleted` | `int $form_id` | After a form and all its entries were deleted. |
| `swf_entry_created` | `int $entry_id, Submission $submission` | After an entry was stored, before actions run. Not fired when storing is switched off. |
| `swf_after_actions` | `Submission $submission` | After all actions (e-mails, add-on actions) ran. |

## Filters

| Hook | Value / arguments | Purpose |
|------|-------------------|---------|
| `swf_register_actions` | `list<ActionInterface> $actions, Submission $submission` | Add actions that run after a valid submission. Exceptions of an action are caught and logged on the entry. |
| `swf_validation_errors` | `array<string,string> $errors, array $values, int $form_id` | Add `path => message` (path `id` or `repeater[0].id`) to reject a submission. |
| `swf_submission_data` | `array $data, array $values, int $form_id` | Change the data (keyed by field key) before it is stored and sent. |
| `swf_webhook_payload` | `array $payload, array $webhook, Submission $submission` | Prepared webhook envelope after mapping, before serialization/signing. Webhook settings exclude the signing secret. Used by `RequestBuilder`; automatic delivery is not connected yet. |
| `swf_email_args` | `array{to, subject, message, headers} $args, array $notification, Submission $submission` | Change a notification e-mail. |
| `swf_merge_tag` | `?string $value, string $tag, string $argument, Submission $submission` | Resolve custom merge tags (`null` = unknown, tag stays). |
| `swf_element_markup` | `string $html, array $element, RenderContext $context` | Change the HTML of one element. |
| `swf_client_ip` | `string $ip` | IP for rate limiting / hashing. Return the forwarded address behind a trusted proxy. |

## Public PHP functions

* `swf_render_form( int $form_id, bool $title = false ): string`
* `swf_get_form( int $form_id ): ?array` – published document
* `swf_submit( int $form_id, array $values ): SubmissionResult` – full pipeline without spam checks
