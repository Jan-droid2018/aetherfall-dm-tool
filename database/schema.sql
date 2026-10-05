SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS attribute_definitions (
  code VARCHAR(2) PRIMARY KEY,
  name VARCHAR(40) NOT NULL UNIQUE,
  sort_order TINYINT UNSIGNED NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS classes (
  id VARCHAR(120) PRIMARY KEY,
  name VARCHAR(190) NOT NULL UNIQUE,
  schema_version VARCHAR(80) NOT NULL,
  source_file VARCHAR(255) NOT NULL,
  source_hash CHAR(64) NOT NULL,
  raw_json LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS class_resources (
  class_id VARCHAR(120) NOT NULL,
  resource_id VARCHAR(120) NOT NULL,
  name VARCHAR(190) NOT NULL,
  form TEXT NULL,
  maximum_formula TEXT NULL,
  start_value TEXT NULL,
  base_generation TEXT NULL,
  generation TEXT NULL,
  consumption TEXT NULL,
  relief TEXT NULL,
  persistence TEXT NULL,
  recovery TEXT NULL,
  stacking TEXT NULL,
  transfer TEXT NULL,
  loss_decay TEXT NULL,
  visibility TEXT NULL,
  multiclass_boundary TEXT NULL,
  base_function TEXT NULL,
  rounding TEXT NULL,
  source_file VARCHAR(255) NULL,
  source_hash CHAR(64) NULL,
  raw_json LONGTEXT NOT NULL,
  PRIMARY KEY (class_id, resource_id),
  CONSTRAINT fk_resource_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS class_actions (
  id VARCHAR(220) PRIMARY KEY,
  class_id VARCHAR(120) NOT NULL,
  external_id VARCHAR(190) NOT NULL,
  name VARCHAR(255) NOT NULL,
  action_type VARCHAR(80) NOT NULL DEFAULT 'class_action',
  unlock_level SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  attack_formula TEXT NULL,
  damage_formula TEXT NULL,
  formula_variables_json TEXT NULL,
  damage_type VARCHAR(190) NULL,
  resource_cost TEXT NULL,
  resource_gain TEXT NULL,
  weapon_mode VARCHAR(190) NULL,
  description TEXT NULL,
  source_file VARCHAR(255) NULL,
  source_hash CHAR(64) NULL,
  raw_json LONGTEXT NOT NULL,
  UNIQUE KEY uq_class_action_external (class_id, external_id),
  INDEX idx_class_actions_level (class_id, unlock_level),
  CONSTRAINT fk_class_action_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS weapon_combat_profiles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  weapon_id VARCHAR(190) NOT NULL,
  profile_key VARCHAR(80) NOT NULL,
  label VARCHAR(190) NOT NULL,
  handling VARCHAR(190) NULL,
  attack_formula TEXT NULL,
  attack_attribute VARCHAR(190) NULL,
  damage_formula TEXT NULL,
  damage_type VARCHAR(190) NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  raw_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_weapon_combat_profile (weapon_id, profile_key),
  INDEX idx_weapon_profile_weapon (weapon_id),
  CONSTRAINT fk_weapon_profile_weapon FOREIGN KEY (weapon_id) REFERENCES weapons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS class_weapon_profiles (
  id VARCHAR(220) PRIMARY KEY,
  class_id VARCHAR(120) NOT NULL,
  profile_name VARCHAR(190) NOT NULL,
  unlock_level SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  start_die VARCHAR(80) NULL,
  attack_formula TEXT NULL,
  damage_formula TEXT NULL,
  damage_type VARCHAR(190) NULL,
  handling VARCHAR(80) NULL,
  range_text VARCHAR(190) NULL,
  weight VARCHAR(80) NULL,
  description TEXT NULL,
  source_file VARCHAR(255) NULL,
  source_hash CHAR(64) NULL,
  raw_json LONGTEXT NOT NULL,
  UNIQUE KEY uq_class_weapon_profile (class_id, profile_name),
  CONSTRAINT fk_class_weapon_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS class_attribute_adjustments (
  class_id VARCHAR(120) NOT NULL,
  role ENUM('primary','secondary','penalty') NOT NULL,
  attribute_code VARCHAR(2) NOT NULL,
  adjustment SMALLINT NOT NULL,
  PRIMARY KEY (class_id, role),
  CONSTRAINT fk_adjust_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  CONSTRAINT fk_adjust_attr FOREIGN KEY (attribute_code) REFERENCES attribute_definitions(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS abilities (
  id VARCHAR(190) PRIMARY KEY,
  class_id VARCHAR(120) NOT NULL,
  unlock_level SMALLINT UNSIGNED NOT NULL,
  number SMALLINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  type VARCHAR(190) NULL,
  focus VARCHAR(255) NULL,
  action_cost TEXT NULL,
  trigger_requirement TEXT NULL,
  costs TEXT NULL,
  class_resource TEXT NULL,
  usage_limit TEXT NULL,
  range_text TEXT NULL,
  target_text TEXT NULL,
  area_text TEXT NULL,
  duration_text TEXT NULL,
  attack_roll TEXT NULL,
  saving_throw TEXT NULL,
  dc TEXT NULL,
  attribute_reference TEXT NULL,
  damage_type TEXT NULL,
  calculation TEXT NULL,
  effect MEDIUMTEXT NULL,
  target_effect MEDIUMTEXT NULL,
  status_effect TEXT NULL,
  stacking TEXT NULL,
  end_condition TEXT NULL,
  scaling TEXT NULL,
  source_file VARCHAR(255) NOT NULL,
  source_hash CHAR(64) NOT NULL,
  raw_json LONGTEXT NOT NULL,
  INDEX idx_abilities_class_level (class_id, unlock_level),
  CONSTRAINT fk_ability_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS spell_elements (
  id VARCHAR(120) PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  schema_version VARCHAR(80) NOT NULL,
  source_file VARCHAR(255) NOT NULL,
  source_hash CHAR(64) NOT NULL,
  raw_json LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS spells (
  id VARCHAR(190) PRIMARY KEY,
  element_id VARCHAR(120) NOT NULL,
  grade TINYINT UNSIGNED NOT NULL,
  number TINYINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  effect_type VARCHAR(190) NULL,
  cast_time TEXT NULL,
  trigger_requirement TEXT NULL,
  mana_cost_percent DECIMAL(8,2) NULL,
  mana_cost_raw VARCHAR(190) NULL,
  usage_limit TEXT NULL,
  range_text TEXT NULL,
  target_text TEXT NULL,
  area_text TEXT NULL,
  duration_text TEXT NULL,
  concentration TINYINT(1) NULL,
  magic_attribute TEXT NULL,
  attack_roll TEXT NULL,
  saving_throw TEXT NULL,
  dc TEXT NULL,
  damage_type TEXT NULL,
  calculation MEDIUMTEXT NULL,
  rule_effect MEDIUMTEXT NULL,
  target_effect MEDIUMTEXT NULL,
  status_effect TEXT NULL,
  stacking TEXT NULL,
  end_condition TEXT NULL,
  upcast TEXT NULL,
  source_file VARCHAR(255) NOT NULL,
  source_hash CHAR(64) NOT NULL,
  raw_json LONGTEXT NOT NULL,
  INDEX idx_spells_filter (element_id, grade, effect_type),
  INDEX idx_spells_name (name),
  CONSTRAINT fk_spell_element FOREIGN KEY (element_id) REFERENCES spell_elements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS creatures (
  id VARCHAR(160) PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  challenge_rating DECIMAL(8,2) NULL,
  encounter_rank VARCHAR(120) NULL,
  archetype VARCHAR(190) NULL,
  elemental_affinity VARCHAR(190) NULL,
  schema_version VARCHAR(80) NOT NULL,
  source_file VARCHAR(255) NOT NULL,
  source_hash CHAR(64) NOT NULL,
  raw_json LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS creature_levels (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  creature_id VARCHAR(160) NOT NULL,
  level SMALLINT UNSIGNED NOT NULL,
  max_hp INT UNSIGNED NULL,
  physical_defense DECIMAL(10,2) NULL,
  magic_defense DECIMAL(10,2) NULL,
  attributes_json LONGTEXT NOT NULL,
  combat_values_json LONGTEXT NOT NULL,
  hp_calculation_json LONGTEXT NOT NULL,
  elemental_resistances_json LONGTEXT NOT NULL,
  raw_json LONGTEXT NOT NULL,
  UNIQUE KEY uq_creature_level (creature_id, level),
  CONSTRAINT fk_level_creature FOREIGN KEY (creature_id) REFERENCES creatures(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS creature_actions (
  id VARCHAR(220) PRIMARY KEY,
  creature_id VARCHAR(160) NOT NULL,
  level SMALLINT UNSIGNED NOT NULL,
  number SMALLINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  type VARCHAR(190) NULL,
  attack_data TEXT NULL,
  damage_data TEXT NULL,
  damage_type VARCHAR(190) NULL,
  defense_save TEXT NULL,
  range_text TEXT NULL,
  notes TEXT NULL,
  raw_json LONGTEXT NOT NULL,
  INDEX idx_creature_actions (creature_id, level),
  CONSTRAINT fk_action_creature FOREIGN KEY (creature_id) REFERENCES creatures(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bosses (
  id VARCHAR(160) PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  challenge_rating DECIMAL(8,2) NULL,
  encounter_rank VARCHAR(120) NULL,
  archetype VARCHAR(190) NULL,
  elemental_affinity VARCHAR(190) NULL,
  phase_count SMALLINT UNSIGNED NULL,
  schema_version VARCHAR(80) NOT NULL,
  source_file VARCHAR(255) NOT NULL,
  source_hash CHAR(64) NOT NULL,
  raw_json LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS boss_levels (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  boss_id VARCHAR(160) NOT NULL,
  level SMALLINT UNSIGNED NOT NULL,
  max_hp INT UNSIGNED NULL,
  physical_defense DECIMAL(10,2) NULL,
  magic_defense DECIMAL(10,2) NULL,
  attribute_profiles_json LONGTEXT NOT NULL,
  combat_values_json LONGTEXT NOT NULL,
  hp_calculation_json LONGTEXT NOT NULL,
  elemental_resistances_json LONGTEXT NOT NULL,
  phase_values_json LONGTEXT NOT NULL,
  raw_json LONGTEXT NOT NULL,
  UNIQUE KEY uq_boss_level (boss_id, level),
  CONSTRAINT fk_level_boss FOREIGN KEY (boss_id) REFERENCES bosses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS boss_actions (
  id VARCHAR(220) PRIMARY KEY,
  boss_id VARCHAR(160) NOT NULL,
  level SMALLINT UNSIGNED NOT NULL,
  number SMALLINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  attack_data TEXT NULL,
  damage_data TEXT NULL,
  notes TEXT NULL,
  shared_rule_json LONGTEXT NULL,
  raw_json LONGTEXT NOT NULL,
  INDEX idx_boss_actions (boss_id, level),
  CONSTRAINT fk_action_boss FOREIGN KEY (boss_id) REFERENCES bosses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS characters (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  nickname VARCHAR(190) NULL,
  player_name VARCHAR(190) NOT NULL,
  class_id VARCHAR(120) NOT NULL,
  level SMALLINT UNSIGNED NOT NULL,
  max_hp INT UNSIGNED NOT NULL,
  current_hp INT UNSIGNED NOT NULL,
  critical_damage_percent DECIMAL(8,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_character_class FOREIGN KEY (class_id) REFERENCES classes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS character_classes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  character_id BIGINT UNSIGNED NOT NULL,
  class_id VARCHAR(120) NOT NULL,
  role VARCHAR(20) NOT NULL,
  class_level SMALLINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_character_class_role (character_id, role),
  UNIQUE KEY uq_character_class_id (character_id, class_id),
  CONSTRAINT fk_character_class_character FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE,
  CONSTRAINT fk_character_class_class FOREIGN KEY (class_id) REFERENCES classes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS character_class_resources (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  character_class_id BIGINT UNSIGNED NOT NULL,
  class_resource_id VARCHAR(120) NOT NULL,
  current_value DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_character_class_resource (character_class_id, class_resource_id),
  CONSTRAINT fk_character_resource_class FOREIGN KEY (character_class_id) REFERENCES character_classes(id) ON DELETE CASCADE,
  INDEX idx_character_resource_definition (class_resource_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS character_attributes (
  character_id BIGINT UNSIGNED NOT NULL,
  attribute_code VARCHAR(2) NOT NULL,
  value DECIMAL(12,2) NOT NULL,
  modifier DECIMAL(12,2) NOT NULL,
  bonus DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (character_id, attribute_code),
  CONSTRAINT fk_char_attr_character FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE,
  CONSTRAINT fk_char_attr_definition FOREIGN KEY (attribute_code) REFERENCES attribute_definitions(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS character_abilities (
  character_id BIGINT UNSIGNED NOT NULL,
  ability_id VARCHAR(190) NOT NULL,
  class_role VARCHAR(20) NULL,
  ability_level SMALLINT UNSIGNED NULL,
  slot_number TINYINT UNSIGNED NULL,
  PRIMARY KEY (character_id, ability_id),
  UNIQUE KEY uq_character_ability_slot (character_id, class_role, ability_level, slot_number),
  CONSTRAINT fk_char_ability_character FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE,
  CONSTRAINT fk_char_ability_ability FOREIGN KEY (ability_id) REFERENCES abilities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS character_spells (
  character_id BIGINT UNSIGNED NOT NULL,
  spell_id VARCHAR(190) NOT NULL,
  PRIMARY KEY (character_id, spell_id),
  CONSTRAINT fk_char_spell_character FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE,
  CONSTRAINT fk_char_spell_spell FOREIGN KEY (spell_id) REFERENCES spells(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS character_spell_slots (
  character_id BIGINT UNSIGNED NOT NULL,
  grade TINYINT UNSIGNED NOT NULL,
  slot_number SMALLINT UNSIGNED NOT NULL,
  spell_id VARCHAR(190) NULL,
  PRIMARY KEY (character_id, grade, slot_number),
  CONSTRAINT fk_spell_slot_character FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE,
  CONSTRAINT fk_spell_slot_spell FOREIGN KEY (spell_id) REFERENCES spells(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS weapons (
  id VARCHAR(190) PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  display_name VARCHAR(255) NULL,
  subtitle VARCHAR(255) NULL,
  base_weapon_type VARCHAR(190) NULL,
  category VARCHAR(190) NULL,
  quality VARCHAR(80) NULL,
  item_level SMALLINT UNSIGNED NULL,
  is_magical TINYINT(1) NOT NULL DEFAULT 0,
  is_elemental TINYINT(1) NOT NULL DEFAULT 0,
  elements_json TEXT NULL,
  core_die VARCHAR(80) NULL,
  handling VARCHAR(80) NULL,
  range_text VARCHAR(190) NULL,
  damage_type VARCHAR(190) NULL,
  weight VARCHAR(80) NULL,
  attack_attribute VARCHAR(190) NULL,
  attack_formula TEXT NULL,
  damage_formula TEXT NULL,
  critical_modification TEXT NULL,
  special_properties TEXT NULL,
  active_ability TEXT NULL,
  class_restriction VARCHAR(190) NULL,
  appearance TEXT NULL,
  schema_version VARCHAR(80) NOT NULL,
  source_file VARCHAR(255) NOT NULL,
  source_hash CHAR(64) NOT NULL,
  raw_json LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_weapon_search (name, category, quality),
  INDEX idx_weapon_filters (quality, category, item_level, is_magical)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS character_weapon_slots (
  character_id BIGINT UNSIGNED NOT NULL,
  slot VARCHAR(20) NOT NULL,
  weapon_id VARCHAR(190) NULL,
  PRIMARY KEY (character_id, slot),
  CONSTRAINT fk_weapon_slot_character FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE,
  CONSTRAINT fk_weapon_slot_weapon FOREIGN KEY (weapon_id) REFERENCES weapons(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS armors (
  id VARCHAR(190) PRIMARY KEY,
  external_id VARCHAR(190) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  display_name VARCHAR(255) NULL,
  item_kind VARCHAR(80) NOT NULL,
  base_item_name VARCHAR(255) NULL,
  armor_slot VARCHAR(20) NULL,
  armor_archetype VARCHAR(190) NULL,
  quality VARCHAR(80) NULL,
  item_level SMALLINT UNSIGNED NULL,
  is_magical TINYINT(1) NOT NULL DEFAULT 0,
  physical_defense DECIMAL(12,2) NULL,
  magical_defense DECIMAL(12,2) NULL,
  shield_class VARCHAR(80) NULL,
  resistances_json LONGTEXT NULL,
  elements_json LONGTEXT NULL,
  special_properties LONGTEXT NULL,
  active_ability LONGTEXT NULL,
  class_binding LONGTEXT NULL,
  appearance LONGTEXT NULL,
  schema_version VARCHAR(80) NOT NULL,
  source_file VARCHAR(255) NOT NULL,
  source_hash CHAR(64) NOT NULL,
  raw_json LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_armor_slot (armor_slot),
  INDEX idx_armor_filter (quality, armor_archetype, item_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS magic_foci (
  id VARCHAR(190) PRIMARY KEY,
  external_id VARCHAR(190) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  subtitle VARCHAR(255) NULL,
  display_name VARCHAR(255) NULL,
  item_type VARCHAR(190) NULL,
  base_focus_type VARCHAR(190) NULL,
  category VARCHAR(190) NULL,
  quality VARCHAR(80) NULL,
  item_level SMALLINT UNSIGNED NULL,
  is_magical TINYINT(1) NOT NULL DEFAULT 0,
  standard_magic_attack TEXT NULL,
  magic_attack TEXT NULL,
  final_magic_attack TEXT NULL,
  handling VARCHAR(190) NULL,
  magic_attribute VARCHAR(190) NULL,
  weight VARCHAR(80) NULL,
  element_binding LONGTEXT NULL,
  is_elemental TINYINT(1) NOT NULL DEFAULT 0,
  attack_roll TEXT NULL,
  critical_modification TEXT NULL,
  class_binding LONGTEXT NULL,
  active_ability LONGTEXT NULL,
  special_properties LONGTEXT NULL,
  effect_calculation LONGTEXT NULL,
  spell_interaction LONGTEXT NULL,
  lore LONGTEXT NULL,
  special_rules LONGTEXT NULL,
  schema_version VARCHAR(80) NOT NULL,
  source_file VARCHAR(255) NOT NULL,
  source_hash CHAR(64) NOT NULL,
  raw_json LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_magic_focus_filter (quality, category, item_level, is_elemental)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS character_armor_slots (
  character_id BIGINT UNSIGNED NOT NULL,
  slot VARCHAR(20) NOT NULL,
  armor_id VARCHAR(190) NOT NULL,
  PRIMARY KEY (character_id, slot),
  CONSTRAINT fk_armor_slot_character FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE,
  CONSTRAINT fk_armor_slot_armor FOREIGN KEY (armor_id) REFERENCES armors(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS character_magic_focus (
  character_id BIGINT UNSIGNED PRIMARY KEY,
  magic_focus_id VARCHAR(190) NOT NULL,
  CONSTRAINT fk_character_magic_focus_character FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE,
  CONSTRAINT fk_character_magic_focus_focus FOREIGN KEY (magic_focus_id) REFERENCES magic_foci(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS combat_encounters (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NULL,
  status ENUM('active','finished') NOT NULL DEFAULT 'active',
  current_round INT UNSIGNED NOT NULL DEFAULT 1,
  current_turn_index INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS combat_participants (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  encounter_id BIGINT UNSIGNED NOT NULL,
  participant_type ENUM('character','creature','boss') NOT NULL,
  reference_id VARCHAR(190) NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  initiative DECIMAL(10,2) NOT NULL DEFAULT 0,
  selected_level SMALLINT UNSIGNED NULL,
  selected_phase VARCHAR(190) NULL,
  max_hp INT UNSIGNED NOT NULL,
  current_hp INT UNSIGNED NOT NULL,
  current_shield DECIMAL(12,2) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  runtime_state_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_participant_order (encounter_id, initiative, sort_order),
  CONSTRAINT fk_participant_encounter FOREIGN KEY (encounter_id) REFERENCES combat_encounters(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS combat_participant_resources (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  combat_participant_id BIGINT UNSIGNED NOT NULL,
  character_class_id BIGINT UNSIGNED NULL,
  class_resource_id VARCHAR(120) NOT NULL,
  current_value DECIMAL(12,2) NOT NULL DEFAULT 0,
  max_value_snapshot DECIMAL(12,2) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_participant_resource (combat_participant_id, character_class_id, class_resource_id),
  CONSTRAINT fk_participant_resource_participant FOREIGN KEY (combat_participant_id) REFERENCES combat_participants(id) ON DELETE CASCADE,
  CONSTRAINT fk_participant_resource_character_class FOREIGN KEY (character_class_id) REFERENCES character_classes(id) ON DELETE SET NULL,
  INDEX idx_participant_resource_definition (class_resource_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS combat_resource_transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  combat_participant_id BIGINT UNSIGNED NOT NULL,
  execution_id VARCHAR(190) NOT NULL,
  character_class_id BIGINT UNSIGNED NULL,
  class_resource_id VARCHAR(120) NOT NULL,
  delta DECIMAL(12,2) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_resource_execution (combat_participant_id, execution_id, character_class_id, class_resource_id),
  CONSTRAINT fk_resource_transaction_participant FOREIGN KEY (combat_participant_id) REFERENCES combat_participants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS combat_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  encounter_id BIGINT UNSIGNED NOT NULL,
  round_number INT UNSIGNED NOT NULL,
  participant_id BIGINT UNSIGNED NULL,
  target_participant_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(60) NOT NULL,
  source_type VARCHAR(60) NULL,
  source_id VARCHAR(190) NULL,
  message TEXT NOT NULL,
  calculation_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_log_encounter FOREIGN KEY (encounter_id) REFERENCES combat_encounters(id) ON DELETE CASCADE,
  CONSTRAINT fk_log_actor FOREIGN KEY (participant_id) REFERENCES combat_participants(id) ON DELETE SET NULL,
  CONSTRAINT fk_log_target FOREIGN KEY (target_participant_id) REFERENCES combat_participants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  status ENUM('running','success','failed') NOT NULL,
  counts_json LONGTEXT NULL,
  warnings_json LONGTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
