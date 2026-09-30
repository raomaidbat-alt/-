-- Bitrix24 Lead Analytics: схема для MySQL 8.0.19+
-- Все даты хранятся в UTC (соединение выставляет time_zone = '+00:00').
-- Персональных данных лида (имя, название, контакты) в схеме нет.
-- Применение: php bin/install.php  (или mysql b24_analytics < sql/mysql/001_schema.sql)

CREATE TABLE IF NOT EXISTS sync_runs (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    mode              VARCHAR(16)  NOT NULL,              -- full | incremental
    status            VARCHAR(16)  NOT NULL,              -- running | success | failed | dry_run
    started_at        DATETIME     NOT NULL,
    finished_at       DATETIME     NULL,
    watermark_from    DATETIME     NULL,                  -- нижняя граница DATE_MODIFY для инкремента
    leads_fetched     INT          NOT NULL DEFAULT 0,
    leads_upserted    INT          NOT NULL DEFAULT 0,
    snapshots_written INT          NOT NULL DEFAULT 0,
    leads_deleted     INT          NOT NULL DEFAULT 0,
    api_calls         INT          NOT NULL DEFAULT 0,
    error_message     TEXT         NULL,
    PRIMARY KEY (id),
    KEY idx_sync_runs_status_started (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS statuses_directory (
    status_id   VARCHAR(50)  NOT NULL,
    name        VARCHAR(255) NOT NULL,
    sort        INT          NOT NULL DEFAULT 0,
    color       VARCHAR(16)  NULL,
    semantics   CHAR(1)      NOT NULL DEFAULT 'P',        -- P = в работе, S = успех, F = провал
    updated_at  DATETIME     NOT NULL,
    PRIMARY KEY (status_id),
    KEY idx_statuses_sort (sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sources_directory (
    source_id   VARCHAR(50)  NOT NULL,
    name        VARCHAR(255) NOT NULL,
    sort        INT          NOT NULL DEFAULT 0,
    color       VARCHAR(16)  NULL,
    updated_at  DATETIME     NOT NULL,
    PRIMARY KEY (source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leads_current (
    bitrix_id          BIGINT UNSIGNED NOT NULL,
    status_id          VARCHAR(50)   NOT NULL,
    status_semantics   CHAR(1)       NOT NULL DEFAULT 'P',
    max_stage_sort     INT           NOT NULL DEFAULT 0,  -- самая дальняя стадия (P/S), до которой дошёл лид
    is_qualified       TINYINT       NOT NULL DEFAULT 0,  -- ушёл дальше первой стадии
    stage_entered_at   DATETIME      NULL,
    opportunity        DECIMAL(18,2) NOT NULL DEFAULT 0,
    currency_id        VARCHAR(8)    NULL,
    source_id          VARCHAR(50)   NULL,
    utm_source         VARCHAR(255)  NULL,
    utm_medium         VARCHAR(255)  NULL,
    utm_campaign       VARCHAR(255)  NULL,
    date_create        DATETIME      NOT NULL,
    date_modify        DATETIME      NULL,
    custom_fields_enc  TEXT          NULL,                -- разрешённые UF_CRM_*, libsodium secretbox
    loss_reason        VARCHAR(255)  NULL,                -- причина отказа из поля sync.loss_reason_field
    row_hash           CHAR(64)      NOT NULL,
    is_deleted         TINYINT       NOT NULL DEFAULT 0,
    first_seen_at      DATETIME      NOT NULL,
    last_synced_at     DATETIME      NOT NULL,
    last_seen_run_id   BIGINT UNSIGNED NULL,
    PRIMARY KEY (bitrix_id),
    KEY idx_leads_status       (status_id),
    KEY idx_leads_source       (source_id),
    KEY idx_leads_date_create  (date_create),
    KEY idx_leads_date_modify  (date_modify),
    KEY idx_leads_utm_source   (utm_source),
    KEY idx_leads_utm_medium   (utm_medium),
    KEY idx_leads_utm_campaign (utm_campaign),
    KEY idx_leads_cohort       (is_deleted, date_create, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leads_snapshots (
    snapshot_id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    lead_id               BIGINT UNSIGNED NOT NULL,
    sync_run_id           BIGINT UNSIGNED NOT NULL,
    recorded_at           DATETIME      NOT NULL,
    change_type           VARCHAR(16)   NOT NULL,         -- created | status | update | deleted
    status_id             VARCHAR(50)   NOT NULL,
    prev_status_id        VARCHAR(50)   NULL,
    status_semantics      CHAR(1)       NOT NULL DEFAULT 'P',
    stage_entered_at      DATETIME      NULL,
    seconds_in_prev_stage INT           NULL,             -- сколько лид провёл на prev_status_id
    opportunity           DECIMAL(18,2) NOT NULL DEFAULT 0,
    currency_id           VARCHAR(8)    NULL,
    source_id             VARCHAR(50)   NULL,
    is_deleted            TINYINT       NOT NULL DEFAULT 0,
    row_hash              CHAR(64)      NOT NULL,
    PRIMARY KEY (snapshot_id),
    UNIQUE KEY uq_snapshot_lead_run (lead_id, sync_run_id),
    KEY idx_snap_lead_time   (lead_id, recorded_at),
    KEY idx_snap_recorded    (recorded_at),
    KEY idx_snap_run         (sync_run_id),
    KEY idx_snap_transition  (change_type, prev_status_id, status_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Сделки, созданные из лидов: по выигранным считается выручка канала. Только суммы и даты.
CREATE TABLE IF NOT EXISTS deals (
    deal_id          BIGINT UNSIGNED NOT NULL,
    lead_id          BIGINT UNSIGNED NOT NULL,
    stage_id         VARCHAR(50)   NULL,
    semantics        CHAR(1)       NOT NULL DEFAULT 'P',  -- P в работе, S выиграна, F проиграна
    opportunity      DECIMAL(18,2) NOT NULL DEFAULT 0,
    currency_id      VARCHAR(8)    NULL,
    date_create      DATETIME      NULL,
    won_at           DATETIME      NULL,
    last_seen_run_id BIGINT UNSIGNED NULL,
    synced_at        DATETIME      NOT NULL,
    PRIMARY KEY (deal_id),
    KEY idx_deals_lead (lead_id),
    KEY idx_deals_created (date_create)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Расходы на каналы, которые вносятся в дашборде. Сумма равномерно делится на дни date_from..date_to.
CREATE TABLE IF NOT EXISTS channel_spend (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel_key  VARCHAR(64)   NOT NULL,
    date_from    DATE          NOT NULL,
    date_to      DATE          NOT NULL,
    amount       DECIMAL(14,2) NOT NULL,
    comment      VARCHAR(255)  NULL,
    created_at   DATETIME      NOT NULL,
    PRIMARY KEY (id),
    KEY idx_spend_channel_dates (channel_key, date_from, date_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Журнал снапшотов только дописывается: UPDATE и DELETE запрещены на уровне БД.
DROP TRIGGER IF EXISTS trg_leads_snapshots_no_update;
CREATE TRIGGER trg_leads_snapshots_no_update BEFORE UPDATE ON leads_snapshots
    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'leads_snapshots is append-only';

DROP TRIGGER IF EXISTS trg_leads_snapshots_no_delete;
CREATE TRIGGER trg_leads_snapshots_no_delete BEFORE DELETE ON leads_snapshots
    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'leads_snapshots is append-only';
