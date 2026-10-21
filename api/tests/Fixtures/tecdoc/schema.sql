CREATE TABLE manufacturer (
  manufacturer_id INTEGER PRIMARY KEY,
  entry_idx INTEGER NOT NULL,
  short_name TEXT,
  name TEXT NOT NULL,
  has_passenger_car INTEGER NOT NULL,
  has_commercial_vehicle INTEGER NOT NULL,
  has_motorbike INTEGER NOT NULL,
  is_motorbike_maker INTEGER NOT NULL,
  is_vgl INTEGER NOT NULL,
  is_atv INTEGER NOT NULL,
  has_link INTEGER NOT NULL,
  UNIQUE (entry_idx)
);
CREATE TABLE vehicle_model (
  model_id INTEGER PRIMARY KEY,
  manufacturer_id INTEGER,
  name TEXT NOT NULL,
  year_from INTEGER,
  month_from INTEGER,
  year_to INTEGER,
  month_to INTEGER,
  is_passenger_car INTEGER NOT NULL,
  is_commercial_vehicle INTEGER NOT NULL,
  is_motorbike INTEGER NOT NULL,
  is_atv INTEGER NOT NULL,
  is_transporter INTEGER NOT NULL,
  has_link INTEGER NOT NULL,
  has_country_spec INTEGER NOT NULL,
  mod_slot INTEGER NOT NULL,
  UNIQUE (mod_slot)
);
CREATE TABLE vehicle_model_text (
  model_id INTEGER NOT NULL,
  language_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  PRIMARY KEY (model_id, language_id)
) WITHOUT ROWID;
CREATE TABLE pc_type (
  type_id INTEGER PRIMARY KEY,
  model_id INTEGER NOT NULL,
  description TEXT,
  year_from INTEGER,
  month_from INTEGER,
  year_to INTEGER,
  month_to INTEGER,
  power_kw INTEGER,
  power_ps INTEGER,
  capacity_tax_ccm INTEGER,
  capacity_tech_ccm INTEGER,
  capacity_litre100 INTEGER,
  cylinders INTEGER,
  valves_per_cylinder INTEGER,
  engine_type INTEGER,
  drive_type INTEGER,
  brake_type INTEGER,
  brake_system INTEGER,
  gear_type INTEGER,
  body_type INTEGER,
  fuel_type INTEGER,
  catalysator_type INTEGER,
  fuel_mixture INTEGER,
  abs_code INTEGER,
  has_abs INTEGER,
  asr_code INTEGER,
  has_asr INTEGER,
  has_link INTEGER NOT NULL,
  has_country_override INTEGER NOT NULL,
  has_country_restriction INTEGER NOT NULL,
  entry_idx INTEGER NOT NULL,
  UNIQUE (entry_idx)
);
CREATE TABLE pc_type_text (
  type_id INTEGER NOT NULL,
  language_id INTEGER NOT NULL,
  description TEXT NOT NULL,
  PRIMARY KEY (type_id, language_id)
) WITHOUT ROWID;
CREATE TABLE pc_type_engine (
  type_id INTEGER NOT NULL,
  seq INTEGER NOT NULL,
  engine_id INTEGER NOT NULL,
  year_from INTEGER,
  month_from INTEGER,
  year_to INTEGER,
  month_to INTEGER,
  country_id INTEGER,
  is_exclusion INTEGER NOT NULL,
  PRIMARY KEY (type_id, seq)
) WITHOUT ROWID;
CREATE TABLE engine (
  engine_id INTEGER PRIMARY KEY,
  manufacturer_id INTEGER,
  engine_code TEXT NOT NULL,
  sales_description TEXT,
  year_from INTEGER,
  month_from INTEGER,
  year_to INTEGER,
  month_to INTEGER,
  power_kw_from INTEGER,
  power_kw_to INTEGER,
  power_ps_from INTEGER,
  power_ps_to INTEGER,
  capacity_from_ccm INTEGER,
  capacity_to_ccm INTEGER,
  compression_min_x100 INTEGER,
  compression_max_x100 INTEGER,
  torque_min INTEGER,
  torque_max INTEGER,
  bore_um INTEGER,
  stroke_um INTEGER,
  valves INTEGER,
  cylinders INTEGER,
  main_bearings INTEGER,
  engine_type INTEGER,
  fuel_type INTEGER,
  fuel_mixture INTEGER,
  charge_type INTEGER,
  engine_construction INTEGER,
  cylinder_construction INTEGER,
  engine_management INTEGER,
  valve_management INTEGER,
  emission_norm INTEGER,
  cooling_type INTEGER,
  has_restriction INTEGER NOT NULL,
  entry_idx INTEGER NOT NULL,
  UNIQUE (entry_idx)
);
CREATE TABLE key_value (
  tab_nr INTEGER NOT NULL,
  key_code TEXT NOT NULL,
  PRIMARY KEY (tab_nr, key_code)
) WITHOUT ROWID;
CREATE TABLE key_value_text (
  tab_nr INTEGER NOT NULL,
  key_code TEXT NOT NULL,
  language_id INTEGER NOT NULL,
  text TEXT NOT NULL,
  is_aligned INTEGER NOT NULL DEFAULT 1,
  PRIMARY KEY (tab_nr, key_code, language_id)
) WITHOUT ROWID;
CREATE TABLE search_tree_node (
  tree_id INTEGER NOT NULL,
  node_id INTEGER NOT NULL,
  parent_node_id INTEGER,
  sort_idx INTEGER NOT NULL,
  is_resolved INTEGER NOT NULL,
  is_last INTEGER NOT NULL,
  PRIMARY KEY (tree_id, node_id),
  UNIQUE (tree_id, sort_idx)
) WITHOUT ROWID;
CREATE INDEX i_stn_parent ON search_tree_node(tree_id, parent_node_id);
CREATE TABLE search_tree_node_text (
  tree_id INTEGER NOT NULL,
  node_id INTEGER NOT NULL,
  language_id INTEGER NOT NULL,
  text TEXT NOT NULL,
  PRIMARY KEY (tree_id, node_id, language_id)
) WITHOUT ROWID;
CREATE TABLE search_tree_node_genart (
  tree_id INTEGER NOT NULL,
  node_id INTEGER NOT NULL,
  pos INTEGER NOT NULL,
  ref_value INTEGER NOT NULL,
  genart_id INTEGER NOT NULL,
  PRIMARY KEY (tree_id, node_id, pos)
) WITHOUT ROWID;
CREATE TABLE pc_type_genart (type_id INTEGER, genart_id INTEGER, PRIMARY KEY (type_id, genart_id)) WITHOUT ROWID;
CREATE TABLE cv_type (
  type_id INTEGER PRIMARY KEY,
  model_id INTEGER NOT NULL,
  description TEXT,
  year_from INTEGER,
  month_from INTEGER,
  year_to INTEGER,
  month_to INTEGER,
  power_ps_from INTEGER,
  power_ps_to INTEGER,
  power_kw_from INTEGER,
  power_kw_to INTEGER,
  capacity_tech_ccm INTEGER,
  tonnage INTEGER,
  platform_type INTEGER,
  axle_config INTEGER,
  has_link INTEGER NOT NULL,
  entry_idx INTEGER NOT NULL,
  UNIQUE (entry_idx)
);
CREATE TABLE cv_type_engine (
  type_id INTEGER NOT NULL,
  seq INTEGER NOT NULL,
  engine_id INTEGER NOT NULL,
  subtype_id INTEGER,
  year_from INTEGER,
  month_from INTEGER,
  year_to INTEGER,
  month_to INTEGER,
  country_id INTEGER,
  is_exclusion INTEGER NOT NULL,
  PRIMARY KEY (type_id, seq)
) WITHOUT ROWID;
CREATE TABLE cv_type_genart (type_id INTEGER, genart_id INTEGER, PRIMARY KEY (type_id, genart_id)) WITHOUT ROWID;
CREATE TABLE generic_article_text (
  genart_id INTEGER NOT NULL,
  language_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  is_aligned INTEGER NOT NULL DEFAULT 1,
  PRIMARY KEY (genart_id, language_id)
) WITHOUT ROWID;
CREATE TABLE article (
  article_id INTEGER PRIMARY KEY,
  supplier_id INTEGER NOT NULL,
  article_number TEXT NOT NULL,
  add_desc_offset INTEGER,
  lotsize1 INTEGER,
  lotsize2 INTEGER,
  is_new INTEGER NOT NULL,
  is_old INTEGER NOT NULL,
  has_exclude INTEGER NOT NULL,
  flag_mat INTEGER NOT NULL,
  flag_at INTEGER NOT NULL,
  flag_accessory INTEGER NOT NULL,
  flag_sb INTEGER NOT NULL,
  has_html INTEGER NOT NULL,
  country_list_id INTEGER,
  country_list_is_exclusion INTEGER,
  n_sensitive INTEGER NOT NULL,
  vehicle_link_offset INTEGER
);
CREATE INDEX i_article_supplier_number ON article(supplier_id, article_number);
CREATE TABLE article_product (
  article_id INTEGER NOT NULL,
  pos INTEGER NOT NULL,
  genart_id INTEGER NOT NULL,
  PRIMARY KEY (article_id, pos)
) WITHOUT ROWID;
CREATE INDEX i_article_product_genart ON article_product(genart_id, article_id);
CREATE TABLE article_attribute (
  article_id INTEGER NOT NULL,
  seq INTEGER NOT NULL,
  genart_id INTEGER,
  criterion_id INTEGER NOT NULL,
  value_num REAL,
  value_text TEXT,
  key_code TEXT,
  is_negative INTEGER,
  show_immediately INTEGER,
  country_id INTEGER,
  PRIMARY KEY (article_id, seq)
) WITHOUT ROWID;
CREATE TABLE article_oe_number (
  article_id INTEGER NOT NULL,
  seq INTEGER NOT NULL,
  manufacturer_id INTEGER NOT NULL,
  oe_number TEXT NOT NULL,
  is_additive INTEGER NOT NULL,
  ref_info_key INTEGER,
  country_list_id INTEGER,
  PRIMARY KEY (article_id, seq)
) WITHOUT ROWID;
CREATE INDEX i_article_oe_number ON article_oe_number(oe_number, manufacturer_id, article_id);
CREATE TABLE article_pc_type (
  article_id INTEGER NOT NULL,
  type_id INTEGER NOT NULL,
  PRIMARY KEY (type_id, article_id)
) WITHOUT ROWID;
CREATE INDEX i_article_pc_type_article ON article_pc_type(article_id, type_id);
CREATE TABLE article_cv_type (
  article_id INTEGER NOT NULL,
  type_id INTEGER NOT NULL,
  PRIMARY KEY (type_id, article_id)
) WITHOUT ROWID;
CREATE INDEX i_article_cv_type_article ON article_cv_type(article_id, type_id);
CREATE TABLE supplier (
  supplier_id INTEGER PRIMARY KEY,
  n_logos INTEGER NOT NULL,
  first_article_id INTEGER,
  n_articles INTEGER,
  name TEXT,
  short_name TEXT
);
CREATE TABLE supplier_dlnr (supplier_id INTEGER PRIMARY KEY, dlnr INTEGER UNIQUE);
CREATE TABLE brand (dlnr INTEGER PRIMARY KEY, name TEXT);
CREATE TABLE criterion (
  criterion_id INTEGER PRIMARY KEY,
  value_type TEXT NOT NULL,
  key_table_nr INTEGER
);
CREATE TABLE criterion_text (
  criterion_id INTEGER NOT NULL,
  language_id INTEGER NOT NULL,
  name TEXT,
  short_name TEXT,
  unit TEXT,
  is_aligned INTEGER NOT NULL DEFAULT 1,
  PRIMARY KEY (criterion_id, language_id)
) WITHOUT ROWID;
CREATE TABLE article_link_qualifier (
  article_id INTEGER NOT NULL,
  vehicle_kind TEXT NOT NULL,
  vehicle_id INTEGER,
  genart_id INTEGER NOT NULL,
  supplier_id INTEGER NOT NULL,
  qual_set_id INTEGER NOT NULL
);
CREATE INDEX i_alq_article ON article_link_qualifier(article_id);
CREATE INDEX i_alq_vehicle ON article_link_qualifier(vehicle_kind, vehicle_id);
CREATE TABLE link_qualifier_set (
  qual_set_id INTEGER PRIMARY KEY,
  primary_countries TEXT
);
CREATE TABLE link_qualifier_item (
  qual_set_id INTEGER NOT NULL,
  seq INTEGER NOT NULL,
  criterion_id INTEGER NOT NULL,
  value_num REAL,
  value_text TEXT,
  key_code TEXT,
  date_year INTEGER,
  date_month INTEGER,
  countries TEXT,
  PRIMARY KEY (qual_set_id, seq)
) WITHOUT ROWID;
