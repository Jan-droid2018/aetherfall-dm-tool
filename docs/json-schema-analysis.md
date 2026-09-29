# JSON-Schema-Analyse

Stand: 29.09.2026. Analysiert wurden rekursiv alle 96 JSON-Dateien. Die vier `_manifest.json` sind Metadaten und werden nicht importiert. Alle Dateien sind valides JSON und verwenden je Content-Art genau eine Schema-Version.

| Content | Echtdaten | Schema | Laufzeitobjekte |
|---|---:|---|---:|
| Klassen | 26 | `aetherfall.class_abilities.v1` | 5.200 Fähigkeiten, 1.040 Stufenblöcke |
| Kreaturen | 28 | `aetherfall.creatures.v1` | 252 Stufenprofile, 918 konkrete Profilaktionen |
| Bosse | 32 | `aetherfall.bosses.v1` | 288 Stufenprofile, 2.502 konkrete Profilaktionen |
| Zauberelemente | 6 | `aetherfall.spells.v1` | 600 Zauber (je 10 Grade × 10 Zauber) |

## Klassen

Wurzelfelder: `schema_version`, `id`, `name`, `source_heading`, `source_file`, `resource`, `class_preamble_markdown`, `class_sections`, `levels`, `statistics`, `source`.

Jede Klasse besitzt genau 40 Stufen mit fünf Fähigkeiten, also 200 Fähigkeiten. Fähigkeiten enthalten stabil: ID, Klassen-ID, Stufe, Nummer, Name, Typ, Schwerpunkt, Aktionskosten, Auslöser, Kosten, Klassenressource, Einsatzlimit, Reichweite, Ziel, Fläche, Dauer, Angriffswurf, Rettungswurf, SG, Attributsbezug, Schadensart, Berechnung, Regelwirkung, Zielwirkung, Status, Stapelung, Endbedingung und Skalierung. Optionale Ausrüstungs-/Waffenbezüge und `extra_fields` bleiben in `raw_json` erhalten.

Jede Klasse besitzt eine umfangreiche Ressourcendefinition. Neben den gemeinsamen Ressourcenfeldern kommen klassenspezifische Angaben wie Klassen-SG, Resonanzwurf, Gefährtenwerte und Barrieren vor. Die Datenbank speichert die Definition zentral als `class_resources` und vollständig roh.

## Zauber

Wurzelfelder: `schema_version`, `id`, `element`, `source_file`, `metadata`, `document_intro_markdown`, `grades`, `statistics`, `source`.

Elemente: Dunkelheit, Erde, Feuer, Licht, Luft und Wasser. Jeder Grad enthält zehn Zauber. Ein Zauber besitzt stabile ID, Nummer, Name, Element, Grad, Wirkungsart, Wirkzeit, Auslöser, strukturierte prozentuale Manakosten, Einsatzlimit, Reichweite, Ziel, Fläche, Dauer, Konzentration, Magieattribut, Angriffswurf, Rettungswurf, SG, Schadensart, Berechnung, Regel-/Zielwirkung, Status, Stapelung, Endbedingung und Aufwertung.

## Kreaturen

Wurzelfelder: `schema_version`, `id`, `name`, `source_file`, `core`, `action_overview`, `shared_action_details`, `lore_and_rules`, `levels`, `post_sections`, `statistics`, `source`.

Jede Kreatur hat neun Profile (Stufen 1, 5, 10, 15, 20, 25, 30, 35, 40). Ein Profil enthält alle elf Attribute mit Wert/Modifikator/Bonus, Kampfwerte, LP-Berechnung, sechs strukturierte Elementresistenzen, eine Aktionstabelle, ausführliche Aktionsdetails und optionale Zusatzabschnitte. `combat_values.values` enthält unter anderem maximale LP, physische Verteidigung, Magieverteidigung, Bewegung, Kritwerte und kreaturenspezifische SG/Werte. Für die Laufzeit werden die konkreten Profilaktionen importiert; Details werden über Namen zusammengeführt und zugleich roh erhalten.

## Bosse

Wurzelfelder: `schema_version`, `id`, `name`, `source_file`, `core`, `action_overview`, `shared_action_details`, `boss_rules`, `levels`, `post_sections`, `statistics`, `source`.

Bosse bleiben getrennte Entitäten. Jedes der neun Stufenprofile kann mehrere Attributprofile, Kampfwerte, LP-Berechnung, konkrete Aktionen, Resistenzen und strukturierte Phasenwerte besitzen. Insgesamt wurden 306 Attributprofile und 270 nichtleere Phasenwert-Strukturen erkannt. Bossregeln und gemeinsame Aktionsdefinitionen werden nicht in ein Kreaturenschema gepresst, sondern als Bestandteil des vollständigen Rohobjekts beziehungsweise als `shared_rule_json` erhalten.

## Importentscheidungen

- Stabile JSON-IDs sind Primärschlüssel der Regelobjekte.
- `source_file`, SHA-256-`source_hash`, `schema_version` und vollständiges `raw_json` sichern Provenienz.
- Profilstrukturen werden zusätzlich normalisiert, damit Combat-Abfragen keine JSON-Dateien pro Request lesen müssen.
- Unbekannte oder neu hinzukommende Felder gehen wegen `raw_json` nicht verloren.
- Klassenattribut-Anpassungen werden per stabiler Klassen-ID gesät. `wundensammler` ist die tatsächliche JSON-ID; damit wird die im Auftrag abweichend geschriebene Bezeichnung ohne unsicheres Namensmatching eindeutig verknüpft.

