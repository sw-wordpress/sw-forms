# Verifikation der GitHub-Updates

Stand: 11. November 2026. Ziel: `sw-wordpress/sw-forms`, Repository-ID `1432336899`,
Basename `sw-forms/sw-forms.php`, `SWF_VERSION=0.5.0`.
Die Sicherheitsimplementierung wurde aus der [Referenz 49dc66f](https://github.com/sw-wordpress/churchtools-suite/tree/49dc66f)
adaptiert; die UI folgt SW BackupIt: ausschließlich `plugin_row_meta` in der nativen
Pluginübersicht. Keine eigene Update-Seite und keine Laufzeitabhängigkeit von anderen Plugins.

## Ausgeführte Prüfungen

| Prüfung | Ergebnis |
| --- | --- |
| `composer check` | bestanden: PSR-12, WPCS, PHPStan Level 8 ohne Baseline, 114 Unit-Tests / 667 Assertions |
| vollständige WordPress-Suite, private Installation/DB | 139 Tests / 2391 Assertions; 1 erwarteter Skip für das optionale, dort nicht installierte WP Armour |
| acht eigenständige WordPress-Integrationsskripte einschließlich Smoke-Test | bestanden |
| isolierte Update-Suite | 59 Tests: Single-Site 170 Assertions, Multisite 179 Assertions |
| gemergte vollständige PHP-Coverage | 8400/9122 Zeilen = 92,09 %; alle bestehenden strengeren Bereichsgrenzen bestanden |
| npm Lint, Typecheck, Build | bestanden |
| Vitest einschließlich eigenständigem Update-JS | 414 Tests; 3049/3291 Zeilen = 92,65 %; alle bestehenden Bereichsgrenzen bestanden |
| Chromium: Frontend, Builder/Admin, native Pluginzeile | bestanden; Einzelaufruf, manueller/schweigsamer Tagescheck, Status und unveränderte native Navigation |
| echte Mozart-Release-ZIPs | gebaut; mit Wegwerfschlüssel signiert und mit Laufzeitprüfern verifiziert; fehlendes Secret blockiert; Paketvollständigkeit und Ausschluss privater Testschlüssel geprüft |
| WordPress.org-Build | bootet; Update URI, Provider und dessen Skript fehlen; Quellcheckout bleibt unverändert |
| drei Betreiberblöcke unter Bash und Zsh | isoliert geprüft: 0700/0600, Überschreibschutz, Erhalt vorhandener Schlüssel, Konfliktablehnung; `gh` simuliert, Secret nur über stdin |
| Draft-Workflow | simulierte neue/vorhandene Drafts, veröffentlichter Release und API-Fehler geprüft; YAML geparst |
| Übersetzungen | deutsche Kataloge kompiliert und ohne offene/fuzzy Einträge geprüft |

Die Update-Suite deckt Signatur/Byteänderungen, strikte Identität/Schema/Assetbindung,
doppelte JSON-Schlüssel, ZIP-Manipulationen/Pfade/Symlinks, Redirects/Größenlimits,
Nonce/Rechte, Frontend/Cron/REST/CLI-Sperren, persistente Fristen nach Fehler/Cache-Löschung,
Rollback, Lock-Übernahme, Deaktivierung, Multisite und echte native Einzel-/AJAX-/Sammelupgrades ab.
Zwei durch WordPress aktivierte Pluginfixtures laden gleichzeitig unabhängige Providerkopien mit eigenen Hooks, Schlüsseln, Speichern und Locks;
fremde Pluginangebote und Downloadentscheidungen bleiben erhalten. Echte Upgrades laufen nur
im privaten `tests/wp/vendor/`-WordPress, niemals gegen Entwicklungsdateien.

## Artefakte und verbleibender Betrieb

`dist/sw-forms-0.5.0.zip` ist installierbar und enthält den vom Betreiber hinterlegten öffentlichen Schlüssel `sw-forms-2026-11`.
`dist/verification/` enthält ausschließlich Testartefakte mit einem Wegwerf-Vertrauensanker,
Manifest, Signatur und simulierten Asset-IDs; diese sind keine produktiven Releases.
Die privaten Testschlüssel wurden weder ins Plugin noch in die ZIPs übernommen.

Der Betreiber hat den öffentlichen Schlüssel `sw-forms-2026-11` hinterlegt. Die GitHub-Umgebung
`plugin-releases` existiert mit benutzerdefinierten Branch-/Tagregeln; ihre Secrets konnten
mit den verfügbaren Leserechten nicht geprüft werden (HTTP 403). Die Existenz einer Umgebung
belegt keine manuelle Release-Freigabe. Ein signierter produktiver Release und die
vertrauenswürdige Erstverteilung sind noch nicht verifiziert; eine produktiv aktive
Updateversorgung wird deshalb nicht behauptet. Lokale Tests verwenden weiterhin ausschließlich
Wegwerfschlüssel. Einrichtung/Rotation und Grenzen:
[Betriebshandbuch](GITHUB-UPDATES-BETRIEB.md). Der Store-Build ist geprüft; ein tatsächlicher
Store-Übergang bestehender Installationen samt Zustandsbereinigung bleibt ein späterer Release.

## PR-Follow-up

Drei gültige Hinweise aus PR #64 wurden umgesetzt: Schlüssel-Neuprovisionierung verwendet
`sw-forms-2026-11-next` und erhält den bestehenden Schlüssel; der Befehlstest startet nun
mit dem echten öffentlichen Schlüsselring. Nach erfolgreicher manueller Prüfung führt ein
Neuladen-Link zurück zur nativen Pluginliste. Alle neuen Benutzermeldungen laufen durch die
Textdomain und sind in Englisch/Deutsch geprüft. Die CI provisioniert `swf_wp_tests` explizit,
nachdem der erste Lauf am fehlenden Testschema scheiterte. Frontend-, PHP-, WordPress- und
Release-Prüfungen wurden nach den Korrekturen lokal wiederholt; GitHub-Reviewstatus wird im
PR separat dokumentiert.

Der nachgelagerte SonarCloud-Hinweis `php:S4790` ist durch SHA-256 statt MD5 für den
Advisory-Lock-Bezeichner behoben. Der Wert bleibt einschließlich Pluginpräfix auf 64 Zeichen
begrenzt. Die Multisite-Prüfung erfasst die tatsächlich verwendeten SQL-Locknamen zweier
aktivierter Pluginkopien und prüft Trennung und Längenlimit; beide Modi gehen in die Coverage ein.
