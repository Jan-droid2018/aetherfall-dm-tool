'use strict';

// Character skills are intentionally kept separate from the combat UI. This
// layer only renders/edit-packs persisted value + bonus; the server derives
// the modifier and remains authoritative.
state.characterSkills = [];
state.skillsInitializedAt = null;

const SKILL_CATEGORY_LABELS = Object.freeze({
  social: 'Soziale Fertigkeiten',
  physical: 'Körperliche Fertigkeiten',
  knowledge: 'Wissensfertigkeiten',
  craft: 'Handwerksfertigkeiten',
  everyday: 'Alltagsfertigkeiten',
});

function skillModifier(value) {
  const number = Number(value);
  return Number.isInteger(number) && number >= CHARACTER_RULES.MIN_ATTRIBUTE && number <= CHARACTER_RULES.MAX_ATTRIBUTE
    ? calculateAttributeModifier(number)
    : null;
}

function renderSkillModifier(row) {
  const output = row.querySelector('[data-skill-modifier]');
  if (!output) return;
  const value = Number(row.querySelector('[data-skill-field="value"]')?.value);
  const modifier = skillModifier(value);
  output.textContent = modifier === null ? '—' : signed(modifier);
}

function renderCharacterSkills(skills = state.characterSkills, initializedAt = state.skillsInitializedAt) {
  const root = $('#character-skills-list');
  const status = $('#character-skills-status');
  const initialize = $('#initialize-character-skills');
  const characterId = $('#character-form')?.elements.id?.value || '';
  const initialized = Boolean(initializedAt) && Array.isArray(skills) && skills.length > 0;
  state.characterSkills = Array.isArray(skills) ? skills : [];
  state.skillsInitializedAt = initializedAt || null;

  if (status) {
    status.textContent = initialized
      ? `Einmalig initialisiert${initializedAt ? ` · ${new Date(initializedAt.replace(' ', 'T')).toLocaleDateString('de-DE')}` : ''}. Attribute ändern die Fertigkeitswerte nicht automatisch.`
      : characterId ? 'Noch nicht initialisiert.' : 'Neue Charaktere werden nach dem Speichern automatisch initialisiert.';
  }
  if (initialize) initialize.classList.toggle('hidden', !characterId || initialized);
  if (!root) return;
  if (!initialized) {
    root.innerHTML = '<p class="field-hint">Noch keine Fertigkeitswerte vorhanden. Bei einem bestehenden Charakter kannst du sie einmalig aus den aktuellen Attributen berechnen und festschreiben.</p>';
    return;
  }

  const groups = {};
  skills.forEach(skill => (groups[skill.category] ||= []).push(skill));
  root.innerHTML = Object.entries(SKILL_CATEGORY_LABELS).map(([category, label]) => {
    const rows = groups[category] || [];
    if (!rows.length) return '';
    return `<section class="skill-category"><h5>${esc(label)}</h5><div class="skill-table-head"><span>Fertigkeit</span><span>Prim.</span><span>Sek.</span><span>Wert</span><span>Mod.</span><span>Bonus</span></div>${rows.map(skill => `<div class="skill-row" data-character-skill="${esc(skill.skill_definition_id)}"><strong title="${esc(skill.name)}">${esc(skill.name)}</strong><span class="skill-attributes">${esc(skill.primary_attribute)}</span><span class="skill-attributes">${esc(skill.secondary_attribute)}</span><label><span class="sr-only">Wert</span><input data-skill-field="value" type="number" min="1" max="100" step="1" inputmode="numeric" value="${esc(skill.value)}"></label><output class="skill-modifier" data-skill-modifier title="Automatisch aus dem Fertigkeitswert berechnet">${signed(skill.modifier)}</output><label><span class="sr-only">Bonus</span><input data-skill-field="bonus" type="number" step="1" inputmode="numeric" value="${esc(skill.bonus)}"></label></div>`).join('')}</section>`;
  }).join('');
  root.querySelectorAll('[data-character-skill]').forEach(row => {
    row.querySelectorAll('input').forEach(input => input.addEventListener('input', () => {
      floorIntegerInput(input);
      renderSkillModifier(row);
      const skill = state.characterSkills.find(item => String(item.skill_definition_id) === row.dataset.characterSkill);
      if (skill) skill[input.dataset.skillField] = Number(input.value);
    }));
  });
}

function collectCharacterSkills() {
  return [...document.querySelectorAll('[data-character-skill]')].map(row => ({
    skill_definition_id: row.dataset.characterSkill,
    value: Number(row.querySelector('[data-skill-field="value"]')?.value),
    bonus: Number(row.querySelector('[data-skill-field="bonus"]')?.value),
  }));
}

async function loadCharacterSkills(characterId) {
  const character = await api(`characters/${characterId}`);
  renderCharacterSkills(character.skills || [], character.skills_initialized_at || null);
}

const skillOpenCharacter = openCharacter;
openCharacter = function () {
  skillOpenCharacter();
  renderCharacterSkills([], null);
};
$('#new-character').onclick = openCharacter;

const skillEditCharacter = editCharacter;
editCharacter = async function (id) {
  await skillEditCharacter(id);
  try {
    await loadCharacterSkills(id);
  } catch (error) {
    notify('Fertigkeiten konnten nicht geladen werden: ' + error.message, true);
  }
};

const skillApi = api;
api = async function (path, options = {}) {
  if (/^characters\/\d+$/.test(path) && options.body && state.characterSkills?.length) {
    try {
      const payload = JSON.parse(options.body);
      if (payload && !Object.hasOwn(payload, 'skills')) {
        payload.skills = collectCharacterSkills();
        options = { ...options, body: JSON.stringify(payload) };
      }
    } catch (_) {
      // The original API wrapper will report malformed request bodies.
    }
  }
  return skillApi(path, options);
};

$('#initialize-character-skills')?.addEventListener('click', async () => {
  const id = $('#character-form')?.elements.id?.value;
  if (!id) return;
  const button = $('#initialize-character-skills');
  if (button) button.disabled = true;
  try {
    await api(`characters/${id}/skills/initialize`, { method: 'POST', body: '{}' });
    await loadCharacterSkills(id);
    notify('47 Fertigkeiten wurden einmalig berechnet und festgeschrieben.');
  } catch (error) {
    notify(error.message, true);
  } finally {
    if (button) button.disabled = false;
  }
});

renderCharacterSkills([], null);
