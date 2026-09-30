# Datenbankschema

## Regelwerk

- `attribute_definitions`: exakt elf zentrale Attributcodes.
- `classes`, `class_resources`, `class_attribute_adjustments`, `abilities`.
- `spell_elements`, `spells`.
- `creatures`, `creature_levels`, `creature_actions`.
- `bosses`, `boss_levels`, `boss_actions`.
- `import_runs`: Zeit, Ergebnis, echte Counts und Warnungen.

Alle zentralen Regelobjekte nutzen stabile String-IDs. Normalisierte Felder dienen Suche und Combat; `raw_json` ist `LONGTEXT` für breite MariaDB-/XAMPP-Kompatibilität.

## Charaktere

- `characters`: nur Name, Spieler, Klasse, Stufe, Max/Aktuell-LP und kritischer Schaden in Prozent. `max_hp` wird beim Speichern aus Klasse, Stufe und Attributen abgeleitet; `current_hp` bleibt ein separater Zustand.
- `character_attributes`: Unique/Primary Key aus Charakter und Attribut. Wert und Bonus werden manuell gepflegt; der gespeicherte Modifikator ist ein serverseitig berechneter, abgeleiteter Wert.
- `character_abilities`, `character_spells`: reine Relationstabellen.

Für Spielercharaktere gelten ganzzahlige Attributwerte von 1 bis 100 sowie ganzzahlige Attributboni. Der gespeicherte Modifikator ist `floor((Wert - 10) / 2)`. Maximale LP sind `floor(100 + ((Haupt-Mod + Haupt-Bonus) × 10) + ((Sekundär-Mod + Sekundär-Bonus) × 5) + (Stufe × 10))`. Haupt- und Sekundärattribut stammen aus `class_attribute_adjustments`; die dort hinterlegten Anpassungswerte +2/+1/−2 werden dabei nicht auf den Attributwert angewendet.

## Combat

- `combat_encounters`: Status, Runde und aktueller sortierter Zugindex.
- `combat_participants`: polymorphe Referenz, gewähltes Profil, Initiative, Phase und unabhängiger LP-Snapshot.
- `combat_log`: Runde, Akteur, Ziel, Quelle, lesbare Meldung und vollständiger Berechnungs-Breakdown.

Foreign Keys sichern echte Relationen. Charakteränderungen, Import und Combat-State-Änderungen verwenden Transaktionen.

# Charakterklassen

`character_classes` ist die autoritative Zuordnung der Klassenrollen und individuellen Klassenstufen. `characters.level` bleibt als abgeleiteter Kompatibilitätswert erhalten und entspricht der höchsten Klassenstufe.

`character_spell_slots` speichert optionale Zauberslots getrennt nach Grad (1–10); Zauber werden nicht automatisch durch Klassenstufen begrenzt.
