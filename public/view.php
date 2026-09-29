<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Ätherfall DM-Tool</title>
  <link rel="stylesheet" href="assets/css/app.css">
  <link rel="stylesheet" href="assets/css/character-calculations.css">
</head>
<body>
<header class="topbar"><button class="brand" data-nav="home">ÄTHERFALL <span>DM-TOOL</span></button><nav><button data-nav="combat">Combat Tracker</button><button data-nav="characters">Charaktere</button></nav></header>
<main>
  <div id="notice" class="notice hidden"></div>
  <section id="page-home" class="page active">
    <div class="hero"><p class="eyebrow">Lokales Spielleiter-Cockpit</p><h1>Alles im Blick.<br><em>Der Tisch bleibt analog.</em></h1><p>Regelwerkdaten, Charaktere und Kämpfe – schnell, nachvollziehbar und ohne automatische Spielerwürfe.</p></div>
    <div class="launch-grid"><button class="launch-card" data-nav="combat"><span class="sigil">⚔</span><strong>Combat Tracker</strong><small>Initiative, LP, Aktionen und Kampfprotokoll</small></button><button class="launch-card" data-nav="characters"><span class="sigil">◈</span><strong>Charaktere</strong><small>Reduzierte DM-Datensätze und Regelverknüpfungen</small></button></div>
    <div id="stats" class="stats"></div>
  </section>

  <section id="page-characters" class="page">
    <div class="section-head"><div><p class="eyebrow">Verwaltung</p><h2>Charaktere</h2></div><button class="primary" id="new-character">+ Charakter erstellen</button></div>
    <div id="character-list" class="card-list"></div>
    <form id="character-form" class="panel hidden">
      <div class="section-head"><h3 id="character-form-title">Charakter erstellen</h3><button type="button" class="ghost" id="close-character">Schließen</button></div>
      <input type="hidden" name="id"><div class="form-grid four"><label>Name<input name="name" required></label><label>Spieler<input name="player_name" required></label><label>Klasse<select name="class_id" required></select></label><label>Stufe<input name="level" type="number" min="1" value="1" required></label></div>
      <div id="class-adjustments" class="class-note"></div>
      <h4>Attribute <small>Wert und Bonus werden manuell gepflegt; der Modifikator ist automatisch.</small></h4><div id="attribute-grid" class="attributes"></div>
      <div class="form-grid three"><label>Maximale LP · automatisch<input name="max_hp" type="number" value="110" readonly></label><label>Aktuelle LP<input name="current_hp" type="number" inputmode="numeric" min="0" step="1" value="110"></label><label>Kritischer Schaden %<input name="critical_damage_percent" type="number" min="0" step="0.01" value="0" required></label></div>
      <div id="hp-breakdown" class="hp-breakdown">Klasse wählen, um Max LP zu berechnen.</div>
      <div class="split"><div><h4>Fähigkeiten der Klasse</h4><input id="ability-search" placeholder="Fähigkeit suchen …"><div id="ability-options" class="options"></div></div><div><h4>Zauber</h4><div class="filters"><input id="spell-search" placeholder="Zauber suchen …"><select id="spell-element"><option value="">Alle Elemente</option></select><select id="spell-grade"><option value="">Alle Grade</option></select></div><div id="spell-options" class="options"></div></div></div>
      <div class="actions"><button class="primary" type="submit">Charakter speichern</button></div>
    </form>
  </section>

  <section id="page-combat" class="page">
    <div class="section-head"><div><p class="eyebrow">Sitzungsmodus</p><h2>Combat Tracker</h2></div><button class="primary" id="new-encounter">+ Kampf erstellen</button></div>
    <div id="encounter-list" class="card-list"></div>
    <div id="encounter-detail" class="hidden">
      <div class="combat-toolbar"><button class="ghost" id="back-encounters">← Kämpfe</button><h3 id="encounter-title"></h3><div class="round">Runde <strong id="round-number">1</strong></div><button id="previous-turn">← Vorheriger Zug</button><button class="primary" id="next-turn">Nächster Zug →</button></div>
      <div class="combat-layout"><div><div id="initiative-list" class="initiative"></div><form id="participant-form" class="panel compact"><h4>Teilnehmer hinzufügen</h4><div class="form-grid four"><label>Typ<select name="participant_type"><option value="character">Charakter</option><option value="creature">Kreatur</option><option value="boss">Boss</option></select></label><label>Auswahl<select name="reference_id"></select></label><label>Stufe<select name="selected_level"></select></label><label>Initiative<input name="initiative" type="number" value="0"></label></div><label class="optional-hp">Max LP (nur falls Profilwert fehlt)<input name="max_hp" type="number" min="1"></label><button class="primary" type="submit">Hinzufügen</button></form></div>
      <aside><div id="active-card" class="panel"></div><div class="panel calculator"><h4>Combat Calculator <small id="calculator-actor"></small></h4><label>Aktion / Fähigkeit<select id="combat-source"></select></label><label>Ziel<select id="combat-target"></select></label><label>Angriffsformel<input id="combat-attack-formula" placeholder="z. B. W20 + ST-Modifikator"></label><div class="form-grid two"><label>Angriffswürfel (manuell)<input id="attack-roll" type="number"></label><label>Ziel-Verteidigung / Ausweichen gesamt<input id="target-total" type="number"></label></div><label>Schadens-/Effektformel<textarea id="combat-formula" rows="3" placeholder="z. B. 3W10 + 12 + WI-Modifikator"></textarea></label><div id="dice-inputs" class="form-grid two"></div><div class="form-grid two"><label>Magieattribut<select id="magic-attribute"><option value="">Nicht festgelegt</option></select></label><label class="check"><input id="critical" type="checkbox"> Kritischer Treffer <small id="critical-hint"></small></label><label>Resistenz % <small>(leer = automatisch)</small><input id="resistance" type="number"></label><label>Verteidigung <small>(leer = automatisch)</small><input id="defense" type="number"></label></div><div id="manual-values"></div><button class="primary" id="calculate" type="button">Berechnen</button><div id="calculation-result"></div><div class="apply-row hidden" id="apply-row"><button id="apply-damage" type="button">Schaden anwenden</button><button id="apply-healing" type="button">Heilung anwenden</button></div></div></aside></div>
      <div class="panel"><h4>Kampfprotokoll</h4><div id="combat-log" class="log"></div></div>
    </div>
  </section>
</main>
<script src="assets/js/app.js"></script>
</body></html>

