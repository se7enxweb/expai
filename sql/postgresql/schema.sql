-- expai (Exponential Intelligence): the tables expai_conversation, expai_draft_log, expai_probe_result and expai_usage_log (postgresql).
-- Generated from expAiSchema::definition() by bin/php/expaischema.php --write-files with the kernel's
-- schema handler; bin/php/expaischema.php --install creates the same tables in a running installation.

CREATE SEQUENCE IF NOT EXISTS expai_conversation_id_seq
  START 1
  INCREMENT 1
  MAXVALUE 9223372036854775807
  MINVALUE 1
  CACHE 1;
CREATE TABLE IF NOT EXISTS expai_conversation (
  busy_owner character varying(60) DEFAULT ''::character varying NOT NULL,
  busy_until integer DEFAULT 0 NOT NULL,
  created integer DEFAULT 0 NOT NULL,
  draft_count integer DEFAULT 0 NOT NULL,
  id integer DEFAULT nextval('expai_conversation_id_seq'::text) NOT NULL,
  iterations integer DEFAULT 0 NOT NULL,
  messages text,
  model character varying(100) DEFAULT ''::character varying NOT NULL,
  model_alias character varying(100) DEFAULT ''::character varying NOT NULL,
  modified integer DEFAULT 0 NOT NULL,
  node_id integer DEFAULT 0 NOT NULL,
  provider character varying(40) DEFAULT 'anthropic'::character varying NOT NULL,
  run_id integer DEFAULT 0 NOT NULL,
  thread_id integer DEFAULT 0 NOT NULL,
  turn_count integer DEFAULT 0 NOT NULL,
  user_id integer DEFAULT 0 NOT NULL
);

CREATE INDEX expai_conversation_user_node ON expai_conversation USING btree ( user_id, node_id );
CREATE INDEX expai_conversation_thread ON expai_conversation USING btree ( thread_id );
ALTER TABLE ONLY expai_conversation ADD CONSTRAINT expai_conversation_pkey PRIMARY KEY ( id );
CREATE SEQUENCE IF NOT EXISTS expai_usage_log_id_seq
  START 1
  INCREMENT 1
  MAXVALUE 9223372036854775807
  MINVALUE 1
  CACHE 1;
CREATE TABLE IF NOT EXISTS expai_usage_log (
  "action" character varying(40) DEFAULT ''::character varying NOT NULL,
  batch_id integer DEFAULT 0 NOT NULL,
  billed_chars integer DEFAULT 0 NOT NULL,
  cache_read_tokens integer DEFAULT 0 NOT NULL,
  cache_write_tokens integer DEFAULT 0 NOT NULL,
  conversation_id integer DEFAULT 0 NOT NULL,
  created integer DEFAULT 0 NOT NULL,
  drafts_written integer DEFAULT 0 NOT NULL,
  error_class character varying(60) DEFAULT ''::character varying NOT NULL,
  error_message character varying(255) DEFAULT ''::character varying NOT NULL,
  fallback_reason character varying(20) DEFAULT ''::character varying NOT NULL,
  http_status integer DEFAULT 0 NOT NULL,
  id integer DEFAULT nextval('expai_usage_log_id_seq'::text) NOT NULL,
  input_tokens integer DEFAULT 0 NOT NULL,
  iteration integer DEFAULT 0 NOT NULL,
  job_uuid character varying(36) DEFAULT ''::character varying NOT NULL,
  lane character varying(16) DEFAULT ''::character varying NOT NULL,
  latency_ms integer DEFAULT 0 NOT NULL,
  mcp_calls integer DEFAULT 0 NOT NULL,
  model character varying(100) DEFAULT ''::character varying NOT NULL,
  model_alias character varying(100) DEFAULT ''::character varying NOT NULL,
  node_id integer DEFAULT 0 NOT NULL,
  output_tokens integer DEFAULT 0 NOT NULL,
  price_factor integer DEFAULT 100 NOT NULL,
  provider character varying(40) DEFAULT 'anthropic'::character varying NOT NULL,
  rate_limits text,
  reasoning_tokens integer DEFAULT 0 NOT NULL,
  region character varying(10) DEFAULT ''::character varying NOT NULL,
  reported_cost_micros bigint DEFAULT '0' NOT NULL,
  request_debug text,
  requested_model_alias character varying(100) DEFAULT ''::character varying NOT NULL,
  reserved_micros bigint DEFAULT '0' NOT NULL,
  reserved_tokens integer DEFAULT 0 NOT NULL,
  retention character varying(10) DEFAULT ''::character varying NOT NULL,
  retries integer DEFAULT 0 NOT NULL,
  run_id integer DEFAULT 0 NOT NULL,
  service_tier character varying(20) DEFAULT ''::character varying NOT NULL,
  session_key character varying(40) DEFAULT ''::character varying NOT NULL,
  stop_reason character varying(30) DEFAULT ''::character varying NOT NULL,
  thread_id integer DEFAULT 0 NOT NULL,
  tool_calls text,
  tool_count integer DEFAULT 0 NOT NULL,
  tool_mode character varying(10) DEFAULT ''::character varying NOT NULL,
  tool_ms integer DEFAULT 0 NOT NULL,
  turn integer DEFAULT 0 NOT NULL,
  user_id integer DEFAULT 0 NOT NULL
);

CREATE INDEX expai_usage_log_created ON expai_usage_log USING btree ( created );
CREATE INDEX expai_usage_log_provider ON expai_usage_log USING btree ( provider, created );
CREATE INDEX expai_usage_log_user ON expai_usage_log USING btree ( user_id, created );
CREATE INDEX expai_usage_log_turn ON expai_usage_log USING btree ( conversation_id, turn );
CREATE INDEX expai_usage_log_thread ON expai_usage_log USING btree ( thread_id );
CREATE INDEX expai_usage_log_job ON expai_usage_log USING btree ( job_uuid );
ALTER TABLE ONLY expai_usage_log ADD CONSTRAINT expai_usage_log_pkey PRIMARY KEY ( id );
CREATE SEQUENCE IF NOT EXISTS expai_draft_log_id_seq
  START 1
  INCREMENT 1
  MAXVALUE 9223372036854775807
  MINVALUE 1
  CACHE 1;
CREATE TABLE IF NOT EXISTS expai_draft_log (
  "action" character varying(20) DEFAULT ''::character varying NOT NULL,
  change_id integer DEFAULT 0 NOT NULL,
  contentobject_id integer DEFAULT 0 NOT NULL,
  conversation_id integer DEFAULT 0 NOT NULL,
  created integer DEFAULT 0 NOT NULL,
  id integer DEFAULT nextval('expai_draft_log_id_seq'::text) NOT NULL,
  job_uuid character varying(36) DEFAULT ''::character varying NOT NULL,
  language_code character varying(20) DEFAULT ''::character varying NOT NULL,
  model character varying(100) DEFAULT ''::character varying NOT NULL,
  modified integer DEFAULT 0 NOT NULL,
  node_id integer DEFAULT 0 NOT NULL,
  package_uuid character varying(36) DEFAULT ''::character varying NOT NULL,
  parent_node_id integer DEFAULT 0 NOT NULL,
  prompt_summary character varying(255) DEFAULT ''::character varying NOT NULL,
  provider character varying(40) DEFAULT 'anthropic'::character varying NOT NULL,
  source character varying(20) DEFAULT ''::character varying NOT NULL,
  status character varying(20) DEFAULT 'draft'::character varying NOT NULL,
  thread_id integer DEFAULT 0 NOT NULL,
  user_id integer DEFAULT 0 NOT NULL,
  "version" integer DEFAULT 0 NOT NULL
);

CREATE INDEX expai_draft_log_object ON expai_draft_log USING btree ( contentobject_id, "version" );
CREATE INDEX expai_draft_log_user ON expai_draft_log USING btree ( user_id, status );
CREATE INDEX expai_draft_log_node ON expai_draft_log USING btree ( node_id );
CREATE INDEX expai_draft_log_thread ON expai_draft_log USING btree ( thread_id );
CREATE INDEX expai_draft_log_package ON expai_draft_log USING btree ( package_uuid );
ALTER TABLE ONLY expai_draft_log ADD CONSTRAINT expai_draft_log_pkey PRIMARY KEY ( id );
CREATE SEQUENCE IF NOT EXISTS expai_probe_result_id_seq
  START 1
  INCREMENT 1
  MAXVALUE 9223372036854775807
  MINVALUE 1
  CACHE 1;
CREATE TABLE IF NOT EXISTS expai_probe_result (
  created integer DEFAULT 0 NOT NULL,
  details text,
  id integer DEFAULT nextval('expai_probe_result_id_seq'::text) NOT NULL,
  probe_mode character varying(10) DEFAULT ''::character varying NOT NULL,
  model character varying(100) DEFAULT ''::character varying NOT NULL,
  model_alias character varying(100) DEFAULT ''::character varying NOT NULL,
  pass_rate integer DEFAULT 0 NOT NULL,
  passed integer DEFAULT 0 NOT NULL,
  provider character varying(40) DEFAULT ''::character varying NOT NULL,
  tasks integer DEFAULT 0 NOT NULL,
  tool_mode character varying(10) DEFAULT ''::character varying NOT NULL,
  user_id integer DEFAULT 0 NOT NULL
);

CREATE INDEX expai_probe_result_alias ON expai_probe_result USING btree ( model_alias, created );
ALTER TABLE ONLY expai_probe_result ADD CONSTRAINT expai_probe_result_pkey PRIMARY KEY ( id );
CREATE SEQUENCE IF NOT EXISTS expai_thread_id_seq
  START 1
  INCREMENT 1
  MAXVALUE 9223372036854775807
  MINVALUE 1
  CACHE 1;
CREATE TABLE IF NOT EXISTS expai_thread (
  cost_micros bigint DEFAULT '0' NOT NULL,
  created integer DEFAULT 0 NOT NULL,
  id integer DEFAULT nextval('expai_thread_id_seq'::text) NOT NULL,
  lane character varying(16) DEFAULT 'auto'::character varying NOT NULL,
  last_event_id integer DEFAULT 0 NOT NULL,
  lead_run_id integer DEFAULT 0 NOT NULL,
  model_alias character varying(100) DEFAULT ''::character varying NOT NULL,
  modified integer DEFAULT 0 NOT NULL,
  node_id integer DEFAULT 0 NOT NULL,
  origin character varying(16) DEFAULT 'vector'::character varying NOT NULL,
  plan_id integer DEFAULT 0 NOT NULL,
  "position" integer DEFAULT 0 NOT NULL,
  provider character varying(40) DEFAULT ''::character varying NOT NULL,
  requests integer DEFAULT 0 NOT NULL,
  reserved_micros bigint DEFAULT '0' NOT NULL,
  review_policy character varying(12) DEFAULT 'staged'::character varying NOT NULL,
  run_mode character varying(10) DEFAULT 'write'::character varying NOT NULL,
  scope_json text,
  seen_event_id integer DEFAULT 0 NOT NULL,
  shelf character varying(10) DEFAULT 'active'::character varying NOT NULL,
  state character varying(16) DEFAULT 'idle'::character varying NOT NULL,
  title character varying(255) DEFAULT ''::character varying NOT NULL,
  tokens integer DEFAULT 0 NOT NULL,
  user_id integer DEFAULT 0 NOT NULL,
  uuid character varying(36) DEFAULT ''::character varying NOT NULL
);

CREATE INDEX expai_thread_user_shelf ON expai_thread USING btree ( user_id, shelf, modified );
CREATE INDEX expai_thread_user_state ON expai_thread USING btree ( user_id, state );
CREATE INDEX expai_thread_uuid ON expai_thread USING btree ( uuid );
CREATE INDEX expai_thread_node ON expai_thread USING btree ( node_id );
ALTER TABLE ONLY expai_thread ADD CONSTRAINT expai_thread_pkey PRIMARY KEY ( id );
CREATE SEQUENCE IF NOT EXISTS expai_thread_run_id_seq
  START 1
  INCREMENT 1
  MAXVALUE 9223372036854775807
  MINVALUE 1
  CACHE 1;
CREATE TABLE IF NOT EXISTS expai_thread_run (
  conversation_id integer DEFAULT 0 NOT NULL,
  cost_micros bigint DEFAULT '0' NOT NULL,
  created integer DEFAULT 0 NOT NULL,
  finished integer DEFAULT 0 NOT NULL,
  id integer DEFAULT nextval('expai_thread_run_id_seq'::text) NOT NULL,
  job_uuid character varying(36) DEFAULT ''::character varying NOT NULL,
  kind character varying(12) DEFAULT 'turn'::character varying NOT NULL,
  label character varying(255) DEFAULT ''::character varying NOT NULL,
  lane character varying(16) DEFAULT ''::character varying NOT NULL,
  model_alias character varying(100) DEFAULT ''::character varying NOT NULL,
  modified integer DEFAULT 0 NOT NULL,
  parent_run_id integer DEFAULT 0 NOT NULL,
  plan_item integer DEFAULT -1 NOT NULL,
  progress_done integer DEFAULT 0 NOT NULL,
  progress_total integer DEFAULT 0 NOT NULL,
  started integer DEFAULT 0 NOT NULL,
  state character varying(16) DEFAULT 'idle'::character varying NOT NULL,
  thread_id integer DEFAULT 0 NOT NULL,
  tokens integer DEFAULT 0 NOT NULL
);

CREATE INDEX expai_thread_run_thread ON expai_thread_run USING btree ( thread_id, id );
CREATE INDEX expai_thread_run_conv ON expai_thread_run USING btree ( conversation_id );
ALTER TABLE ONLY expai_thread_run ADD CONSTRAINT expai_thread_run_pkey PRIMARY KEY ( id );
CREATE SEQUENCE IF NOT EXISTS expai_thread_event_id_seq
  START 1
  INCREMENT 1
  MAXVALUE 9223372036854775807
  MINVALUE 1
  CACHE 1;
CREATE TABLE IF NOT EXISTS expai_thread_event (
  created integer DEFAULT 0 NOT NULL,
  id integer DEFAULT nextval('expai_thread_event_id_seq'::text) NOT NULL,
  kind character varying(20) DEFAULT ''::character varying NOT NULL,
  payload text,
  run_id integer DEFAULT 0 NOT NULL,
  thread_id integer DEFAULT 0 NOT NULL
);

CREATE INDEX expai_thread_event_thread ON expai_thread_event USING btree ( thread_id, id );
CREATE INDEX expai_thread_event_created ON expai_thread_event USING btree ( created );
ALTER TABLE ONLY expai_thread_event ADD CONSTRAINT expai_thread_event_pkey PRIMARY KEY ( id );
CREATE SEQUENCE IF NOT EXISTS expai_followup_id_seq
  START 1
  INCREMENT 1
  MAXVALUE 9223372036854775807
  MINVALUE 1
  CACHE 1;
CREATE TABLE IF NOT EXISTS expai_followup (
  body text,
  created integer DEFAULT 0 NOT NULL,
  delivery character varying(8) DEFAULT 'queue'::character varying NOT NULL,
  id integer DEFAULT nextval('expai_followup_id_seq'::text) NOT NULL,
  modified integer DEFAULT 0 NOT NULL,
  "position" integer DEFAULT 0 NOT NULL,
  state character varying(10) DEFAULT 'waiting'::character varying NOT NULL,
  thread_id integer DEFAULT 0 NOT NULL
);

CREATE INDEX expai_followup_thread ON expai_followup USING btree ( thread_id, state );
ALTER TABLE ONLY expai_followup ADD CONSTRAINT expai_followup_pkey PRIMARY KEY ( id );
CREATE SEQUENCE IF NOT EXISTS expai_checkpoint_id_seq
  START 1
  INCREMENT 1
  MAXVALUE 9223372036854775807
  MINVALUE 1
  CACHE 1;
CREATE TABLE IF NOT EXISTS expai_checkpoint (
  changes_json text,
  conversation_len integer DEFAULT 0 NOT NULL,
  created integer DEFAULT 0 NOT NULL,
  drafts_json text,
  id integer DEFAULT nextval('expai_checkpoint_id_seq'::text) NOT NULL,
  label character varying(255) DEFAULT ''::character varying NOT NULL,
  thread_id integer DEFAULT 0 NOT NULL,
  turn integer DEFAULT 0 NOT NULL
);

CREATE INDEX expai_checkpoint_thread ON expai_checkpoint USING btree ( thread_id, turn );
CREATE INDEX expai_checkpoint_created ON expai_checkpoint USING btree ( created );
ALTER TABLE ONLY expai_checkpoint ADD CONSTRAINT expai_checkpoint_pkey PRIMARY KEY ( id );
CREATE SEQUENCE IF NOT EXISTS expai_plan_id_seq
  START 1
  INCREMENT 1
  MAXVALUE 9223372036854775807
  MINVALUE 1
  CACHE 1;
CREATE TABLE IF NOT EXISTS expai_plan (
  approved integer DEFAULT 0 NOT NULL,
  approved_by integer DEFAULT 0 NOT NULL,
  created integer DEFAULT 0 NOT NULL,
  estimate_json text,
  id integer DEFAULT nextval('expai_plan_id_seq'::text) NOT NULL,
  items_json text,
  modified integer DEFAULT 0 NOT NULL,
  state character varying(12) DEFAULT 'proposed'::character varying NOT NULL,
  thread_id integer DEFAULT 0 NOT NULL
);

CREATE INDEX expai_plan_thread ON expai_plan USING btree ( thread_id );
ALTER TABLE ONLY expai_plan ADD CONSTRAINT expai_plan_pkey PRIMARY KEY ( id );
CREATE SEQUENCE IF NOT EXISTS expai_change_id_seq
  START 1
  INCREMENT 1
  MAXVALUE 9223372036854775807
  MINVALUE 1
  CACHE 1;
CREATE TABLE IF NOT EXISTS expai_change (
  applied_version integer DEFAULT 0 NOT NULL,
  attribute_identifier character varying(100) DEFAULT ''::character varying NOT NULL,
  base_hash character varying(64) DEFAULT ''::character varying NOT NULL,
  base_version integer DEFAULT 0 NOT NULL,
  class_identifier character varying(100) DEFAULT ''::character varying NOT NULL,
  contentobject_id integer DEFAULT 0 NOT NULL,
  created integer DEFAULT 0 NOT NULL,
  datatype character varying(40) DEFAULT ''::character varying NOT NULL,
  decided integer DEFAULT 0 NOT NULL,
  decided_by integer DEFAULT 0 NOT NULL,
  decision character varying(10) DEFAULT 'pending'::character varying NOT NULL,
  hunks_json text,
  id integer DEFAULT nextval('expai_change_id_seq'::text) NOT NULL,
  language_code character varying(20) DEFAULT ''::character varying NOT NULL,
  modified integer DEFAULT 0 NOT NULL,
  new_key character varying(40) DEFAULT ''::character varying NOT NULL,
  parent_node_id integer DEFAULT 0 NOT NULL,
  proposed text,
  run_id integer DEFAULT 0 NOT NULL,
  thread_id integer DEFAULT 0 NOT NULL
);

CREATE INDEX expai_change_thread ON expai_change USING btree ( thread_id, decision );
CREATE INDEX expai_change_object ON expai_change USING btree ( contentobject_id, language_code );
CREATE INDEX expai_change_created ON expai_change USING btree ( created );
ALTER TABLE ONLY expai_change ADD CONSTRAINT expai_change_pkey PRIMARY KEY ( id );
CREATE SEQUENCE IF NOT EXISTS expai_batch_id_seq
  START 1
  INCREMENT 1
  MAXVALUE 9223372036854775807
  MINVALUE 1
  CACHE 1;
CREATE TABLE IF NOT EXISTS expai_batch (
  batch_api character varying(20) DEFAULT ''::character varying NOT NULL,
  canceled integer DEFAULT 0 NOT NULL,
  created integer DEFAULT 0 NOT NULL,
  deleted integer DEFAULT 0 NOT NULL,
  ended integer DEFAULT 0 NOT NULL,
  errored integer DEFAULT 0 NOT NULL,
  expired integer DEFAULT 0 NOT NULL,
  expires_at integer DEFAULT 0 NOT NULL,
  id integer DEFAULT nextval('expai_batch_id_seq'::text) NOT NULL,
  input_ref character varying(120) DEFAULT ''::character varying NOT NULL,
  lane character varying(16) DEFAULT ''::character varying NOT NULL,
  last_error character varying(255) DEFAULT ''::character varying NOT NULL,
  model character varying(100) DEFAULT ''::character varying NOT NULL,
  model_alias character varying(100) DEFAULT ''::character varying NOT NULL,
  modified integer DEFAULT 0 NOT NULL,
  output_ref character varying(120) DEFAULT ''::character varying NOT NULL,
  polls integer DEFAULT 0 NOT NULL,
  provider character varying(40) DEFAULT ''::character varying NOT NULL,
  remote_id character varying(120) DEFAULT ''::character varying NOT NULL,
  remote_status character varying(40) DEFAULT ''::character varying NOT NULL,
  request_count integer DEFAULT 0 NOT NULL,
  results_fetched integer DEFAULT 0 NOT NULL,
  retention character varying(10) DEFAULT ''::character varying NOT NULL,
  state character varying(20) DEFAULT 'submitted'::character varying NOT NULL,
  submit_job_uuid character varying(36) DEFAULT ''::character varying NOT NULL,
  submitted integer DEFAULT 0 NOT NULL,
  succeeded integer DEFAULT 0 NOT NULL,
  user_id integer DEFAULT 0 NOT NULL
);

CREATE INDEX expai_batch_state ON expai_batch USING btree ( state, provider );
ALTER TABLE ONLY expai_batch ADD CONSTRAINT expai_batch_pkey PRIMARY KEY ( id );
CREATE SEQUENCE IF NOT EXISTS expai_chain_run_id_seq
  START 1
  INCREMENT 1
  MAXVALUE 9223372036854775807
  MINVALUE 1
  CACHE 1;
CREATE TABLE IF NOT EXISTS expai_chain_run (
  brief text,
  "chain" character varying(40) DEFAULT ''::character varying NOT NULL,
  chain_hash character varying(40) DEFAULT ''::character varying NOT NULL,
  class_identifier character varying(50) DEFAULT ''::character varying NOT NULL,
  cost_micros bigint DEFAULT '0' NOT NULL,
  created integer DEFAULT 0 NOT NULL,
  current_step character varying(40) DEFAULT ''::character varying NOT NULL,
  data text,
  finished integer DEFAULT 0 NOT NULL,
  group_uuid character varying(36) DEFAULT ''::character varying NOT NULL,
  id integer DEFAULT nextval('expai_chain_run_id_seq'::text) NOT NULL,
  input_tokens integer DEFAULT 0 NOT NULL,
  item integer DEFAULT 0 NOT NULL,
  item_count integer DEFAULT 0 NOT NULL,
  label character varying(150) DEFAULT ''::character varying NOT NULL,
  "language" character varying(20) DEFAULT ''::character varying NOT NULL,
  last_error character varying(255) DEFAULT ''::character varying NOT NULL,
  modified integer DEFAULT 0 NOT NULL,
  node_id integer DEFAULT 0 NOT NULL,
  object_id integer DEFAULT 0 NOT NULL,
  output_tokens integer DEFAULT 0 NOT NULL,
  snapshot text,
  state character varying(20) DEFAULT 'running'::character varying NOT NULL,
  user_id integer DEFAULT 0 NOT NULL,
  uuid character varying(36) DEFAULT ''::character varying NOT NULL,
  "version" integer DEFAULT 0 NOT NULL
);

CREATE INDEX expai_chain_run_uuid ON expai_chain_run USING btree ( uuid );
CREATE INDEX expai_chain_run_state ON expai_chain_run USING btree ( state, user_id );
ALTER TABLE ONLY expai_chain_run ADD CONSTRAINT expai_chain_run_pkey PRIMARY KEY ( id );
CREATE SEQUENCE IF NOT EXISTS expai_chain_step_id_seq
  START 1
  INCREMENT 1
  MAXVALUE 9223372036854775807
  MINVALUE 1
  CACHE 1;
CREATE TABLE IF NOT EXISTS expai_chain_step (
  cost_micros bigint DEFAULT '0' NOT NULL,
  created integer DEFAULT 0 NOT NULL,
  finished integer DEFAULT 0 NOT NULL,
  id integer DEFAULT nextval('expai_chain_step_id_seq'::text) NOT NULL,
  input_hash character varying(40) DEFAULT ''::character varying NOT NULL,
  input_tokens integer DEFAULT 0 NOT NULL,
  issue_count integer DEFAULT 0 NOT NULL,
  iteration integer DEFAULT 0 NOT NULL,
  job_uuid character varying(36) DEFAULT ''::character varying NOT NULL,
  lane character varying(16) DEFAULT ''::character varying NOT NULL,
  "language" character varying(20) DEFAULT ''::character varying NOT NULL,
  model character varying(100) DEFAULT ''::character varying NOT NULL,
  note character varying(255) DEFAULT ''::character varying NOT NULL,
  output text,
  output_tokens integer DEFAULT 0 NOT NULL,
  provider character varying(40) DEFAULT ''::character varying NOT NULL,
  run_id integer DEFAULT 0 NOT NULL,
  score integer DEFAULT -1 NOT NULL,
  service_tier character varying(20) DEFAULT ''::character varying NOT NULL,
  state character varying(20) DEFAULT 'queued'::character varying NOT NULL,
  step character varying(40) DEFAULT ''::character varying NOT NULL,
  step_type character varying(20) DEFAULT ''::character varying NOT NULL,
  usage_log_id integer DEFAULT 0 NOT NULL,
  verdict character varying(10) DEFAULT ''::character varying NOT NULL
);

CREATE INDEX expai_chain_step_run ON expai_chain_step USING btree ( run_id );
CREATE INDEX expai_chain_step_job ON expai_chain_step USING btree ( job_uuid );
ALTER TABLE ONLY expai_chain_step ADD CONSTRAINT expai_chain_step_pkey PRIMARY KEY ( id );
