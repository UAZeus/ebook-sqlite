-- E-Book Library — SQLite Schema
-- Run: php database/migrate.php

-- ─── Genres ────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS genres (
    genre_id INTEGER PRIMARY KEY AUTOINCREMENT,
    name     TEXT NOT NULL UNIQUE
);

-- ─── Users ─────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS users (
    user_id       TEXT    NOT NULL PRIMARY KEY,
    name          TEXT    NOT NULL,
    email         TEXT    NOT NULL UNIQUE,
    password      TEXT    NOT NULL,
    role          TEXT    NOT NULL DEFAULT 'viewer',
    auth_provider TEXT    NOT NULL DEFAULT 'email',
    created_at    TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);

-- ─── User Preferences ─────────────────────────────────────────

CREATE TABLE IF NOT EXISTS user_preferences (
    user_id  TEXT NOT NULL,
    genre_id INT  NOT NULL,
    PRIMARY KEY (user_id, genre_id),
    FOREIGN KEY (user_id)  REFERENCES users(user_id)  ON DELETE CASCADE,
    FOREIGN KEY (genre_id) REFERENCES genres(genre_id) ON DELETE CASCADE
);

-- ─── Books ─────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS books (
    book_id     INTEGER PRIMARY KEY AUTOINCREMENT,
    title       TEXT    NOT NULL,
    author      TEXT    NOT NULL,
    description TEXT,
    cover_url   TEXT,
    genre_id    INT,
    isbn        TEXT,
    year        INT,
    pages       INT,
    file_path   TEXT,
    file_size   INTEGER DEFAULT 0,
    user_id     TEXT    NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'pending',
    created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (genre_id) REFERENCES genres(genre_id) ON DELETE SET NULL,
    FOREIGN KEY (user_id)  REFERENCES users(user_id)   ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_books_author ON books(author);
CREATE INDEX IF NOT EXISTS idx_books_genre  ON books(genre_id);
CREATE INDEX IF NOT EXISTS idx_books_status ON books(status);

-- ─── Activity Logs ─────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS activity_logs (
    log_id     INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    TEXT    NOT NULL,
    book_id    INT     NOT NULL,
    event_type TEXT    NOT NULL,
    weight     INT     NOT NULL DEFAULT 1,
    is_deleted INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (book_id) REFERENCES books(book_id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_activity_rec  ON activity_logs(user_id, book_id, event_type, created_at);
CREATE INDEX IF NOT EXISTS idx_activity_view ON activity_logs(user_id, event_type, is_deleted, created_at);
CREATE INDEX IF NOT EXISTS idx_activity_date ON activity_logs(created_at);

-- ─── Reviews ───────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS reviews (
    review_id  INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    TEXT NOT NULL,
    book_id    INT  NOT NULL,
    rating     INT  NOT NULL CHECK (rating BETWEEN 1 AND 5),
    comment    TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (book_id) REFERENCES books(book_id) ON DELETE CASCADE,
    UNIQUE(user_id, book_id)
);

-- ─── Remember Tokens ───────────────────────────────────────────

CREATE TABLE IF NOT EXISTS remember_tokens (
    token_id   INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    TEXT    NOT NULL,
    token      TEXT    NOT NULL UNIQUE,
    expires_at TEXT    NOT NULL,
    created_at TEXT    NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_remember_token ON remember_tokens(token);

-- ─── Bookmarks ─────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS bookmarks (
    user_id    TEXT NOT NULL,
    book_id    INT  NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY (user_id, book_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (book_id) REFERENCES books(book_id) ON DELETE CASCADE
);

-- ─── Login Attempts (rate limiting) ────────────────────────────

CREATE TABLE IF NOT EXISTS login_attempts (
    attempt_id   INTEGER PRIMARY KEY AUTOINCREMENT,
    ip           TEXT    NOT NULL,
    attempt_time TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_login_attempts_ip_time ON login_attempts(ip, attempt_time);

-- ─── Admin Audit Trail ─────────────────────────────────────────

CREATE TABLE IF NOT EXISTS audit_logs (
    log_id      INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_id    TEXT NOT NULL,
    action      TEXT NOT NULL,
    target_type TEXT NOT NULL DEFAULT '',
    target_id   TEXT NOT NULL DEFAULT '',
    details     TEXT NOT NULL DEFAULT '{}',
    created_at  TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (admin_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_audit_logs_date  ON audit_logs(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_logs_admin ON audit_logs(admin_id);

-- ─── View: weighted recommendation scores ──────────────────────

DROP VIEW IF EXISTS user_book_scores;
CREATE VIEW user_book_scores AS
SELECT
    a.user_id,
    a.book_id,
    SUM(a.weight) AS score
FROM activity_logs a
WHERE a.is_deleted = 0
  AND a.created_at >= datetime('now', '-180 days')
GROUP BY a.user_id, a.book_id;

-- ─── Seed genres ──────────────────────────────────────────────

INSERT OR IGNORE INTO genres (name) VALUES
    ('Sci-Fi'),
    ('Fantasy'),
    ('Romance'),
    ('Non-Fiction'),
    ('Thriller');
