CREATE TABLE IF NOT EXISTS links (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    code            TEXT    NOT NULL,
    target_url      TEXT    NOT NULL,
    created_at      TEXT    NOT NULL,
    expires_at      TEXT        NULL,
    created_ip_hash TEXT        NULL,
    click_count     INTEGER NOT NULL DEFAULT 0
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_links_code ON links (code);

CREATE INDEX IF NOT EXISTS idx_links_creator ON links (created_ip_hash, created_at);

CREATE TABLE IF NOT EXISTS clicks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    link_id    INTEGER NOT NULL REFERENCES links (id) ON DELETE CASCADE,
    clicked_at TEXT    NOT NULL,
    ip_hash    TEXT        NULL,
    referer    TEXT        NULL,
    user_agent TEXT        NULL
);

CREATE INDEX IF NOT EXISTS idx_clicks_link ON clicks (link_id, clicked_at)
