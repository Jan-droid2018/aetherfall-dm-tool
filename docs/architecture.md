# Architektur

```text
JSON (Source of Truth)
  → CLI-Validierung / idempotenter Import
  → MariaDB-Regelwerk + Laufzeitdaten
  → PDO-Repositories / Services
  → kleine JSON-API
  → deutsche Vanilla-JS-Oberfläche
```

- `app/Support`: Umgebung, PDO, JSON und Logging.
- `app/Repositories`: relationale Abfragen und transaktionales Charakter-CRUD.
- `app/Services`: Import, Validierung, zentrale Charakterberechnungen, Combat State und Schadenspipeline.
- `app/Formula`: sicherer Tokenizer, Parser und Variablenresolver ohne `eval`.
- `bin`: Migration, Import und Diagnostik.
- `public`: einziger Web-Einstieg, SPA-Oberfläche und Assets.

Die Webanwendung liest Regelwerkdaten aus der Datenbank. JSON wird ausschließlich durch bewusste CLI-Läufe verarbeitet. Charakter-LP und Kampf-LP sind getrennt; Teilnehmer erhalten Snapshots. Regeldefinitionen werden nie in Charaktere kopiert.

## Charakterberechnungen

`CharacterAttributeCalculator` ist die serverseitige Source of Truth für Charakter-Modifikatoren und akzeptiert ausschließlich Attributwerte von 1 bis 100. `CharacterHpCalculator` berechnet Max-LP und kapselt das Verhalten der aktuellen LP. Das Repository ignoriert vom Client gelieferte Modifikatoren und Max-LP, berechnet beide Werte neu und speichert sie gemeinsam mit dem Charakter in einer Transaktion.

Die JavaScript-Berechnung dient nur der unmittelbaren Vorschau. Sie markiert das Haupt- und Sekundärattribut der gewählten Klasse und zeigt den LP-Breakdown an. Klassenanpassungen +2/+1/−2 bleiben reine Information und verändern die eingegebenen Attributwerte nicht.

# Multiclassing

Charaktere werden über `character_classes` mit den Rollen `primary`, `secondary_1` und `secondary_2` verbunden. Jede Relation besitzt eine eigene `class_level`; die Gesamtstufe ist ausschließlich das Maximum dieser Werte. LP verwenden die Attribute der Hauptklasse und die höchste Klassenstufe. Ursprungsvermächtnis ist eine primäre Sonderklasse ohne Nebenklassen.
