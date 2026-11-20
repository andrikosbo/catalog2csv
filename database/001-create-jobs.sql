CREATE TABLE IF NOT EXISTS catalog2csv_jobs (
    id BINARY(32) NOT NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    payload MEDIUMBLOB NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY catalog2csv_jobs_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
