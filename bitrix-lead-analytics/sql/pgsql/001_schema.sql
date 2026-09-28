-- Bitrix24 Lead Analytics: схема для PostgreSQL 14+
-- Все даты хранятся в UTC как TIMESTAMP без зоны (соединение выставляет TIME ZONE 'UTC').
-- Персональных данных лида (имя, название, контакты) в схеме нет.
-- Применение: php bin/install.php  (или psql -d b24_analytics -f sql/pgsql/001_schema.sql)

CREATE TABLE IF NOT EXISTS sync_runs (
    id                BIGSERIAL    PRIMARY KEY,
    mode              VARCHAR(16)  NOT NULL,              -- full | incremental
    status            VARCHAR(16)  NOT NULL,              -- running | success | failed | dry_run
    started_at        TIMESTAMP(0) NOT NULL,
    finished_at       TIMESTAMP(0) NULL,
    watermark_from    TIMESTAMP(0) NULL,
    leads_fetched     INTEGER      NOT NULL DEFAULT 0,
    leads_upserted    INTEGER      NOT NULL DEFAULT 0,
    snapshots_written INTEGER      NOT NULL DEFAULT 0,
    leads_deleted     INTEGER      NOT NULL DEFAULT 0,
    api_calls         INTEGER      NOT NULL DEFAULT 0,
    error_message     TEXT         NULL
);
CREATE INDEX IF NOT EXISTS idx_sync_runs_status_started ON sync_runs (status, started_at);

CREATE TABLE IF NOT EXISTS statuses_directory (
    status_id   VARCHAR(50)  PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    sort        INTEGER      NOT NULL DEFAULT 0,
    color       VARCHAR(16)  NULL,
    semantics   CHAR(1)      NOT NULL DEFAULT 'P',        -- P = в работе, S = успех, F = провал
    updated_at  TIMESTAMP(0) NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_statuses_sort ON statuses_directory (sort);

CREATE TABLE IF NOT EXISTS sources_directory (
    source_id   VARCHAR(50)  PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    sort        INTEGER      NOT NULL DEFAULT 0,
    color       VARCHAR(16)  NULL,
    updated_at  TIMESTAMP(0) NOT NULL
);

CREATE TABLE IF NOT EXISTS leads_current (
    bitrix_id          BIGINT        PRIMARY KEY,
    status_id          VARCHAR(50)   NOT NULL,
    status_semantics   CHAR(1)       NOT NULL DEFAULT 'P',
    max_stage_sort     INTEGER       NOT NULL DEFAULT 0,
    is_qualified       SMALLINT      NOT NULL DEFAULT 0,
    stage_entered_at   TIMESTAMP(0)  NULL,
    opportunity        NUMERIC(18,2) NOT NULL DEFAULT 0,
    currency_id        VARCHAR(8)    NULL,
    source_id          VARCHAR(50)   NULL,
    assigned_by_id     BIGINT        NULL,
    utm_source         VARCHAR(255)  NULL,
    utm_medium         VARCHAR(255)  NULL,
    utm_campaign       VARCHAR(255)  NULL,
    utm_content        VARCHAR(255)  NULL,
    utm_term           VARCHAR(255)  NULL,
    date_create        TIMESTAMP(0)  NOT NULL,
    date_modify        TIMESTAMP(0)  NULL,
    custom_fields_enc  TEXT          NULL,                -- разрешённые UF_CRM_*, libsodium secretbox
    row_hash           CHAR(64)      NOT NULL,
    is_deleted         SMALLINT      NOT NULL DEFAULT 0,
    first_seen_at      TIMESTAMP(0)  NOT NULL,
    last_synced_at     TIMESTAMP(0)  NOT NULL,
    last_seen_run_id   BIGINT        NULL
);
CREATE INDEX IF NOT EXISTS idx_leads_status       ON leads_current (status_id);
CREATE INDEX IF NOT EXISTS idx_leads_source       ON leads_current (source_id);
CREATE INDEX IF NOT EXISTS idx_leads_date_create  ON leads_current (date_create);
CREATE INDEX IF NOT EXISTS idx_leads_date_modify  ON leads_current (date_modify);
CREATE INDEX IF NOT EXISTS idx_leads_utm_source   ON leads_current (utm_source);
CREATE INDEX IF NOT EXISTS idx_leads_utm_medium   ON leads_current (utm_medium);
CREATE INDEX IF NOT EXISTS idx_leads_utm_campaign ON leads_current (utm_campaign);
CREATE INDEX IF NOT EXISTS idx_leads_cohort       ON leads_current (is_deleted, date_create, source_id);

CREATE TABLE IF NOT EXISTS leads_snapshots (
    snapshot_id           BIGSERIAL     PRIMARY KEY,
    lead_id               BIGINT        NOT NULL,
    sync_run_id           BIGINT        NOT NULL,
    recorded_at           TIMESTAMP(0)  NOT NULL,
    change_type           VARCHAR(16)   NOT NULL,         -- created | status | update | deleted
    status_id             VARCHAR(50)   NOT NULL,
    prev_status_id        VARCHAR(50)   NULL,
    status_semantics      CHAR(1)       NOT NULL DEFAULT 'P',
    stage_entered_at      TIMESTAMP(0)  NULL,
    seconds_in_prev_stage INTEGER       NULL,
    opportunity           NUMERIC(18,2) NOT NULL DEFAULT 0,
    currency_id           VARCHAR(8)    NULL,
    assigned_by_id        BIGINT        NULL,
    source_id             VARCHAR(50)   NULL,
    is_deleted            SMALLINT      NOT NULL DEFAULT 0,
    row_hash              CHAR(64)      NOT NULL,
    CONSTRAINT uq_snapshot_lead_run UNIQUE (lead_id, sync_run_id)
);
CREATE INDEX IF NOT EXISTS idx_snap_lead_time  ON leads_snapshots (lead_id, recorded_at);
CREATE INDEX IF NOT EXISTS idx_snap_recorded   ON leads_snapshots (recorded_at);
CREATE INDEX IF NOT EXISTS idx_snap_run        ON leads_snapshots (sync_run_id);
CREATE INDEX IF NOT EXISTS idx_snap_transition ON leads_snapshots (change_type, prev_status_id, status_id);

-- Журнал снапшотов только дописывается: UPDATE и DELETE запрещены на уровне БД.
CREATE OR REPLACE FUNCTION leads_snapshots_append_only() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'leads_snapshots is append-only';
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_leads_snapshots_append_only ON leads_snapshots;
CREATE TRIGGER trg_leads_snapshots_append_only
    BEFORE UPDATE OR DELETE ON leads_snapshots
    FOR EACH ROW EXECUTE FUNCTION leads_snapshots_append_only();
