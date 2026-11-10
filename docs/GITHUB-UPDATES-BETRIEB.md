# Betrieb der verifizierten GitHub-Updates

Repository: `sw-wordpress/sw-forms` (GitHub-ID `1432336899`), Basename `sw-forms/sw-forms.php`, Version `SWF_VERSION`. Der Provider ist implementiert, aber ohne Betreiberkonfiguration **nicht produktiv updatefähig**. SW Forms hatte zuvor keinen eigenen Updater oder Installer; es werden ausschließlich der eigene Slug und das eigene Repository verwaltet. Ohne eingerichteten öffentlichen Schlüssel werden weder GitHub-Releases geprüft noch GitHub-Updates installiert. Es gibt keinen Rückfall auf unsignierte Downloads.

## Einmalige Einrichtung vor der ersten signierten Veröffentlichung

1. Ein dediziertes Ed25519-Schlüsselpaar außerhalb des Repositorys und außerhalb von WordPress erzeugen. Der Signierer benötigt den **64 Byte langen Sodium Secret Key**, Base64-kodiert; kein GitHub-Token und kein SSH-Schlüssel. Schlüsselmaterial nicht im Terminalprotokoll ausgeben. Die Schlüsselgenerierung und Ablage erfolgen bewusst durch den Betreiber, nicht beim Installieren des Plugins.
2. In GitHub die Umgebung `plugin-releases` mit Freigaben und geschützten Release-Tags einrichten. In dieser Umgebung das Secret `SW_PLUGIN_RELEASE_SIGNING_KEY` und die Variable `SW_PLUGIN_RELEASE_KEY_ID` setzen. Der öffentliche Schlüssel ist kein Geheimnis; der private Schlüssel darf weder ins Repository noch auf den WordPress-Server gelangen.
3. Den öffentlichen Schlüssel im vertrauenswürdig ausgelieferten `includes/update/trusted-keys.php` hinterlegen: Rückgabearray `key_id => Base64(public_key)`. Alternativ kann eine lokal administrierte WordPress-Konfiguration die Array-Konstante `SWF_RELEASE_PUBLIC_KEYS` setzen. Keine über das Backend eingetragene Downloadadresse oder remote geladene Schlüsselliste wird vertraut.
4. **Vertrauensstart:** Bereits installierte Versionen ohne Schlüssel müssen den öffentlichen Schlüssel einmal über einen separat vertrauenswürdigen Weg erhalten, etwa durch manuelle Installation des ersten kontrollierten ZIPs oder die lokale Konstante. Der erste heruntergeladene Release darf seinen eigenen Schlüssel nicht legitimieren.
5. Erst einen Draft mit Testschlüssel in einer privaten WordPress-Installation prüfen. Anschließend einen echten Versions-Tag veröffentlichen; der Release-Workflow bettet den öffentlichen Schlüssel ein, baut ZIPs, lädt diese in einen Draft, signiert das Manifest mit den tatsächlichen Asset-IDs und veröffentlicht erst den vollständigen Satz.

Ohne Secret oder Key-ID bricht der Release-Build vor dem Upload/Publish ab. Diese Konfiguration wurde durch die Codeänderung nicht automatisch im GitHub-Konto angelegt. Ein Repository-/Workflow-Administrator mit Zugriff auf eine ungeschützte Signierumgebung könnte weiterhin bösartigen Code signieren; die geschützte Umgebung ist deshalb Teil der Betriebsanforderung.

Ein Release startet durch Push eines Tags wie `v0.5.1`, nachdem Header und `SWF_VERSION` im geprüften Commit auf dieselbe Version gesetzt wurden; siehe [Release-Build](release.md). Der Workflow lädt Draft-Metadaten nach dem ZIP-Upload über die API-URL aus `gh release view`, prüft den eingebetteten öffentlichen Schlüssel und signiert die tatsächlichen Asset-IDs. Eine bereits belegte Key-ID darf keinen anderen öffentlichen Schlüssel erhalten. Veröffentlichte Releases werden nicht überschrieben. Alte Tags verwenden weiterhin ihren damaligen Workflow; Korrekturen benötigen einen neuen Tag mit dem aktuellen Code.

## Copy & Paste: Schlüssel für SW Forms einrichten

Diese Befehle sind für Bash/Zsh auf dem lokalen Entwicklungs-/Release-Rechner mit PHP und Sodium gedacht, nicht für den produktiven WordPress-Webserver. Der Checkout liegt hier unter `/var/www/html/wordpress_dev/wp-content/plugins/sw-forms`. Private Dateien landen ausschließlich unter `$HOME/.local/share/sw-forms-release-keys`, außerhalb dieses Checkouts und Webroots. Diesen Ordner separat verschlüsselt sichern; er gehört nicht in Git, Release-ZIPs oder öffentlich synchronisierte Ordner. Die Befehle wurden mit Wegwerfschlüsseln geprüft; produktive Schlüssel werden nicht durch die Dokumentation angelegt.

**1. Einmalig Schlüssel erzeugen.** Der Block verweigert einen bereits vorhandenen Schlüsselordner, damit ein erneutes Einfügen keinen bestehenden Schlüssel ersetzt. Die Key-ID ist eine öffentliche Kennung; beim späteren Schlüsselwechsel eine neue Kennung und einen neuen Ordner verwenden.

```bash
(
  set -eu
  umask 077
  export SWF_RELEASE_KEYS_DIR="$HOME/.local/share/sw-forms-release-keys"
  php <<'PHP'
<?php
declare(strict_types=1);
if (!function_exists('sodium_crypto_sign_keypair')) {
    throw new RuntimeException('PHP-Sodium fehlt.');
}
$dir = getenv('SWF_RELEASE_KEYS_DIR');
if (!$dir || file_exists($dir) || is_link($dir)) {
    throw new RuntimeException('Schlüsselordner existiert bereits oder Pfad fehlt. Keine Dateien verändert.');
}
if (!mkdir($dir, 0700, true)) {
    throw new RuntimeException('Schlüsselordner konnte nicht angelegt werden.');
}
$id = 'sw-forms-2026-11';
$pair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($pair);
$public = sodium_crypto_sign_publickey($pair);
$files = [
    'signing-key.base64' => base64_encode($secret) . "\n",
    'public-keys.php' => "<?php\nreturn " . var_export([$id => base64_encode($public)], true) . ";\n",
    'key-id.txt' => $id . "\n",
];
foreach ($files as $name => $contents) {
    $handle = fopen($dir . '/' . $name, 'x');
    if (!$handle || fwrite($handle, $contents) !== strlen($contents)) {
        throw new RuntimeException('Schlüsseldatei konnte nicht vollständig geschrieben werden.');
    }
    fclose($handle);
}
sodium_memzero($secret);
sodium_memzero($pair);
echo "Schlüsseldateien erzeugt; privater Schlüssel wurde nicht ausgegeben.\n";
PHP
)
```

**2. Nur den öffentlichen Schlüssel ins Plugin übernehmen.** Dieser Block erhält bereits vorhandene öffentliche Schlüssel und lehnt eine abweichende Belegung derselben Key-ID ab. Dafür werden keine privaten Schlüssel in eine Umgebungsvariable oder nach WordPress kopiert.

```bash
(
  set -eu
  export SWF_PLUGIN_DIR='/var/www/html/wordpress_dev/wp-content/plugins/sw-forms'
  export SWF_RELEASE_KEYS_DIR="$HOME/.local/share/sw-forms-release-keys"
  php <<'PHP'
<?php
declare(strict_types=1);
$target = getenv('SWF_PLUGIN_DIR') . '/includes/update/trusted-keys.php';
$existing = require $target;
$incoming = require getenv('SWF_RELEASE_KEYS_DIR') . '/public-keys.php';
if (!is_array($existing) || !is_array($incoming) || count($incoming) !== 1) {
    throw new RuntimeException('Ungültiger öffentlicher Schlüsselring.');
}
foreach ($incoming as $id => $key) {
    $decoded = is_string($key) ? base64_decode($key, true) : false;
    if (!is_string($decoded) || strlen($decoded) !== 32 || (isset($existing[$id]) && $existing[$id] !== $key)) {
        throw new RuntimeException('Ungültiger öffentlicher Schlüssel oder widersprüchliche Key-ID.');
    }
    $existing[$id] = $key;
}
$contents = "<?php\n/** Public Ed25519 verification keys. */\nreturn " . var_export($existing, true) . ";\n";
if (file_put_contents($target, $contents, LOCK_EX) !== strlen($contents)) {
    throw new RuntimeException('Öffentlicher Schlüsselring konnte nicht geschrieben werden.');
}
echo "Öffentlicher Schlüsselring aktualisiert.\n";
PHP
  php -l "$SWF_PLUGIN_DIR/includes/update/trusted-keys.php"
  git -C "$SWF_PLUGIN_DIR" diff -- includes/update/trusted-keys.php
)
```

Die angezeigte Änderung an `includes/update/trusted-keys.php` enthält ausschließlich den öffentlichen Schlüssel. Diese Datei nach Review regulär committen. Bestehende Installationen benötigen sie einmal über einen vertrauenswürdigen manuellen Upload oder eine lokale Konfiguration; erst dann können sie signierte Folgeupdates akzeptieren.

**3. GitHub vorbereiten und Secret hinterlegen.** Zuerst in den [Repository-Environments](https://github.com/sw-wordpress/sw-forms/settings/environments) die Umgebung `plugin-releases` anlegen und Freigaben sowie erlaubte Release-Tags passend zum Workflow absichern. Erst danach folgenden Block mit einem für dieses Repository berechtigten GitHub-CLI-Konto ausführen. `gh auth status` prüft die Anmeldung; falls nötig zuvor `gh auth login` ausführen.

```bash
(
  set -eu
  SWF_RELEASE_KEYS_DIR="$HOME/.local/share/sw-forms-release-keys"
  gh auth status
  gh secret set SW_PLUGIN_RELEASE_SIGNING_KEY \
    --repo sw-wordpress/sw-forms --env plugin-releases \
    < "$SWF_RELEASE_KEYS_DIR/signing-key.base64"
  gh variable set SW_PLUGIN_RELEASE_KEY_ID \
    --repo sw-wordpress/sw-forms --env plugin-releases \
    --body "$(cat "$SWF_RELEASE_KEYS_DIR/key-id.txt")"
  gh secret list --repo sw-wordpress/sw-forms --env plugin-releases
  gh variable list --repo sw-wordpress/sw-forms --env plugin-releases
)
```

Der private Schlüssel wird über Standardeingabe an GitHub übergeben und erscheint weder im Befehlsargument noch in der Terminalausgabe. Die Listen zeigen Secret-Namen beziehungsweise öffentliche Variablen. Referenzen: [GitHub CLI: Secret setzen](https://cli.github.com/manual/gh_secret_set), [Variable setzen](https://cli.github.com/manual/gh_variable_set), [PHP: Ed25519-Schlüsselpaar erzeugen](https://www.php.net/manual/en/function.sodium-crypto-sign-keypair.php).

Ein erneutes Setzen des Secrets ersetzt den GitHub-Wert. Deshalb vor Ausführung sicherstellen, dass dieser lokale Schlüssel zum gewünschten öffentlichen Schlüsselring passt; bei Rotation den Ablauf weiter unten verwenden. Die Befehle erzeugen keinen Release-Tag und veröffentlichen kein ZIP. Der eingerichtete Tag-Workflow übernimmt später Build, Signierung und Veröffentlichung.

## Prüfungen und Installation

Die Prüfschaltfläche und der lokale Status stehen ausschließlich in der SW-Forms-Zeile der nativen **Plugins**-Übersicht (bei Multisite in der Netzwerkverwaltung). Es gibt keine eigene Update-Seite.

- Berechtigte Backend-Besuche können nach dem Seitenaufbau einen Prüflauf starten; höchstens einmal je 24 Stunden pro konfigurierter Quelle und WordPress-Netzwerk. Nach Netzwerk- und Verifikationsfehlern gilt dieselbe Sperrfrist.
- Ein manueller Klick auf „Jetzt auf Updates prüfen“ darf das Tageslimit umgehen, hat aber einen Mindestabstand von 60 Sekunden und benötigt Nonce plus `update_plugins`.
- Das Frontend, Cron, WP-CLI und REST lösen keine GitHub-Prüfung aus. Ohne Backend-Besuch gibt es keine tägliche Anfrage.
- Die Pluginübersicht und der Plugin-Detaildialog zeigen ausschließlich lokale Angebote. Die Installation verwendet WordPress’ normale Einzel-/AJAX-/Sammelaktualisierung. Die Auto-Update-Spalte erklärt, dass nur manuell installiert wird.
- Vor dem WordPress-Download wird das gespeicherte Manifest erneut verifiziert. Danach wird genau das gebundene Asset in eine exklusive temporäre Datei geladen und auf Größe, SHA-256, ZIP-Pfade, Dateitypen, Entpacklimits und Plugin-Header geprüft. Fehler blockieren die Übergabe an WordPress.
- Erfolgreich geprüfte Angebote sind höchstens sieben Tage ohne erneute Prüfung installierbar. Die zuletzt akzeptierte höchste Version verhindert das Zurücksetzen auf einen älteren geprüften Stand.
- Erstinstallationen erfolgen über einen bewussten ZIP-Upload in WordPress. Ein Upload wird nicht als vom GitHub-Provider verifiziert ausgewiesen.

Lokale, nicht autoloadende Zustände liegen bei Single-Site in WordPress-Optionen; Multisite verwendet Netzwerkoptionen. Die Tagesgrenze überlebt eine Transient-Löschung. Ein abgelaufener Lock ist übernehmbar; ein alter Besitzer darf den neuen Lock nicht freigeben oder ein verspätetes Ergebnis veröffentlichen. Beim ersten berechtigten Backend-Besuch werden die eigene native Auto-Update-Auswahl und eigene alte Angebote bereinigt. Es gibt keine alten SW-Forms-Updater-Cronjobs oder Zugangsdaten zu migrieren. Die eigene Auswahl in WordPress’ Auto-Update-Liste wird entfernt. Beim Deaktivieren der Suite werden eigene zwischengespeicherte Updateangebote entfernt, bevor ihre Schutz-Hooks entfallen. 

## Dateien und Schnittstellen

| Datei | Verantwortung |
| --- | --- |
| `includes/update/source.php` | Lokal festgelegtes Repository, numerische Repository-ID, erlaubte Slugs, Hauptplugin und eindeutige Speicher-/Locknamen |
| `includes/update/descriptors.php` | Tatsächliche WordPress-Basenames, Slugs und installierte Versionen der verwalteten Plugins |
| `includes/update/trusted-keys.php` | Ausschließlich öffentliche Ed25519-Schlüssel |
| `class-sw-forms-update-manifest.php` | Signatur, Schema und Bindung an Release/Asset |
| `class-sw-forms-update-transport.php` | HTTPS, erlaubte Hosts/Pfade, Redirect- und Größenlimits |
| `class-sw-forms-update-package.php` | Hash, ZIP-Struktur, Dateitypen, Entpacklimits und Header |
| `class-sw-forms-update-store.php` | Persistenter Zustand, atomarer Lock mit Eigentümerprüfung |
| `class-sw-forms-update-service.php` | Prüfpolitik, Quellenabruf und verifizierter Download |
| `class-sw-forms-update-provider.php` | Native WordPress-Hooks, Backend-Berechtigungen, Migration und UI-Anbindung |
| `bin/release-manifest.php` | Public-Key-Export für den Build und Signierung der fertig hochgeladenen ZIPs |

Bei Übernahme werden `source.php`, `descriptors.php`, Schlüssel und der Plugin-spezifische Adapter konfiguriert. Klassen-, Hook- und Optionspräfixe müssen pro ausgelieferter Kopie eindeutig sein. Kein „erste geladene Bibliotheksversion gewinnt“ und keine Laufzeit-Composer-Abhängigkeit. SW Forms verwaltet keine Addons.

Tag, Header, `SWF_VERSION`, ZIP-Name `sw-forms-<version>.zip` und signierte Paketversion stimmen überein; erlaubt sind drei oder vier numerische Komponenten sowie die Tag-Zusätze `v` und `-NN`. Der Signierer und der Laufzeitprüfer werden mit Wegwerfschlüsseln gegeneinander getestet.

## Schlüsselwechsel, Ausfälle und WordPress.org

Für Rotation zunächst eine mit dem bisherigen Schlüssel signierte Übergangsrelease mit beiden öffentlichen Schlüsseln ausliefern; erst danach mit der neuen Key-ID signieren. Ein kompromittierter Schlüssel benötigt einen separat vertrauenswürdigen Wiederherstellungsweg. Ohne zusätzliches signiertes zeitlich gültiges Channel-Verzeichnis kann ein erstmaliger Client nicht zuverlässig erkennen, dass eine neuere Version absichtlich vorenthalten wird.

GitHub-Redirects sind auf konkrete HTTPS-Hosts begrenzt. Ein Wechsel des CDN kann deshalb ein Client-Update nötig machen. Metadaten haben Größen- und Zeitlimits; der Paketdownload erfolgt ausschließlich beim manuellen Installationsauftrag. Die Unterstützung fremder privater Repositorys und GitHub-Tokens ist nicht Teil dieser Implementierung.

Vor Einreichung bei WordPress.org sind GitHub-Update-URI und Provider aus dem Store-Build zu entfernen. Eine letzte signierte Übergangsrelease muss diesen Wechsel für bestehende Installationen durchführen; dabei gezielt eigene Updateangebote und Zustände bereinigen. Das wird nicht bereits beim bloßen Vorhandensein einer vermeintlich gleichnamigen Store-Version automatisch umgeschaltet.

## Verifikation

`tests/wp/tests/VerifiedUpdatesTest.php` verwendet einen privaten WordPress-/MariaDB-Aufbau, Wegwerfschlüssel und vollständig simulierte HTTP-Antworten. Darunter sind echte WordPress-Einzel- und Sammelinstallationen ausschließlich im Vendor-Verzeichnis der Testinstallation. Produktive Plugin-Dateien werden nicht installiert oder überschrieben. Die Browserprüfung sichert den manuellen Prüfdialog und die unveränderte WordPress-Navigation ab.

Die Suiten bleiben auf Pull Requests beschränkt. Der Tag-Build prüft und signiert seine Artefakte; er führt keine Testsuite aus. Ohne eingerichtete produktive Schlüssel kann ein erfolgreicher Testlauf keine echte signierte Veröffentlichung ersetzen.

Aktuelle lokale Prüfergebnisse, Artefakte und noch nicht eingerichteter Betrieb:
[Verifikationsbericht](GITHUB-UPDATES-VERIFIKATION.md). Reproduzierbare Runner:
[Tests und Coverage](testing.md#verified-manual-github-updates).
