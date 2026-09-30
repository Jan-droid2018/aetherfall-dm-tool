# Ätherfall DM-Tool

Lokales, deutschsprachiges DM-Cockpit für Charakterverknüpfungen, Initiative, Kampf-LP, manuell gewürfelte Formeln und Combat Log. Das vorhandene Verzeichnis `json/` bleibt unveränderte Source of Truth.

## Voraussetzungen

- Windows mit XAMPP
- PHP 8.0 oder neuer mit `pdo_mysql`, `mbstring` und `json`
- MariaDB/MySQL aus XAMPP
- Apache; keine CDN- oder Composer-Abhängigkeiten

## Installation unter XAMPP

1. Projekt nach `C:\xampp\htdocs\DM-Tool` kopieren. Den Ordner `json` nicht verändern.
2. Apache und MySQL im XAMPP Control Panel starten.
3. Optional in phpMyAdmin eine leere Datenbank mit `utf8mb4` anlegen:

   ```sql
   CREATE DATABASE aetherfall_dm_tool CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

   Falls sie fehlt, legt `bin/migrate.php` den konfigurierten, sicher validierten Datenbanknamen selbst an.
4. `.env.example` als `.env` kopieren und lokale Datenbankdaten eintragen. Bei einer XAMPP-Standardinstallation funktionieren die eingebauten lokalen Standardwerte auch ohne `.env`. Keine echten Zugangsdaten committen.
5. In einer Shell im Projektroot ausführen:

   ```powershell
   php bin/check-json.php
   php bin/migrate.php
   php bin/import-json.php
   ```

6. `http://localhost/DM-Tool/public/` öffnen.

Der Import ist idempotent. Ein erneutes `php bin/import-json.php` aktualisiert vorhandene stabile IDs und erzeugt keine Duplikate.

Einige Regeldateien sind größer als 1 MiB. Der Importer erkennt ein zu kleines MariaDB-`max_allowed_packet` und erhöht es mit lokalen Root-Rechten für neue Verbindungen auf 16 MiB. Falls der Datenbanknutzer diese Berechtigung nicht besitzt, in `C:\xampp\mysql\bin\my.ini` im Abschnitt `[mysqld]` `max_allowed_packet=16M` setzen und MySQL neu starten.

## Bedienung

Unter **Charaktere** wird ein reduzierter DM-Datensatz angelegt. Die Werte und Boni aller elf Attribute werden als Ganzzahlen manuell eingegeben. Der Modifikator wird für Werte von 1 bis 100 automatisch mit `floor((Wert - 10) / 2)` berechnet. Klassenanpassungen +2/+1/−2 erscheinen nur als Hinweis und werden nicht auf den Attributwert angewendet. Fähigkeiten und Zauber sind Referenzen auf zentrale Regeldefinitionen.

Die maximalen Charakter-LP werden automatisch berechnet:

```text
100
+ ((Hauptattribut-Modifikator + Hauptattribut-Bonus) × 10)
+ ((Sekundärattribut-Modifikator + Sekundärattribut-Bonus) × 5)
+ (Stufe × 10)
```

Modifikatoren und maximale LP sind im Formular nicht editierbar und werden beim Speichern serverseitig neu berechnet. Aktuelle LP bleiben separat: Eine neue Figur beginnt bei ihren maximalen LP; beim Bearbeiten wird sie durch steigende Max-LP nicht geheilt und bei sinkenden Max-LP höchstens auf den neuen Maximalwert reduziert.

Charakterberechnungen liefern immer Ganzzahlen. Sollte eine Berechnung dennoch einen Nachkommawert ergeben, wird mit `floor` abgerundet: `4,5 → 4` und `4,9 → 4`.

Unter **Combat Tracker** wird ein Kampf angelegt, danach werden Charaktere, Kreaturen und Bosse mit Initiative beziehungsweise Profilstufe hinzugefügt. Bei Charakteren wird die aktuelle Stufe immer automatisch aus dem Charakterbogen übernommen; nur Kreaturen und Bosse besitzen eine auswählbare Profilstufe. Max-LP von Kreaturen und Bossen kommen automatisch aus dem importierten Stufenprofil; Bossphasen werden anhand der LP-Schwellen automatisch fortgeschrieben und springen bei Heilung nicht zurück. Zugeordnete Charakterfähigkeiten und -zauber erscheinen beim aktiven Zug im Combat Calculator, Kreaturen- und Bossaktionen stammen aus dem gewählten Profil. Die normale Aktionseingabe besteht aus höchstens Trefferwürfel, Schadens-/Effektwürfel, Ziel und Krit-Checkbox; die Spielerwürfel werden als Summen eingetragen. Technische Formeln und einzelne Würfelgruppen bleiben intern. Das Magieattribut ist nur für aktive Charaktere relevant. Resistenz und physische beziehungsweise magische Profilverteidigung werden beim Ziel automatisch aus dem hinterlegten Kreaturen- oder Bossprofil übernommen und müssen nicht manuell eingetragen werden; bei Charakterzielen bleibt die eigene Verteidigungsentscheidung beim Spieler. Bei aktivierter Krit-Checkbox verwendet der Server den Krit-Prozentwert des aktiven Charakters oder Kreaturenprofils einmalig auf den vollständigen normalen Schaden. Heilung erhält keinen Krit-Bonus. Erst „Schaden anwenden“ oder „Heilung anwenden“ ändert Kampf-LP.

## Diagnose und Tests

```powershell
php bin/check-json.php
php bin/check-formulas.php
php tests/run.php
php bin/recalculate-characters.php
```

Nach Migration und Import kann die echte Datenbank-Idempotenz explizit geprüft werden:

```powershell
php tests/db-import.php
php tests/db-smoke.php
```

Der erste Test importiert zweimal in die konfigurierte Datenbank und vergleicht die Tabellenzahlen. Der Smoke-Test legt temporär Charakter und Kampf mit Charakter/Kreatur/Boss an, prüft Relationen, Initiative, HP und Log und räumt die Testdaten im `finally`-Block wieder auf. `recalculate-characters.php` berechnet bestehende Charaktere einzeln transaktional neu; ungültige Altdaten werden verständlich gemeldet und unverändert übersprungen.

## Regelwerk später aktualisieren

Neue oder geänderte JSON-Dateien in den bestehenden Content-Ordnern bereitstellen, dann erneut prüfen und importieren:

```powershell
php bin/check-json.php
php bin/import-json.php
php bin/check-formulas.php
```

Charakterverknüpfungen zeigen danach automatisch die aktualisierten Definitionen.

## Bewusste V1-Grenzen

Kein Login, Spielerportal, Multiplayer, Inventar, Waffen-/Rüstungsverwaltung, vollständiger Charaktergenerator, automatische Ermittlung der Attributwerte oder -boni, Karten oder vollständige Statusengine. Komplexe Texte, verzweigte Mehrfachwirkungen und nicht eindeutig definierte Verteidigungsregeln nutzen den sichtbaren manuellen Fallback. Die Anwendung würfelt nie für Spieler.

Technische Details stehen in `docs/architecture.md`, `docs/database-schema.md`, `docs/json-schema-analysis.md`, `docs/formula-engine.md` und `docs/formula-patterns.md`.
