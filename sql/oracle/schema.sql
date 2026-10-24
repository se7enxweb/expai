-- expai (Exponential Intelligence): the tables expai_conversation, expai_draft_log, expai_probe_result and expai_usage_log (oracle).
-- Generated from expAiSchema::definition() by bin/php/expaischema.php --write-files with the kernel's
-- schema handler; bin/php/expaischema.php --install creates the same tables in a running installation.

BEGIN
  EXECUTE IMMEDIATE 'CREATE SEQUENCE se_expai_conversation';
EXCEPTION WHEN OTHERS THEN
  IF SQLCODE != -955 THEN RAISE; END IF;
END;
/
CREATE TABLE expai_conversation (
  busy_owner VARCHAR2(60),
  busy_until INTEGER DEFAULT 0 NOT NULL,
  created INTEGER DEFAULT 0 NOT NULL,
  draft_count INTEGER DEFAULT 0 NOT NULL,
  id INTEGER NOT NULL,
  iterations INTEGER DEFAULT 0 NOT NULL,
  messages CLOB,
  model VARCHAR2(100),
  model_alias VARCHAR2(100),
  modified INTEGER DEFAULT 0 NOT NULL,
  node_id INTEGER DEFAULT 0 NOT NULL,
  provider VARCHAR2(40) DEFAULT 'anthropic',
  run_id INTEGER DEFAULT 0 NOT NULL,
  thread_id INTEGER DEFAULT 0 NOT NULL,
  turn_count INTEGER DEFAULT 0 NOT NULL,
  user_id INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( id )
);

CREATE OR REPLACE TRIGGER expai_conversation_id_tr
BEFORE INSERT ON expai_conversation FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_expai_conversation.nextval INTO :new.id FROM dual;
END;
/

CREATE INDEX expai_conversation_thread ON expai_conversation ( thread_id );
CREATE INDEX expai_conversation_user_node ON expai_conversation ( user_id, node_id );
BEGIN
  EXECUTE IMMEDIATE 'CREATE SEQUENCE se_expai_usage_log';
EXCEPTION WHEN OTHERS THEN
  IF SQLCODE != -955 THEN RAISE; END IF;
END;
/
CREATE TABLE expai_usage_log (
  action VARCHAR2(40),
  batch_id INTEGER DEFAULT 0 NOT NULL,
  billed_chars INTEGER DEFAULT 0 NOT NULL,
  cache_read_tokens INTEGER DEFAULT 0 NOT NULL,
  cache_write_tokens INTEGER DEFAULT 0 NOT NULL,
  conversation_id INTEGER DEFAULT 0 NOT NULL,
  created INTEGER DEFAULT 0 NOT NULL,
  drafts_written INTEGER DEFAULT 0 NOT NULL,
  error_class VARCHAR2(60),
  error_message VARCHAR2(255),
  fallback_reason VARCHAR2(20),
  http_status INTEGER DEFAULT 0 NOT NULL,
  id INTEGER NOT NULL,
  input_tokens INTEGER DEFAULT 0 NOT NULL,
  iteration INTEGER DEFAULT 0 NOT NULL,
  job_uuid VARCHAR2(36),
  lane VARCHAR2(16),
  latency_ms INTEGER DEFAULT 0 NOT NULL,
  mcp_calls INTEGER DEFAULT 0 NOT NULL,
  model VARCHAR2(100),
  model_alias VARCHAR2(100),
  node_id INTEGER DEFAULT 0 NOT NULL,
  output_tokens INTEGER DEFAULT 0 NOT NULL,
  price_factor INTEGER DEFAULT 100 NOT NULL,
  provider VARCHAR2(40) DEFAULT 'anthropic',
  rate_limits CLOB,
  reasoning_tokens INTEGER DEFAULT 0 NOT NULL,
  region VARCHAR2(10),
  reported_cost_micros INTEGER DEFAULT 0 NOT NULL,
  request_debug CLOB,
  requested_model_alias VARCHAR2(100),
  reserved_micros INTEGER DEFAULT 0 NOT NULL,
  reserved_tokens INTEGER DEFAULT 0 NOT NULL,
  retention VARCHAR2(10),
  retries INTEGER DEFAULT 0 NOT NULL,
  run_id INTEGER DEFAULT 0 NOT NULL,
  service_tier VARCHAR2(20),
  session_key VARCHAR2(40),
  stop_reason VARCHAR2(30),
  thread_id INTEGER DEFAULT 0 NOT NULL,
  tool_calls CLOB,
  tool_count INTEGER DEFAULT 0 NOT NULL,
  tool_mode VARCHAR2(10),
  tool_ms INTEGER DEFAULT 0 NOT NULL,
  turn INTEGER DEFAULT 0 NOT NULL,
  user_id INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( id )
);

CREATE OR REPLACE TRIGGER expai_usage_log_id_tr
BEFORE INSERT ON expai_usage_log FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_expai_usage_log.nextval INTO :new.id FROM dual;
END;
/

CREATE INDEX expai_usage_log_created ON expai_usage_log ( created );
CREATE INDEX expai_usage_log_job ON expai_usage_log ( job_uuid );
CREATE INDEX expai_usage_log_provider ON expai_usage_log ( provider, created );
CREATE INDEX expai_usage_log_thread ON expai_usage_log ( thread_id );
CREATE INDEX expai_usage_log_turn ON expai_usage_log ( conversation_id, turn );
CREATE INDEX expai_usage_log_user ON expai_usage_log ( user_id, created );
BEGIN
  EXECUTE IMMEDIATE 'CREATE SEQUENCE se_expai_draft_log';
EXCEPTION WHEN OTHERS THEN
  IF SQLCODE != -955 THEN RAISE; END IF;
END;
/
CREATE TABLE expai_draft_log (
  action VARCHAR2(20),
  change_id INTEGER DEFAULT 0 NOT NULL,
  contentobject_id INTEGER DEFAULT 0 NOT NULL,
  conversation_id INTEGER DEFAULT 0 NOT NULL,
  created INTEGER DEFAULT 0 NOT NULL,
  id INTEGER NOT NULL,
  job_uuid VARCHAR2(36),
  language_code VARCHAR2(20),
  model VARCHAR2(100),
  modified INTEGER DEFAULT 0 NOT NULL,
  node_id INTEGER DEFAULT 0 NOT NULL,
  package_uuid VARCHAR2(36),
  parent_node_id INTEGER DEFAULT 0 NOT NULL,
  prompt_summary VARCHAR2(255),
  provider VARCHAR2(40) DEFAULT 'anthropic',
  source VARCHAR2(20),
  status VARCHAR2(20) DEFAULT 'draft',
  thread_id INTEGER DEFAULT 0 NOT NULL,
  user_id INTEGER DEFAULT 0 NOT NULL,
  version INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( id )
);

CREATE OR REPLACE TRIGGER expai_draft_log_id_tr
BEFORE INSERT ON expai_draft_log FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_expai_draft_log.nextval INTO :new.id FROM dual;
END;
/

CREATE INDEX expai_draft_log_node ON expai_draft_log ( node_id );
CREATE INDEX expai_draft_log_object ON expai_draft_log ( contentobject_id, version );
CREATE INDEX expai_draft_log_package ON expai_draft_log ( package_uuid );
CREATE INDEX expai_draft_log_thread ON expai_draft_log ( thread_id );
CREATE INDEX expai_draft_log_user ON expai_draft_log ( user_id, status );
BEGIN
  EXECUTE IMMEDIATE 'CREATE SEQUENCE se_expai_probe_result';
EXCEPTION WHEN OTHERS THEN
  IF SQLCODE != -955 THEN RAISE; END IF;
END;
/
CREATE TABLE expai_probe_result (
  created INTEGER DEFAULT 0 NOT NULL,
  details CLOB,
  id INTEGER NOT NULL,
  probe_mode VARCHAR2(10),
  model VARCHAR2(100),
  model_alias VARCHAR2(100),
  pass_rate INTEGER DEFAULT 0 NOT NULL,
  passed INTEGER DEFAULT 0 NOT NULL,
  provider VARCHAR2(40),
  tasks INTEGER DEFAULT 0 NOT NULL,
  tool_mode VARCHAR2(10),
  user_id INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( id )
);

CREATE OR REPLACE TRIGGER expai_probe_result_id_tr
BEFORE INSERT ON expai_probe_result FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_expai_probe_result.nextval INTO :new.id FROM dual;
END;
/

CREATE INDEX expai_probe_result_alias ON expai_probe_result ( model_alias, created );
BEGIN
  EXECUTE IMMEDIATE 'CREATE SEQUENCE se_expai_thread';
EXCEPTION WHEN OTHERS THEN
  IF SQLCODE != -955 THEN RAISE; END IF;
END;
/
CREATE TABLE expai_thread (
  cost_micros INTEGER DEFAULT 0 NOT NULL,
  created INTEGER DEFAULT 0 NOT NULL,
  id INTEGER NOT NULL,
  lane VARCHAR2(16) DEFAULT 'auto',
  last_event_id INTEGER DEFAULT 0 NOT NULL,
  lead_run_id INTEGER DEFAULT 0 NOT NULL,
  model_alias VARCHAR2(100),
  modified INTEGER DEFAULT 0 NOT NULL,
  node_id INTEGER DEFAULT 0 NOT NULL,
  origin VARCHAR2(16) DEFAULT 'vector',
  plan_id INTEGER DEFAULT 0 NOT NULL,
  position INTEGER DEFAULT 0 NOT NULL,
  provider VARCHAR2(40),
  requests INTEGER DEFAULT 0 NOT NULL,
  reserved_micros INTEGER DEFAULT 0 NOT NULL,
  review_policy VARCHAR2(12) DEFAULT 'staged',
  run_mode VARCHAR2(10) DEFAULT 'write',
  scope_json CLOB,
  seen_event_id INTEGER DEFAULT 0 NOT NULL,
  shelf VARCHAR2(10) DEFAULT 'active',
  state VARCHAR2(16) DEFAULT 'idle',
  title VARCHAR2(255),
  tokens INTEGER DEFAULT 0 NOT NULL,
  user_id INTEGER DEFAULT 0 NOT NULL,
  uuid VARCHAR2(36),
  PRIMARY KEY ( id )
);

CREATE OR REPLACE TRIGGER expai_thread_id_tr
BEFORE INSERT ON expai_thread FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_expai_thread.nextval INTO :new.id FROM dual;
END;
/

CREATE INDEX expai_thread_node ON expai_thread ( node_id );
CREATE INDEX expai_thread_user_shelf ON expai_thread ( user_id, shelf, modified );
CREATE INDEX expai_thread_user_state ON expai_thread ( user_id, state );
CREATE INDEX expai_thread_uuid ON expai_thread ( uuid );
BEGIN
  EXECUTE IMMEDIATE 'CREATE SEQUENCE se_expai_thread_run';
EXCEPTION WHEN OTHERS THEN
  IF SQLCODE != -955 THEN RAISE; END IF;
END;
/
CREATE TABLE expai_thread_run (
  conversation_id INTEGER DEFAULT 0 NOT NULL,
  cost_micros INTEGER DEFAULT 0 NOT NULL,
  created INTEGER DEFAULT 0 NOT NULL,
  finished INTEGER DEFAULT 0 NOT NULL,
  id INTEGER NOT NULL,
  job_uuid VARCHAR2(36),
  kind VARCHAR2(12) DEFAULT 'turn',
  label VARCHAR2(255),
  lane VARCHAR2(16),
  model_alias VARCHAR2(100),
  modified INTEGER DEFAULT 0 NOT NULL,
  parent_run_id INTEGER DEFAULT 0 NOT NULL,
  plan_item INTEGER DEFAULT -1 NOT NULL,
  progress_done INTEGER DEFAULT 0 NOT NULL,
  progress_total INTEGER DEFAULT 0 NOT NULL,
  started INTEGER DEFAULT 0 NOT NULL,
  state VARCHAR2(16) DEFAULT 'idle',
  thread_id INTEGER DEFAULT 0 NOT NULL,
  tokens INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( id )
);

CREATE OR REPLACE TRIGGER expai_thread_run_id_tr
BEFORE INSERT ON expai_thread_run FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_expai_thread_run.nextval INTO :new.id FROM dual;
END;
/

CREATE INDEX expai_thread_run_conv ON expai_thread_run ( conversation_id );
CREATE INDEX expai_thread_run_thread ON expai_thread_run ( thread_id, id );
BEGIN
  EXECUTE IMMEDIATE 'CREATE SEQUENCE se_expai_thread_event';
EXCEPTION WHEN OTHERS THEN
  IF SQLCODE != -955 THEN RAISE; END IF;
END;
/
CREATE TABLE expai_thread_event (
  created INTEGER DEFAULT 0 NOT NULL,
  id INTEGER NOT NULL,
  kind VARCHAR2(20),
  payload CLOB,
  run_id INTEGER DEFAULT 0 NOT NULL,
  thread_id INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( id )
);

CREATE OR REPLACE TRIGGER expai_thread_event_id_tr
BEFORE INSERT ON expai_thread_event FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_expai_thread_event.nextval INTO :new.id FROM dual;
END;
/

CREATE INDEX expai_thread_event_created ON expai_thread_event ( created );
CREATE INDEX expai_thread_event_thread ON expai_thread_event ( thread_id, id );
BEGIN
  EXECUTE IMMEDIATE 'CREATE SEQUENCE se_expai_followup';
EXCEPTION WHEN OTHERS THEN
  IF SQLCODE != -955 THEN RAISE; END IF;
END;
/
CREATE TABLE expai_followup (
  body CLOB,
  created INTEGER DEFAULT 0 NOT NULL,
  delivery VARCHAR2(8) DEFAULT 'queue',
  id INTEGER NOT NULL,
  modified INTEGER DEFAULT 0 NOT NULL,
  position INTEGER DEFAULT 0 NOT NULL,
  state VARCHAR2(10) DEFAULT 'waiting',
  thread_id INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( id )
);

CREATE OR REPLACE TRIGGER expai_followup_id_tr
BEFORE INSERT ON expai_followup FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_expai_followup.nextval INTO :new.id FROM dual;
END;
/

CREATE INDEX expai_followup_thread ON expai_followup ( thread_id, state );
BEGIN
  EXECUTE IMMEDIATE 'CREATE SEQUENCE se_expai_checkpoint';
EXCEPTION WHEN OTHERS THEN
  IF SQLCODE != -955 THEN RAISE; END IF;
END;
/
CREATE TABLE expai_checkpoint (
  changes_json CLOB,
  conversation_len INTEGER DEFAULT 0 NOT NULL,
  created INTEGER DEFAULT 0 NOT NULL,
  drafts_json CLOB,
  id INTEGER NOT NULL,
  label VARCHAR2(255),
  thread_id INTEGER DEFAULT 0 NOT NULL,
  turn INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( id )
);

CREATE OR REPLACE TRIGGER expai_checkpoint_id_tr
BEFORE INSERT ON expai_checkpoint FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_expai_checkpoint.nextval INTO :new.id FROM dual;
END;
/

CREATE INDEX expai_checkpoint_created ON expai_checkpoint ( created );
CREATE INDEX expai_checkpoint_thread ON expai_checkpoint ( thread_id, turn );
BEGIN
  EXECUTE IMMEDIATE 'CREATE SEQUENCE se_expai_plan';
EXCEPTION WHEN OTHERS THEN
  IF SQLCODE != -955 THEN RAISE; END IF;
END;
/
CREATE TABLE expai_plan (
  approved INTEGER DEFAULT 0 NOT NULL,
  approved_by INTEGER DEFAULT 0 NOT NULL,
  created INTEGER DEFAULT 0 NOT NULL,
  estimate_json CLOB,
  id INTEGER NOT NULL,
  items_json CLOB,
  modified INTEGER DEFAULT 0 NOT NULL,
  state VARCHAR2(12) DEFAULT 'proposed',
  thread_id INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( id )
);

CREATE OR REPLACE TRIGGER expai_plan_id_tr
BEFORE INSERT ON expai_plan FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_expai_plan.nextval INTO :new.id FROM dual;
END;
/

CREATE INDEX expai_plan_thread ON expai_plan ( thread_id );
BEGIN
  EXECUTE IMMEDIATE 'CREATE SEQUENCE se_expai_change';
EXCEPTION WHEN OTHERS THEN
  IF SQLCODE != -955 THEN RAISE; END IF;
END;
/
CREATE TABLE expai_change (
  applied_version INTEGER DEFAULT 0 NOT NULL,
  attribute_identifier VARCHAR2(100),
  base_hash VARCHAR2(64),
  base_version INTEGER DEFAULT 0 NOT NULL,
  class_identifier VARCHAR2(100),
  contentobject_id INTEGER DEFAULT 0 NOT NULL,
  created INTEGER DEFAULT 0 NOT NULL,
  datatype VARCHAR2(40),
  decided INTEGER DEFAULT 0 NOT NULL,
  decided_by INTEGER DEFAULT 0 NOT NULL,
  decision VARCHAR2(10) DEFAULT 'pending',
  hunks_json CLOB,
  id INTEGER NOT NULL,
  language_code VARCHAR2(20),
  modified INTEGER DEFAULT 0 NOT NULL,
  new_key VARCHAR2(40),
  parent_node_id INTEGER DEFAULT 0 NOT NULL,
  proposed CLOB,
  run_id INTEGER DEFAULT 0 NOT NULL,
  thread_id INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( id )
);

CREATE OR REPLACE TRIGGER expai_change_id_tr
BEFORE INSERT ON expai_change FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_expai_change.nextval INTO :new.id FROM dual;
END;
/

CREATE INDEX expai_change_created ON expai_change ( created );
CREATE INDEX expai_change_object ON expai_change ( contentobject_id, language_code );
CREATE INDEX expai_change_thread ON expai_change ( thread_id, decision );
BEGIN
  EXECUTE IMMEDIATE 'CREATE SEQUENCE se_expai_batch';
EXCEPTION WHEN OTHERS THEN
  IF SQLCODE != -955 THEN RAISE; END IF;
END;
/
CREATE TABLE expai_batch (
  batch_api VARCHAR2(20),
  canceled INTEGER DEFAULT 0 NOT NULL,
  created INTEGER DEFAULT 0 NOT NULL,
  deleted INTEGER DEFAULT 0 NOT NULL,
  ended INTEGER DEFAULT 0 NOT NULL,
  errored INTEGER DEFAULT 0 NOT NULL,
  expired INTEGER DEFAULT 0 NOT NULL,
  expires_at INTEGER DEFAULT 0 NOT NULL,
  id INTEGER NOT NULL,
  input_ref VARCHAR2(120),
  lane VARCHAR2(16),
  last_error VARCHAR2(255),
  model VARCHAR2(100),
  model_alias VARCHAR2(100),
  modified INTEGER DEFAULT 0 NOT NULL,
  output_ref VARCHAR2(120),
  polls INTEGER DEFAULT 0 NOT NULL,
  provider VARCHAR2(40),
  remote_id VARCHAR2(120),
  remote_status VARCHAR2(40),
  request_count INTEGER DEFAULT 0 NOT NULL,
  results_fetched INTEGER DEFAULT 0 NOT NULL,
  retention VARCHAR2(10),
  state VARCHAR2(20) DEFAULT 'submitted',
  submit_job_uuid VARCHAR2(36),
  submitted INTEGER DEFAULT 0 NOT NULL,
  succeeded INTEGER DEFAULT 0 NOT NULL,
  user_id INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( id )
);

CREATE OR REPLACE TRIGGER expai_batch_id_tr
BEFORE INSERT ON expai_batch FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_expai_batch.nextval INTO :new.id FROM dual;
END;
/

CREATE INDEX expai_batch_state ON expai_batch ( state, provider );
BEGIN
  EXECUTE IMMEDIATE 'CREATE SEQUENCE se_expai_chain_run';
EXCEPTION WHEN OTHERS THEN
  IF SQLCODE != -955 THEN RAISE; END IF;
END;
/
CREATE TABLE expai_chain_run (
  brief CLOB,
  chain VARCHAR2(40),
  chain_hash VARCHAR2(40),
  class_identifier VARCHAR2(50),
  cost_micros INTEGER DEFAULT 0 NOT NULL,
  created INTEGER DEFAULT 0 NOT NULL,
  current_step VARCHAR2(40),
  data CLOB,
  finished INTEGER DEFAULT 0 NOT NULL,
  group_uuid VARCHAR2(36),
  id INTEGER NOT NULL,
  input_tokens INTEGER DEFAULT 0 NOT NULL,
  item INTEGER DEFAULT 0 NOT NULL,
  item_count INTEGER DEFAULT 0 NOT NULL,
  label VARCHAR2(150),
  language VARCHAR2(20),
  last_error VARCHAR2(255),
  modified INTEGER DEFAULT 0 NOT NULL,
  node_id INTEGER DEFAULT 0 NOT NULL,
  object_id INTEGER DEFAULT 0 NOT NULL,
  output_tokens INTEGER DEFAULT 0 NOT NULL,
  snapshot CLOB,
  state VARCHAR2(20) DEFAULT 'running',
  user_id INTEGER DEFAULT 0 NOT NULL,
  uuid VARCHAR2(36),
  version INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( id )
);

CREATE OR REPLACE TRIGGER expai_chain_run_id_tr
BEFORE INSERT ON expai_chain_run FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_expai_chain_run.nextval INTO :new.id FROM dual;
END;
/

CREATE INDEX expai_chain_run_state ON expai_chain_run ( state, user_id );
CREATE INDEX expai_chain_run_uuid ON expai_chain_run ( uuid );
BEGIN
  EXECUTE IMMEDIATE 'CREATE SEQUENCE se_expai_chain_step';
EXCEPTION WHEN OTHERS THEN
  IF SQLCODE != -955 THEN RAISE; END IF;
END;
/
CREATE TABLE expai_chain_step (
  cost_micros INTEGER DEFAULT 0 NOT NULL,
  created INTEGER DEFAULT 0 NOT NULL,
  finished INTEGER DEFAULT 0 NOT NULL,
  id INTEGER NOT NULL,
  input_hash VARCHAR2(40),
  input_tokens INTEGER DEFAULT 0 NOT NULL,
  issue_count INTEGER DEFAULT 0 NOT NULL,
  iteration INTEGER DEFAULT 0 NOT NULL,
  job_uuid VARCHAR2(36),
  lane VARCHAR2(16),
  language VARCHAR2(20),
  model VARCHAR2(100),
  note VARCHAR2(255),
  output CLOB,
  output_tokens INTEGER DEFAULT 0 NOT NULL,
  provider VARCHAR2(40),
  run_id INTEGER DEFAULT 0 NOT NULL,
  score INTEGER DEFAULT -1 NOT NULL,
  service_tier VARCHAR2(20),
  state VARCHAR2(20) DEFAULT 'queued',
  step VARCHAR2(40),
  step_type VARCHAR2(20),
  usage_log_id INTEGER DEFAULT 0 NOT NULL,
  verdict VARCHAR2(10),
  PRIMARY KEY ( id )
);

CREATE OR REPLACE TRIGGER expai_chain_step_id_tr
BEFORE INSERT ON expai_chain_step FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_expai_chain_step.nextval INTO :new.id FROM dual;
END;
/

CREATE INDEX expai_chain_step_job ON expai_chain_step ( job_uuid );
CREATE INDEX expai_chain_step_run ON expai_chain_step ( run_id );
