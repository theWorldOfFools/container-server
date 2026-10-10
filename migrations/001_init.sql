-- Container Server v1 — PostgreSQL schema
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(64) UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    role VARCHAR(16) NOT NULL DEFAULT 'user' CHECK (role IN ('admin','user')),
    max_containers INT NOT NULL DEFAULT 5,
    max_memory_mb INT NOT NULL DEFAULT 2048,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS containers (
    id SERIAL PRIMARY KEY,
    docker_id VARCHAR(128) UNIQUE NOT NULL,
    owner_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name VARCHAR(128) NOT NULL DEFAULT '',
    image VARCHAR(255) NOT NULL DEFAULT '',
    status_cache VARCHAR(32) NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_containers_owner ON containers(owner_id);

CREATE TABLE IF NOT EXISTS exec_tokens (
    token TEXT PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    docker_id VARCHAR(128) NOT NULL,
    expires_at TIMESTAMPTZ NOT NULL,
    used BOOLEAN NOT NULL DEFAULT FALSE
);
CREATE INDEX IF NOT EXISTS idx_exec_tokens_exp ON exec_tokens(expires_at);

CREATE TABLE IF NOT EXISTS activity_logs (
    id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(id) ON DELETE SET NULL,
    action VARCHAR(255) NOT NULL,
    docker_id VARCHAR(128) NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_logs_user ON activity_logs(user_id);

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL DEFAULT ''
);

-- Seed settings default (idempotent)
INSERT INTO settings(key, value) VALUES
    ('allowed_images', 'nginx:latest,php:8.2-apache,mysql:8,redis:7,node:20,postgres:16'),
    ('port_min', '8000'),
    ('port_max', '9000')
ON CONFLICT (key) DO NOTHING;
