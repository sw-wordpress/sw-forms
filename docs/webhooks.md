# Webhooks

A form can send every valid submission to other systems (CRM, ERP, automation tools). Webhooks are
configured per form in the builder tab **Webhooks** and stored in the form document
(`settings.webhooks`, see `schema.md`). Secrets are never part of the document.

## Delivery

1. After a valid submission each enabled webhook whose condition is met gets a **delivery**
   (table `swf_webhook_deliveries`) with a fresh `eventId`. The payload is built at this moment.
2. The delivery is sent in the background by Action Scheduler (bundled, group `swf`; visible under
   Tools › Scheduled Actions). The visitor never waits for the receiver.
3. A `2xx` response is a success. Network errors, timeouts, `408`, `425`, `429` and `5xx` are
   retried after 1 min, 5 min, 30 min, 2 h and 12 h (6 attempts in total, filter
   `swf_webhook_retry_delays`). Other responses (`400`, `401`, `404`, redirects ...) fail at once:
   a retry would not fix a configuration problem. Redirects are never followed.
4. A delivery that failed for good is logged on the entry, fires `swf_webhook_failed`, sends an
   e-mail to the alert address (global setting, default: admin e-mail; at most one mail per
   webhook and hour) and shows a badge in the form list. Failed deliveries can be resent (same
   `eventId`, current webhook settings, e.g. a corrected URL) or dismissed in the Webhooks tab;
   single deliveries also in the entry view.

Retries and resends use the **current** settings of the webhook (URL, headers, secret) but the
payload stored with the delivery. If the webhook was removed or switched off, pending deliveries
end as `dismissed`.

With **"Store entries" switched off** the payload lives only in the delivery and is deleted once
the delivery is final (success or last failed attempt). Such deliveries cannot be resent.
Deleting an entry (manually or by the retention period) deletes its deliveries.

## Request

| | |
|---|---|
| Method | `POST` (default), `PUT` or `PATCH` |
| Body | JSON (`application/json`) or form encoded (`application/x-www-form-urlencoded`, nested values as `a[b]=…`) |
| `User-Agent` | `SW-Forms/<version> (+<site url>)` |
| `X-SWForms-Event` | `form.submitted`, test button: `webhook.test` |
| `X-SWForms-Event-Id` | UUID, identical for retries and resends: use it for idempotency |
| `X-SWForms-Delivery-Attempt` | 1 … 6 |
| `X-SWForms-Signature` | `t=<unix time>,v1=<hex>` if a signing secret is set |
| custom headers | e.g. `Authorization: Bearer …`; values marked secret are stored encrypted |

Timeout: 15 s (filter `swf_webhook_timeout`).

### Payload

With the envelope (default):

```json
{
  "event": "form.submitted",
  "eventId": "6b1f0c1e-3c39-4c1e-9c55-0a6f2b0b6b7e",
  "form": { "id": 3, "title": "Contact", "version": 7 },
  "entry": { "id": 128, "createdAt": "2026-10-26T18:00:00+00:00", "sourceUrl": "https://example.org/contact/" },
  "data": { "email": "max@example.org", "address": { "city": "Berlin" }, "people": [{ "name": "Anna" }] }
}
```

`entry.id` is `null` when entries are not stored. `data` has the data shape of `schema.md`
(keyed by field key, hidden fields omitted). Without the envelope only `data` is sent, e.g. for
web-to-lead endpoints that expect plain form fields.

**Field mapping** ("Selected fields"): pick fields (by element id, so renaming a key does not
break it) and name them for the receiver. Dots nest: `contact.email` → `{"contact":{"email":…}}`
or `contact[email]=…`. Mappable are fields, groups and whole repeaters outside repeaters.

The filter `swf_webhook_payload` changes the payload before it is queued.

## Signature

`v1` is the hex HMAC-SHA256 of `<t>.<raw body>` with the signing secret. Check it on the raw body
and reject old timestamps (replays):

```php
function swf_verify( string $header, string $secret, string $body, int $tolerance = 300 ): bool {
	parse_str( str_replace( ',', '&', $header ), $parts );
	if ( ! isset( $parts['t'], $parts['v1'] ) || abs( time() - (int) $parts['t'] ) > $tolerance ) {
		return false;
	}
	return hash_equals( hash_hmac( 'sha256', $parts['t'] . '.' . $body, $secret ), (string) $parts['v1'] );
}
// swf_verify( $_SERVER['HTTP_X_SWFORMS_SIGNATURE'] ?? '', $secret, file_get_contents( 'php://input' ) );
```

The reference implementation is `SWForms\Core\Webhook\Signature::verify()`.

## Secrets

The signing secret and secret header values are write-only: the builder sends them once
(`webhook.secret`, `webhook.clearSecret`, `headers[].value` of a header with `secret: true`), the
server stores them encrypted (libsodium, key from `SWF_ENCRYPTION_KEY` in `wp-config.php` or the
`AUTH_KEY` salt) in the option `swf_secrets_<form id>` and only reports `hasSecret` /
`hasValue`. They are not part of form versions, `swf_get_form()` or exports. Duplicating a form
copies them. If the salts change, secrets cannot be decrypted any more; deliveries then fail with
a message asking to enter them again.

## Protection against SSRF

Only `http`/`https` URLs without credentials. The host is resolved and **every** address must be
public; loopback, private (RFC 1918, ULA), link-local (incl. cloud metadata `169.254.169.254`),
CGNAT, multicast and reserved ranges are blocked, also inside IPv4-mapped IPv6. The connection is
pinned to the checked address (no DNS rebinding) and redirects are not followed.

Internal systems, such as an ERP in the local network, are allowed in the global settings under
**Allowed internal hosts** (host name, `*.domain`, IP address or CIDR range, one per line) or with
the filter `swf_webhook_url_allowed`.

## Operations

* Action Scheduler runs on WP-Cron. After a submission SW Forms triggers a cron run right away
  (unless `DISABLE_WP_CRON` is set, then the system cron decides the delay).
* The daily maintenance requeues pending deliveries whose job got lost (e.g. while the plugin was
  inactive) and removes finished deliveries without entry after 30 days.
* Uninstalling removes pending queue jobs; with "delete data" also deliveries and secrets.
