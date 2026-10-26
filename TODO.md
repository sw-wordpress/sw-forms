# SW Forms – TODO / Milestones

Konzept: `/home/swarnat/.claude/plans/ich-m-chte-ein-plugin-parallel-glacier.md` · Regeln & Checks: `AGENTS.md`

## ✅ Milestone 1 – MVP (erledigt, 0.1.0)

- Core (Schema-Validierung, Logik-Evaluator PHP + TS mit gemeinsamen Fixtures, DataBuilder, Merge-Tags, Submission-Token)
- Feldtypen: Input, Textarea, Checkbox, Radio, Select, Gruppe/Repeater, Spalten
- Builder (Vue 3 + Quasar): Drag & Drop, Spalten-Resize, Inspector, Logik-Editor, Undo/Redo, Autosave, Vorschau
- Einträge (Liste, Filter, Suche, Bulk, Detail mit Log), E-Mail-Benachrichtigungen, Bestätigung
- Spamschutz ohne Nonce (Token, Honeypot, Rate-Limit), No-JS-Postback, Barrierefreiheit, de_DE

## ✅ Milestone 2 – Webhooks (0.2.0)

Doku: `docs/webhooks.md`. Tests: `tests/Unit/WebhookTest.php`, `tests/wp-webhooks.php`, `apps/admin/scripts/e2e/webhooks.mjs`.

- [x] Action Scheduler per Composer gebündelt (nicht präfixiert, Release unter `libraries/`), Queue-Wrapper (`swf`-Gruppe, WP-Cron-Fallback)
- [x] Webhook-Einstellungen pro Formular: URL, Methode (POST/PUT/PATCH), Format (JSON / form-encoded), Header (auch geheime), Feld-Mapping mit Zielnamen (Standard: alle Felder), Umschlag abschaltbar, Bedingung
- [x] Payload `{event, eventId, form, entry, data}`; Filter `swf_webhook_payload`
- [x] Signatur `X-SWForms-Signature: t=…,v1=…` (HMAC-SHA256 über `t.body`), Secrets verschlüsselt, write-only (nie in Dokument/Export/REST)
- [x] Asynchrone Zustellung, Retries 1 min / 5 min / 30 min / 2 h / 12 h, Timeout, keine Redirects
- [x] SSRF-Schutz: http(s), alle aufgelösten IPs öffentlich, DNS-Pinning; Ausnahmen über Einstellung „Erlaubte interne Hosts“ + Filter. Eigene Prüfung statt `wp_safe_remote_request` (blockiert Ports außer 80/443/8080 und deckt IPv6/Metadaten-IPs nicht ab)
- [x] Zustellungslog am Eintrag + Tabelle `swf_webhook_deliveries`, „Erneut senden“ (Eintrag, Webhooks-Tab, alle fehlgeschlagenen), „Als erledigt markieren“
- [x] Endgültiger Fehlschlag: Alarm-Mail (gedrosselt, Adresse einstellbar) + Badge in der Formularliste; Hooks `swf_webhook_sent` / `swf_webhook_failed`
- [x] Speicherung aus: Payload nur in der Zustellung, nach Erfolg/letztem Retry gelöscht (statt temporärem Eintrag)
- [x] Builder-Tab „Webhooks“ inkl. Test-Button mit Beispieldaten und letzten Zustellungen
- [x] Tests: Signatur/Retry/SSRF/Mapping (Unit), Queue/Retry/Resend/SSRF (Integration gegen lokalen Echo-Endpoint), E2E im Browser

## Milestone 3 – Komfort & Export (0.3.0)

- [ ] CSV-Export der Einträge (Filter/Suche berücksichtigen, Formel-Injection-Schutz, Repeater flach oder als JSON)
- [ ] Weitere Merge-Tags (`{entry:ip}` nur bei Hash-Modus nein, `{form:url}`, `{field:key:raw}` für Rohwerte)
- [ ] Merge-Tags an Cursorposition einfügen statt anhängen
- [ ] Captcha-Provider-Interface + Turnstile / hCaptcha (`swf_register_spam_providers`)
- [x] Select mit Suchfeld (Combobox, barrierefrei) – Issue #2
- [ ] Bedingte Pflichtfelder / Deaktivieren (Logik-Aktionen `require`, `disable`)
- [ ] Datenschutz: WP-Privacy-Exporter/-Eraser für Einträge (per E-Mail-Feld)
- [ ] Einträge bearbeiten (Admin), Notizen am Eintrag

## Milestone 4 – Plugin-API festschreiben (0.4.0)

- [ ] Interfaces & Hooks als stabil markieren, `@since` pflegen, Deprecation-Policy in `docs/extending.md`
- [ ] Registries vervollständigen: `swf_register_validators`, `swf_register_merge_tags` (statt nur Filter)
- [ ] Settings-Schema: Typen `field-picker`, `key-value`, `repeater`; eigene Vue-Komponente per JS-Handle für Add-on-Inspector/Canvas
- [ ] Beispiel-Add-on (eigener Feldtyp + eigene Aktion) als Testfall der API
- [ ] PHPUnit-Integrationssuite mit WP-Testumgebung (statt nur `tests/wp-smoke.php`)

## Milestone 5 – Ausbau (0.5.0+)

- [ ] Gutenberg-Block (Formularauswahl, Live-Vorschau per ServerSideRender)
- [ ] Import/Export von Formularen (JSON, ohne Secrets)
- [ ] Mehrschritt-Formulare (Seitenumbruch-Element, Fortschritt, Validierung pro Schritt)
- [ ] Datei-Upload (geschütztes Verzeichnis, Typ-/Größenprüfung, Anhang in Mails)
- [ ] Weitere Felder: Signatur, HTML/Text-Block, Berechnung (Datepicker, Rating: Issue #2)
- [ ] Vorlagenbibliothek (Kontakt, Anmeldung, Umfrage)
- [ ] Versionsverlauf im Builder (alte Version ansehen / wiederherstellen)

## Bekannte Punkte / technische Schulden

- [ ] Dasselbe Formular zweimal auf einer Seite → doppelte DOM-IDs (Instanz-Suffix einführen)
- [ ] WPML-Anbindung (`Support/Wpml.php`) ungetestet – mit aktivem WPML prüfen
- [ ] Mails im Test mit Mailpit statt `pre_wp_mail` prüfen (Darstellung der HTML-Mail)
- [ ] Admin-Bundle ~330 kB (118 kB gzip): Quasar-Komponenten gezielt importieren / Builder-Chunk weiter splitten
- [ ] Dark-Mode des Builders visuell prüfen
- [ ] CI für Pull Requests (composer check + npm lint/typecheck/test); Repository und Release-Workflow existieren
- [x] Release-Paket (`.github/workflows/main.yml`: Mozart, gebaute Assets, Action Scheduler in `libraries/`)
