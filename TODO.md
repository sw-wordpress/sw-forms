# SW Forms – TODO / Milestones

Konzept: `/home/swarnat/.claude/plans/ich-m-chte-ein-plugin-parallel-glacier.md` · Regeln & Checks: `AGENTS.md`

## ✅ Milestone 1 – MVP (erledigt, 0.1.0)

- Core (Schema-Validierung, Logik-Evaluator PHP + TS mit gemeinsamen Fixtures, DataBuilder, Merge-Tags, Submission-Token)
- Feldtypen: Input, Textarea, Checkbox, Radio, Select, Gruppe/Repeater, Spalten
- Builder (Vue 3 + Quasar): Drag & Drop, Spalten-Resize, Inspector, Logik-Editor, Undo/Redo, Autosave, Vorschau
- Einträge (Liste, Filter, Suche, Bulk, Detail mit Log), E-Mail-Benachrichtigungen, Bestätigung
- Spamschutz ohne Nonce (Token, Honeypot, Rate-Limit), No-JS-Postback, Barrierefreiheit, de_DE

## ✅ Milestone 2 – Webhooks (implementiert, 0.2.0)

- [x] Action Scheduler per Composer bündeln, Queue-Wrapper (`swf_background` Gruppe; Release-Bootstrap und Queue-Integrationstests)
- [x] Webhook-Einstellungen pro Formular: URL, Methode (POST/PUT), Format (JSON / form-encoded), Header, Feld-Mapping (Standard: alle Felder), Bedingung (LogicEditor wiederverwenden)
- [x] Payload: `{event, eventId, form, entry, data}`; Filter `swf_webhook_payload`
- [x] Signatur `X-SWForms-Signature` (HMAC-SHA256, Secret pro Webhook, verschlüsselt gespeichert, nie im Export/REST im Klartext)
- [x] Asynchrone Zustellung, Retries mit Backoff (z. B. 1 min, 5 min, 30 min, 2 h, 12 h), Timeout
- [x] SSRF-Schutz: nur http(s), keine privaten/lokalen IPs (Filter für Ausnahmen), `wp_safe_remote_request`
- [x] Zustellungslog in `swf_entry_log` (Status, HTTP-Code, Dauer, gekürzte Antwort) + „Erneut senden“ im Eintrag
- [x] Endgültiger Fehlschlag: Admin-Mail + Badge in der Formularliste; Hooks `swf_webhook_sent` / `swf_webhook_failed`
- [x] Speicherung aus: Eintrag temporär halten, nach Zustellung/letztem Retry löschen
- [x] Builder-Tab „Webhooks“ inkl. Test-Button mit Beispieldaten
- [x] Tests: Signatur (Unit), Queue/Retry (Integration), E2E gegen lokalen Echo-Endpoint

## ✅ Milestone 3 – Komfort & Export (implementiert, 0.3.0)

- [x] CSV-Export der Einträge (Filter/Suche berücksichtigen, Formel-Injection-Schutz, Repeater flach oder als JSON)
- [x] Weitere Merge-Tags (`{entry:ip}` bleibt bei Hash-/Ohne-IP-Modus leer, `{form:url}`, `{field:key:raw}` für Rohwerte)
- [x] Merge-Tags an Cursorposition einfügen statt anhängen
- [x] Captcha-Provider-Interface + Turnstile / hCaptcha (`swf_register_spam_providers`)
- [x] Select mit Suchfeld (Combobox, barrierefrei)
- [x] Bedingte Pflichtfelder / Deaktivieren (Logik-Aktionen `require`, `disable`)
- [x] Datenschutz: WP-Privacy-Exporter/-Eraser für Einträge (per E-Mail-Feld)
- [x] Einträge bearbeiten (Admin), Notizen am Eintrag

## ✅ Milestone 4 – Plugin-API festschreiben (implementiert, 0.4.0)

- [x] Interfaces & Hooks als stabil markieren, `@since` pflegen, Deprecation-Policy in `docs/extending.md`
- [x] Registries vervollständigen: `swf_register_validators`, `swf_register_merge_tags` (statt nur Filter)
- [x] Settings-Schema: Typen `field-picker`, `key-value`, `repeater`; eigene Vue-Komponente per JS-Handle für Add-on-Inspector/Canvas
- [x] Beispiel-Add-on (eigener Feldtyp + eigene Aktion) als Testfall der API
- [x] PHPUnit-Integrationssuite mit WP-Testumgebung (statt nur `tests/wp-smoke.php`)
- [x] erweiterte Dokumentation in Englisch aller Filter/Actions, inkl. @since Informationen (Parameter, Zweck)

## Milestone 5 – Ausbau (0.5.0+)

- [ ] Gutenberg-Block (Formularauswahl, Live-Vorschau per ServerSideRender)
- [ ] Import/Export von Formularen (JSON, ohne Secrets)
- [ ] Mehrschritt-Formulare (Seitenumbruch-Element, Fortschritt, Validierung pro Schritt)
- [ ] Datei-Upload (geschütztes Verzeichnis, Typ-/Größenprüfung, Anhang in Mails)
- [ ] Weitere Felder: Datepicker, Rating, Signatur, HTML/Text-Block, Berechnung
- [ ] Vorlagenbibliothek (Kontakt, Anmeldung, Umfrage)
- [ ] Versionsverlauf im Builder (alte Version ansehen / wiederherstellen)
- [ ] Visual Composer Block (Formularauswahl)

## Bekannte Punkte / technische Schulden

- [ ] Dasselbe Formular zweimal auf einer Seite → doppelte DOM-IDs (Instanz-Suffix einführen)
- [ ] WPML-Anbindung (`Support/Wpml.php`) ungetestet – mit aktivem WPML prüfen
- [ ] Mails im Test mit Mailpit statt `pre_wp_mail` prüfen (Darstellung der HTML-Mail)
- [ ] Admin-Bundle ~330 kB (118 kB gzip): Quasar-Komponenten gezielt importieren / Builder-Chunk weiter splitten
- [ ] Dark-Mode des Builders visuell prüfen
- [ ] Git-Repository anlegen, CI (composer check + npm lint/typecheck/test)
- [ ] Release-Paket (ohne `apps/admin/node_modules`, mit `vendor/` und gebauten `assets/`)
