# Formel-Engine

`FormulaEngine` normalisiert Schreibvarianten, tokenisiert ausschließlich erlaubte Syntax und wertet sie mit einem rekursiven Parser aus. Es gibt kein `eval`, `new Function` oder dynamisch ausgeführten Regeltext.

## Kontext

- `variables`: Stufe, Attribute, Zaubergrad und explizite Laufzeitwerte.
- `dice`: vom DM eingetragene Gesamtergebnisse pro Würfelnotation.
- `magic_attribute`: löst die beiden generischen Magieattributvariablen erst nach manueller Auswahl auf.

Das Ergebnis enthält Originalformel, isolierten Ausdruck, Endwert, fehlende Werte und Breakdown. Fehlt ein Wert, bleibt `value = null`. Angriffsausdrücke werden getrennt ausgewertet; bei einem Gleichstand wird mangels globaler Gleichstandsregel keine Trefferentscheidung erfunden. Für Kreaturen- und Bossziele werden eindeutig erkennbare Elementresistenzen/Verwundbarkeiten sowie physische oder magische Profilverteidigung automatisch vorgeschlagen. Manuelle Felder überschreiben diese Werte sichtbar. Die zentrale `CombatCalculator`-Pipeline bildet zunächst den vollständigen normalen Schaden. `CriticalDamageCalculator` addiert danach bei aktivierter Checkbox einmalig `normaler Schaden × (critical_damage_percent / 100)`. Erst anschließend folgen zielabhängige Resistenz und Verteidigung. Schaden und Heilung verändern LP erst über den separaten Anwenden-Endpunkt; Heilung verwendet die normale Formelwirkung und erhält keinen Krit-Bonus.

Offene Regelentscheidung: Die JSONs legen keine universell eindeutige Reihenfolge für sämtliche Verteidigungs-, Resistenz-, Verwundbarkeits- und Sondermodifikatoren fest. V1 verwendet die dokumentierte Pipeline Rohwert → manuelle Boni/Mali → Krit → Resistenz → Verteidigung und zeigt jeden Schritt; abweichende Fälle verwenden Overrides. Intern wird präzise gerechnet, der bestehende finale Schadenswert wird auf zwei Nachkommastellen gerundet.

