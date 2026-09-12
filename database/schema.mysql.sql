CREATE TABLE IF NOT EXISTS links (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code            VARCHAR(32)     NOT NULL,
    target_url      VARCHAR(2048)   NOT NULL,
    created_at      DATETIME        NOT NULL,
    expires_at      DATETIME            NULL,
    created_ip_hash CHAR(64)            NULL,
    click_count     BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY idx_links_code (code),
    KEY idx_links_creator (created_ip_hash, created_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_bin;

CREATE TABLE IF NOT EXISTS clicks (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    link_id    BIGINT UNSIGNED NOT NULL,
    clicked_at DATETIME        NOT NULL,
    ip_hash    CHAR(64)            NULL,
    referer    VARCHAR(512)        NULL,
    user_agent VARCHAR(512)        NULL,
    PRIMARY KEY (id),
    KEY idx_clicks_link (link_id, clicked_at),
    CONSTRAINT fk_clicks_link FOREIGN KEY (link_id) REFERENCES links (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4
