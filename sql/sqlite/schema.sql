-- expai (Exponential Intelligence): the tables expai_conversation, expai_draft_log, expai_probe_result and expai_usage_log (sqlite).
-- Generated from expAiSchema::definition() by bin/php/expaischema.php --write-files with the kernel's
-- schema handler; bin/php/expaischema.php --install creates the same tables in a running installation.

CREATE TABLE expai_conversation (
  busy_owner varchar(60) NOT NULL DEFAULT '',
  busy_until INTEGER(11) NOT NULL DEFAULT '0',
  created INTEGER(11) NOT NULL DEFAULT '0',
  draft_count INTEGER(11) NOT NULL DEFAULT '0',
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  iterations INTEGER(11) NOT NULL DEFAULT '0',
  messages longtext,
  model varchar(100) NOT NULL DEFAULT '',
  model_alias varchar(100) NOT NULL DEFAULT '',
  modified INTEGER(11) NOT NULL DEFAULT '0',
  node_id INTEGER(11) NOT NULL DEFAULT '0',
  provider varchar(40) NOT NULL DEFAULT 'anthropic',
  run_id INTEGER(11) NOT NULL DEFAULT '0',
  thread_id INTEGER(11) NOT NULL DEFAULT '0',
  turn_count INTEGER(11) NOT NULL DEFAULT '0',
  user_id INTEGER(11) NOT NULL DEFAULT '0'
);

CREATE  INDEX expai_conversation_thread ON expai_conversation  ( thread_id );
CREATE  INDEX expai_conversation_user_node ON expai_conversation  ( user_id, node_id );
CREATE TABLE expai_usage_log (
  action varchar(40) NOT NULL DEFAULT '',
  batch_id INTEGER(11) NOT NULL DEFAULT '0',
  billed_chars INTEGER(11) NOT NULL DEFAULT '0',
  cache_read_tokens INTEGER(11) NOT NULL DEFAULT '0',
  cache_write_tokens INTEGER(11) NOT NULL DEFAULT '0',
  conversation_id INTEGER(11) NOT NULL DEFAULT '0',
  created INTEGER(11) NOT NULL DEFAULT '0',
  drafts_written INTEGER(11) NOT NULL DEFAULT '0',
  error_class varchar(60) NOT NULL DEFAULT '',
  error_message varchar(255) NOT NULL DEFAULT '',
  fallback_reason varchar(20) NOT NULL DEFAULT '',
  http_status INTEGER(11) NOT NULL DEFAULT '0',
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  input_tokens INTEGER(11) NOT NULL DEFAULT '0',
  iteration INTEGER(11) NOT NULL DEFAULT '0',
  job_uuid varchar(36) NOT NULL DEFAULT '',
  lane varchar(16) NOT NULL DEFAULT '',
  latency_ms INTEGER(11) NOT NULL DEFAULT '0',
  mcp_calls INTEGER(11) NOT NULL DEFAULT '0',
  model varchar(100) NOT NULL DEFAULT '',
  model_alias varchar(100) NOT NULL DEFAULT '',
  node_id INTEGER(11) NOT NULL DEFAULT '0',
  output_tokens INTEGER(11) NOT NULL DEFAULT '0',
  price_factor INTEGER(11) NOT NULL DEFAULT '100',
  provider varchar(40) NOT NULL DEFAULT 'anthropic',
  rate_limits longtext,
  reasoning_tokens INTEGER(11) NOT NULL DEFAULT '0',
  region varchar(10) NOT NULL DEFAULT '',
  reported_cost_micros bigint(20) NOT NULL DEFAULT '0',
  request_debug longtext,
  requested_model_alias varchar(100) NOT NULL DEFAULT '',
  reserved_micros bigint(20) NOT NULL DEFAULT '0',
  reserved_tokens INTEGER(11) NOT NULL DEFAULT '0',
  retention varchar(10) NOT NULL DEFAULT '',
  retries INTEGER(11) NOT NULL DEFAULT '0',
  run_id INTEGER(11) NOT NULL DEFAULT '0',
  service_tier varchar(20) NOT NULL DEFAULT '',
  session_key varchar(40) NOT NULL DEFAULT '',
  stop_reason varchar(30) NOT NULL DEFAULT '',
  thread_id INTEGER(11) NOT NULL DEFAULT '0',
  tool_calls longtext,
  tool_count INTEGER(11) NOT NULL DEFAULT '0',
  tool_mode varchar(10) NOT NULL DEFAULT '',
  tool_ms INTEGER(11) NOT NULL DEFAULT '0',
  turn INTEGER(11) NOT NULL DEFAULT '0',
  user_id INTEGER(11) NOT NULL DEFAULT '0'
);

CREATE  INDEX expai_usage_log_created ON expai_usage_log  ( created );
CREATE  INDEX expai_usage_log_job ON expai_usage_log  ( job_uuid );
CREATE  INDEX expai_usage_log_provider ON expai_usage_log  ( provider, created );
CREATE  INDEX expai_usage_log_thread ON expai_usage_log  ( thread_id );
CREATE  INDEX expai_usage_log_turn ON expai_usage_log  ( conversation_id, turn );
CREATE  INDEX expai_usage_log_user ON expai_usage_log  ( user_id, created );
CREATE TABLE expai_draft_log (
  action varchar(20) NOT NULL DEFAULT '',
  change_id INTEGER(11) NOT NULL DEFAULT '0',
  contentobject_id INTEGER(11) NOT NULL DEFAULT '0',
  conversation_id INTEGER(11) NOT NULL DEFAULT '0',
  created INTEGER(11) NOT NULL DEFAULT '0',
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  job_uuid varchar(36) NOT NULL DEFAULT '',
  language_code varchar(20) NOT NULL DEFAULT '',
  model varchar(100) NOT NULL DEFAULT '',
  modified INTEGER(11) NOT NULL DEFAULT '0',
  node_id INTEGER(11) NOT NULL DEFAULT '0',
  package_uuid varchar(36) NOT NULL DEFAULT '',
  parent_node_id INTEGER(11) NOT NULL DEFAULT '0',
  prompt_summary varchar(255) NOT NULL DEFAULT '',
  provider varchar(40) NOT NULL DEFAULT 'anthropic',
  source varchar(20) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'draft',
  thread_id INTEGER(11) NOT NULL DEFAULT '0',
  user_id INTEGER(11) NOT NULL DEFAULT '0',
  version INTEGER(11) NOT NULL DEFAULT '0'
);

CREATE  INDEX expai_draft_log_node ON expai_draft_log  ( node_id );
CREATE  INDEX expai_draft_log_object ON expai_draft_log  ( contentobject_id, version );
CREATE  INDEX expai_draft_log_package ON expai_draft_log  ( package_uuid );
CREATE  INDEX expai_draft_log_thread ON expai_draft_log  ( thread_id );
CREATE  INDEX expai_draft_log_user ON expai_draft_log  ( user_id, status );
CREATE TABLE expai_probe_result (
  created INTEGER(11) NOT NULL DEFAULT '0',
  details longtext,
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  probe_mode varchar(10) NOT NULL DEFAULT '',
  model varchar(100) NOT NULL DEFAULT '',
  model_alias varchar(100) NOT NULL DEFAULT '',
  pass_rate INTEGER(11) NOT NULL DEFAULT '0',
  passed INTEGER(11) NOT NULL DEFAULT '0',
  provider varchar(40) NOT NULL DEFAULT '',
  tasks INTEGER(11) NOT NULL DEFAULT '0',
  tool_mode varchar(10) NOT NULL DEFAULT '',
  user_id INTEGER(11) NOT NULL DEFAULT '0'
);

CREATE  INDEX expai_probe_result_alias ON expai_probe_result  ( model_alias, created );
CREATE TABLE expai_thread (
  cost_micros bigint(20) NOT NULL DEFAULT '0',
  created INTEGER(11) NOT NULL DEFAULT '0',
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  lane varchar(16) NOT NULL DEFAULT 'auto',
  last_event_id INTEGER(11) NOT NULL DEFAULT '0',
  lead_run_id INTEGER(11) NOT NULL DEFAULT '0',
  model_alias varchar(100) NOT NULL DEFAULT '',
  modified INTEGER(11) NOT NULL DEFAULT '0',
  node_id INTEGER(11) NOT NULL DEFAULT '0',
  origin varchar(16) NOT NULL DEFAULT 'vector',
  plan_id INTEGER(11) NOT NULL DEFAULT '0',
  position INTEGER(11) NOT NULL DEFAULT '0',
  provider varchar(40) NOT NULL DEFAULT '',
  requests INTEGER(11) NOT NULL DEFAULT '0',
  reserved_micros bigint(20) NOT NULL DEFAULT '0',
  review_policy varchar(12) NOT NULL DEFAULT 'staged',
  run_mode varchar(10) NOT NULL DEFAULT 'write',
  scope_json longtext,
  seen_event_id INTEGER(11) NOT NULL DEFAULT '0',
  shelf varchar(10) NOT NULL DEFAULT 'active',
  state varchar(16) NOT NULL DEFAULT 'idle',
  title varchar(255) NOT NULL DEFAULT '',
  tokens INTEGER(11) NOT NULL DEFAULT '0',
  user_id INTEGER(11) NOT NULL DEFAULT '0',
  uuid varchar(36) NOT NULL DEFAULT ''
);

CREATE  INDEX expai_thread_node ON expai_thread  ( node_id );
CREATE  INDEX expai_thread_user_shelf ON expai_thread  ( user_id, shelf, modified );
CREATE  INDEX expai_thread_user_state ON expai_thread  ( user_id, state );
CREATE  INDEX expai_thread_uuid ON expai_thread  ( uuid );
CREATE TABLE expai_thread_run (
  conversation_id INTEGER(11) NOT NULL DEFAULT '0',
  cost_micros bigint(20) NOT NULL DEFAULT '0',
  created INTEGER(11) NOT NULL DEFAULT '0',
  finished INTEGER(11) NOT NULL DEFAULT '0',
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  job_uuid varchar(36) NOT NULL DEFAULT '',
  kind varchar(12) NOT NULL DEFAULT 'turn',
  label varchar(255) NOT NULL DEFAULT '',
  lane varchar(16) NOT NULL DEFAULT '',
  model_alias varchar(100) NOT NULL DEFAULT '',
  modified INTEGER(11) NOT NULL DEFAULT '0',
  parent_run_id INTEGER(11) NOT NULL DEFAULT '0',
  plan_item INTEGER(11) NOT NULL DEFAULT '-1',
  progress_done INTEGER(11) NOT NULL DEFAULT '0',
  progress_total INTEGER(11) NOT NULL DEFAULT '0',
  started INTEGER(11) NOT NULL DEFAULT '0',
  state varchar(16) NOT NULL DEFAULT 'idle',
  thread_id INTEGER(11) NOT NULL DEFAULT '0',
  tokens INTEGER(11) NOT NULL DEFAULT '0'
);

CREATE  INDEX expai_thread_run_conv ON expai_thread_run  ( conversation_id );
CREATE  INDEX expai_thread_run_thread ON expai_thread_run  ( thread_id, id );
CREATE TABLE expai_thread_event (
  created INTEGER(11) NOT NULL DEFAULT '0',
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  kind varchar(20) NOT NULL DEFAULT '',
  payload longtext,
  run_id INTEGER(11) NOT NULL DEFAULT '0',
  thread_id INTEGER(11) NOT NULL DEFAULT '0'
);

CREATE  INDEX expai_thread_event_created ON expai_thread_event  ( created );
CREATE  INDEX expai_thread_event_thread ON expai_thread_event  ( thread_id, id );
CREATE TABLE expai_followup (
  body longtext,
  created INTEGER(11) NOT NULL DEFAULT '0',
  delivery varchar(8) NOT NULL DEFAULT 'queue',
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  modified INTEGER(11) NOT NULL DEFAULT '0',
  position INTEGER(11) NOT NULL DEFAULT '0',
  state varchar(10) NOT NULL DEFAULT 'waiting',
  thread_id INTEGER(11) NOT NULL DEFAULT '0'
);

CREATE  INDEX expai_followup_thread ON expai_followup  ( thread_id, state );
CREATE TABLE expai_checkpoint (
  changes_json longtext,
  conversation_len INTEGER(11) NOT NULL DEFAULT '0',
  created INTEGER(11) NOT NULL DEFAULT '0',
  drafts_json longtext,
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  label varchar(255) NOT NULL DEFAULT '',
  thread_id INTEGER(11) NOT NULL DEFAULT '0',
  turn INTEGER(11) NOT NULL DEFAULT '0'
);

CREATE  INDEX expai_checkpoint_created ON expai_checkpoint  ( created );
CREATE  INDEX expai_checkpoint_thread ON expai_checkpoint  ( thread_id, turn );
CREATE TABLE expai_plan (
  approved INTEGER(11) NOT NULL DEFAULT '0',
  approved_by INTEGER(11) NOT NULL DEFAULT '0',
  created INTEGER(11) NOT NULL DEFAULT '0',
  estimate_json longtext,
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  items_json longtext,
  modified INTEGER(11) NOT NULL DEFAULT '0',
  state varchar(12) NOT NULL DEFAULT 'proposed',
  thread_id INTEGER(11) NOT NULL DEFAULT '0'
);

CREATE  INDEX expai_plan_thread ON expai_plan  ( thread_id );
CREATE TABLE expai_change (
  applied_version INTEGER(11) NOT NULL DEFAULT '0',
  attribute_identifier varchar(100) NOT NULL DEFAULT '',
  base_hash varchar(64) NOT NULL DEFAULT '',
  base_version INTEGER(11) NOT NULL DEFAULT '0',
  class_identifier varchar(100) NOT NULL DEFAULT '',
  contentobject_id INTEGER(11) NOT NULL DEFAULT '0',
  created INTEGER(11) NOT NULL DEFAULT '0',
  datatype varchar(40) NOT NULL DEFAULT '',
  decided INTEGER(11) NOT NULL DEFAULT '0',
  decided_by INTEGER(11) NOT NULL DEFAULT '0',
  decision varchar(10) NOT NULL DEFAULT 'pending',
  hunks_json longtext,
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  language_code varchar(20) NOT NULL DEFAULT '',
  modified INTEGER(11) NOT NULL DEFAULT '0',
  new_key varchar(40) NOT NULL DEFAULT '',
  parent_node_id INTEGER(11) NOT NULL DEFAULT '0',
  proposed longtext,
  run_id INTEGER(11) NOT NULL DEFAULT '0',
  thread_id INTEGER(11) NOT NULL DEFAULT '0'
);

CREATE  INDEX expai_change_created ON expai_change  ( created );
CREATE  INDEX expai_change_object ON expai_change  ( contentobject_id, language_code );
CREATE  INDEX expai_change_thread ON expai_change  ( thread_id, decision );
CREATE TABLE expai_batch (
  batch_api varchar(20) NOT NULL DEFAULT '',
  canceled INTEGER(11) NOT NULL DEFAULT '0',
  created INTEGER(11) NOT NULL DEFAULT '0',
  deleted INTEGER(11) NOT NULL DEFAULT '0',
  ended INTEGER(11) NOT NULL DEFAULT '0',
  errored INTEGER(11) NOT NULL DEFAULT '0',
  expired INTEGER(11) NOT NULL DEFAULT '0',
  expires_at INTEGER(11) NOT NULL DEFAULT '0',
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  input_ref varchar(120) NOT NULL DEFAULT '',
  lane varchar(16) NOT NULL DEFAULT '',
  last_error varchar(255) NOT NULL DEFAULT '',
  model varchar(100) NOT NULL DEFAULT '',
  model_alias varchar(100) NOT NULL DEFAULT '',
  modified INTEGER(11) NOT NULL DEFAULT '0',
  output_ref varchar(120) NOT NULL DEFAULT '',
  polls INTEGER(11) NOT NULL DEFAULT '0',
  provider varchar(40) NOT NULL DEFAULT '',
  remote_id varchar(120) NOT NULL DEFAULT '',
  remote_status varchar(40) NOT NULL DEFAULT '',
  request_count INTEGER(11) NOT NULL DEFAULT '0',
  results_fetched INTEGER(11) NOT NULL DEFAULT '0',
  retention varchar(10) NOT NULL DEFAULT '',
  state varchar(20) NOT NULL DEFAULT 'submitted',
  submit_job_uuid varchar(36) NOT NULL DEFAULT '',
  submitted INTEGER(11) NOT NULL DEFAULT '0',
  succeeded INTEGER(11) NOT NULL DEFAULT '0',
  user_id INTEGER(11) NOT NULL DEFAULT '0'
);

CREATE  INDEX expai_batch_state ON expai_batch  ( state, provider );
CREATE TABLE expai_chain_run (
  brief longtext,
  chain varchar(40) NOT NULL DEFAULT '',
  chain_hash varchar(40) NOT NULL DEFAULT '',
  class_identifier varchar(50) NOT NULL DEFAULT '',
  cost_micros bigint(20) NOT NULL DEFAULT '0',
  created INTEGER(11) NOT NULL DEFAULT '0',
  current_step varchar(40) NOT NULL DEFAULT '',
  data longtext,
  finished INTEGER(11) NOT NULL DEFAULT '0',
  group_uuid varchar(36) NOT NULL DEFAULT '',
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  input_tokens INTEGER(11) NOT NULL DEFAULT '0',
  item INTEGER(11) NOT NULL DEFAULT '0',
  item_count INTEGER(11) NOT NULL DEFAULT '0',
  label varchar(150) NOT NULL DEFAULT '',
  language varchar(20) NOT NULL DEFAULT '',
  last_error varchar(255) NOT NULL DEFAULT '',
  modified INTEGER(11) NOT NULL DEFAULT '0',
  node_id INTEGER(11) NOT NULL DEFAULT '0',
  object_id INTEGER(11) NOT NULL DEFAULT '0',
  output_tokens INTEGER(11) NOT NULL DEFAULT '0',
  snapshot longtext,
  state varchar(20) NOT NULL DEFAULT 'running',
  user_id INTEGER(11) NOT NULL DEFAULT '0',
  uuid varchar(36) NOT NULL DEFAULT '',
  version INTEGER(11) NOT NULL DEFAULT '0'
);

CREATE  INDEX expai_chain_run_state ON expai_chain_run  ( state, user_id );
CREATE  INDEX expai_chain_run_uuid ON expai_chain_run  ( uuid );
CREATE TABLE expai_chain_step (
  cost_micros bigint(20) NOT NULL DEFAULT '0',
  created INTEGER(11) NOT NULL DEFAULT '0',
  finished INTEGER(11) NOT NULL DEFAULT '0',
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  input_hash varchar(40) NOT NULL DEFAULT '',
  input_tokens INTEGER(11) NOT NULL DEFAULT '0',
  issue_count INTEGER(11) NOT NULL DEFAULT '0',
  iteration INTEGER(11) NOT NULL DEFAULT '0',
  job_uuid varchar(36) NOT NULL DEFAULT '',
  lane varchar(16) NOT NULL DEFAULT '',
  language varchar(20) NOT NULL DEFAULT '',
  model varchar(100) NOT NULL DEFAULT '',
  note varchar(255) NOT NULL DEFAULT '',
  output longtext,
  output_tokens INTEGER(11) NOT NULL DEFAULT '0',
  provider varchar(40) NOT NULL DEFAULT '',
  run_id INTEGER(11) NOT NULL DEFAULT '0',
  score INTEGER(11) NOT NULL DEFAULT '-1',
  service_tier varchar(20) NOT NULL DEFAULT '',
  state varchar(20) NOT NULL DEFAULT 'queued',
  step varchar(40) NOT NULL DEFAULT '',
  step_type varchar(20) NOT NULL DEFAULT '',
  usage_log_id INTEGER(11) NOT NULL DEFAULT '0',
  verdict varchar(10) NOT NULL DEFAULT ''
);

CREATE  INDEX expai_chain_step_job ON expai_chain_step  ( job_uuid );
CREATE  INDEX expai_chain_step_run ON expai_chain_step  ( run_id );
