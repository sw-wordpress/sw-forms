# Hooks

Public API stable from 0.4.0; see [deprecation policy](extending.md#stable-api-and-deprecation-policy-040).

All hooks use the prefix `swf_`. Unless stated otherwise exceptions of callbacks are not caught.

## Actions

| Hook | Arguments | When | Since |
|------|-----------|------| --- |
| `swf_register_spam_providers` | `ProviderRegistry $registry` | Once when spam providers are built; register a `Spam\ProviderInterface` implementation. | 0.3.0 |
| `swf_register_field_types` | `FieldRegistry $registry` | Once, when the registry is built. Call `$registry->register( new My_Type() )`. Invalid slugs are ignored, an existing slug is replaced. | 0.1.0 |
| `swf_form_created` | `int $form_id` | After a form was created. | 0.1.0 |
| `swf_form_saved` | `int $form_id, int $version, array $document` | After a form was validated and published. | 0.1.0 |
| `swf_form_deleted` | `int $form_id` | After a form and all its entries were deleted. | 0.1.0 |
| `swf_entry_created` | `int $entry_id, Submission $submission` | After an entry was stored, before actions run. Also fired for temporary webhook entries when storage is off; inspect `meta.temporary` or the document storage setting. | 0.1.0 |
| `swf_after_actions` | `Submission $submission` | After all actions (e-mails, add-on actions) ran. | 0.1.0 |
| `swf_webhook_sent` | `int $delivery_id, array $delivery` | After a 2xx delivery; metadata only (webhookId, eventId, status, attempts, httpCode, durationMs, message, nextAttemptAt), no request or secrets. | 0.2.0 |
| `swf_webhook_failed` | `int $delivery_id, array $delivery` | After the last unsuccessful attempt, with the same metadata contract. | 0.2.0 |
| `swf_register_validators` | `ValidatorRegistry $registry` | Once on first custom field validation; register `Validation\ValidatorInterface`. Active, non-empty fields only. | 0.4.0 |
| `swf_register_merge_tags` | `MergeTagRegistry $registry` | Once on first merge tag lookup; register `Template\MergeTagInterface`. | 0.4.0 |

## Filters

| Hook | Value / arguments | Purpose | Since |
|------|-------------------|---------| --- |
| `swf_register_actions` | `list<ActionInterface> $actions, Submission $submission` | Add actions that run after a valid submission. Exceptions of an action are caught and logged on the entry. | 0.1.0 |
| `swf_validation_errors` | `array<string,string> $errors, array $values, int $form_id` | Add `path => message` (path `id` or `repeater[0].id`) to reject a submission. | 0.1.0 |
| `swf_submission_data` | `array $data, array $values, int $form_id` | Change the data (keyed by field key) before it is stored and sent. | 0.1.0 |
| `swf_webhook_payload` | `array $payload, array $webhook, Submission $submission` | Prepared webhook envelope after mapping, before serialization/signing. Webhook settings exclude the signing secret. Used once before freezing a queued request, and for synthetic test requests. | 0.2.0 |
| `swf_email_args` | `array{to, subject, message, headers} $args, array $notification, Submission $submission` | Change a notification e-mail. | 0.1.0 |
| `swf_merge_tag` | `?string $value, string $tag, string $argument, Submission $submission` | Resolve custom merge tags (`null` = unknown, tag stays). | 0.1.0 |
| `swf_element_markup` | `string $html, array $element, RenderContext $context` | Change the HTML of one element. | 0.1.0 |
| `swf_captcha_allowed_hostnames` | `list<string> $hosts, string $provider` | Hostnames accepted by CAPTCHA verification; defaults to the lowercase home URL hostname. | 0.3.0 |
| `swf_client_ip` | `string $ip` | IP for rate limiting / hashing. Return the forwarded address behind a trusted proxy. | 0.1.0 |
| `swf_webhook_allow_private_url` | `bool $allow, string $url` | Default false. Grant a trusted, exact URL exception for private destinations/nonstandard ports. HTTP(S) syntax restrictions remain. Shared transport for queued, test and resent requests. Never return true indiscriminately. | 0.2.0 |

The `wpa_handle_spammers` action belongs to WP Armour, not SW Forms; see its [integration notes](extending.md#wp-armour-free-and-extended).

## Public PHP functions

All three functions were introduced in 0.1.0. `swf_submit()` is a trusted server-side API:
do not expose it directly to anonymous callers without your own authorization/spam checks.

* `swf_render_form( int $form_id, bool $title = false ): string`
* `swf_get_form( int $form_id ): ?array` – published document
* `swf_submit( int $form_id, array $values ): SubmissionResult` – full pipeline without spam checks
