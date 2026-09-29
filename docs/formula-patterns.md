# Tatsächliche Formel- und Würfelmuster

Die Diagnose `php bin/check-formulas.php` scannt Angriff, Berechnung, SG und Rettungswurf aller aktuellen Regelobjekte. Aktueller Bestand: 14.761 nichtleere Felder, 2.351 unterschiedliche isolierbare mathematische Ausdrücke, 7.726 direkt auswertbare Feldvorkommen und 7.035 bewusste Fallback-Fälle. Die Fallback-Zahl enthält vor allem narrative Rettungswurf- und Regeltexte; sie ist daher keine Zahl fehlerhafter mathematischer Formeln.

## Würfelnotationen

Alle aktuell erkannten Notationen, nach Würfelseite gruppiert:

| Würfel | Anzahlen |
|---|---|
| W1 | 1 |
| W4 | 1, 2, 4, 5, 11 |
| W6 | 1–18, 20 (19 kommt nicht vor) |
| W8 | 1–20 |
| W10 | 1–20 |
| W12 | 1–16, 18–20 (17 kommt nicht vor) |
| W20 | 1 |

`W20` und `1W20` sowie `W/w/D/d` werden als dieselbe Gruppe behandelt. Jede Gruppe verlangt ein manuell eingetragenes Gesamtergebnis; die Engine würfelt nie selbst.

## Mathematische Syntax

- Addition und Subtraktion: `+`, `-`, `–`, `—`, `−`
- Multiplikation: `*`, `×`, freistehendes `x`
- Division: `/`, `÷`
- Verschachtelte runde Klammern
- Abrunden: `⌊ Ausdruck ⌋` und `floor(Ausdruck)`
- Aufrunden: `⌈ Ausdruck ⌉` und `ceil(Ausdruck)`
- Konstanten, Dezimalpunkt und Dezimalkomma
- einzelne sowie mehrere voneinander unabhängige Würfelgruppen

## Variablenfamilien

- `Stufe` und `<Klassenname>-Stufe`
- `Zaubergrad`
- alle elf Attribute als ausgeschriebener Name oder Kürzel, jeweils `-Wert`, `-Modifikator`, `-Bonus`
- Genitivvarianten wie `Geschicklichkeits-Modifikator`
- `Magieattribut-Modifikator` und `Magieattribut-Bonus`
- manuelle Größen wie `Waffenschaden`, Ressourcenstände, Trefferzahlen oder spezielle Bosswerte

Nicht bekannte Variablen werden nicht zu null. Die API liefert sie in `missing`; die Oberfläche erzeugt „Zusätzlicher Wert benötigt“.

## Bewusster manueller Fallback

Folgende tatsächlich vorkommende Muster werden nicht blind automatisiert:

- Regeltext ohne isolierbaren mathematischen Ausdruck
- Rettungswurftexte, die nur Erfolg/Fehlschlag beschreiben
- mehrere zeitlich getrennte Wirkungen in einem Absatz (Sofortschaden plus späterer Schaden/Heilung)
- verzweigte Bedingungen („ab Stufe 21“, „wenn mindestens …“)
- prozentuale LP-/Ressourcenkosten, wenn die Bezugsgröße zur Laufzeit fehlt
- Schadenspipelines mit „normalem Waffenschaden“, bis der DM den Waffenschaden manuell ergänzt
- Tabellen-/Auswahlwirkungen und regelabhängige Maxima/Minima ohne eindeutige Rechenreihenfolge

Die mathematischen Teilstücke können einzeln ausgewertet werden. Nicht mathematische Teile bleiben sichtbar; ein manueller Endwert kann angewendet und wird im Combat Log festgehalten.

