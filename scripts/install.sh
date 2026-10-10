#!/usr/bin/env bash
set -euo pipefail
# Install Container Server (PHP + PostgreSQL + Docker + WS bridge)
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"

echo "== 1. Cek dependensi =="
php -v | head -1
for ext in pdo_pgsql curl json mbstring; do
  php -m | grep -qi "^$ext" && echo "  php-$ext OK" || { echo "  php-$ext HILANG — install: apt install php8.2-$ext"; exit 1; }
done
command -v composer >/dev/null && echo "  composer OK" || { echo "composer hilang"; exit 1; }
command -v node >/dev/null && echo "  node $(node --version) OK" || { echo "node hilang"; exit 1; }
[ -S "${DOCKER_SOCKET:-/var/run/docker.sock}" ] && echo "  docker.sock OK" || echo "  WARN: /var/run/docker.sock tidak ada"

echo "== 2. .env =="
if [ ! -f .env ]; then cp .env.example .env; echo "  .env dibuat dari example — silakan edit DB_PASSWORD dkk"; fi
set -a; source .env; set +a

echo "== 3. PostgreSQL role + database =="
sudo -u postgres psql -v ON_ERROR_STOP=1 <<SQL
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '${DB_USERNAME}') THEN
    CREATE ROLE "${DB_USERNAME}" LOGIN PASSWORD '${DB_PASSWORD}';
  ELSE
    ALTER ROLE "${DB_USERNAME}" WITH LOGIN PASSWORD '${DB_PASSWORD}';
  END IF;
END
\$\$;
SELECT 'role ok' WHERE EXISTS (SELECT FROM pg_roles WHERE rolname = '${DB_USERNAME}');
SQL
if ! sudo -u postgres psql -lqt | cut -d'|' -f1 | grep -qw "${DB_DATABASE}"; then
  sudo -u postgres createdb -O "${DB_USERNAME}" "${DB_DATABASE}"
  echo "  database ${DB_DATABASE} dibuat"
else
  echo "  database ${DB_DATABASE} sudah ada"
fi

echo "== 4. Migrasi =="
export PGPASSWORD="${DB_PASSWORD}"
psql -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -f migrations/001_init.sql

if [ "${1:-}" = "--seed-admin" ]; then
  ADMIN_USER="${ADMIN_USER:-admin}"
  ADMIN_PASS="${ADMIN_PASS:-admin123}"
  HASH="$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$ADMIN_PASS")"
  PGPASSWORD="${DB_PASSWORD}" psql -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" \
    -c "INSERT INTO users(username,password_hash,role,max_containers,max_memory_mb) VALUES('${ADMIN_USER}','${HASH}','admin',100,32768) ON CONFLICT (username) DO UPDATE SET password_hash=EXCLUDED.password_hash, role='admin';"
  echo "  admin '${ADMIN_USER}' siap (ganti password setelah login!)"
fi

echo "== 5. composer + npm =="
composer install --no-dev --optimize-autoloader
(cd ws && npm ci --omit=dev 2>/dev/null || npm install --omit=dev)

echo "== 6. Permission & Apache =="
echo "  Jalankan sekali sebagai root bila belum:"
echo "    usermod -aG docker www-data   # agar PHP bisa baca docker.sock"
echo "    a2enmod proxy proxy_http proxy_wstunnel rewrite && systemctl reload apache2"
echo "    cp deploy/apache-container-server.conf /etc/apache2/conf-available/ && a2enconf container-server && systemctl reload apache2"
echo "  WS daemon (systemd):"
echo "    useradd -r -g docker -s /usr/sbin/nologin container-ws  # sekali saja"
echo "    cp deploy/container-ws.service /etc/systemd/system/ && systemctl enable --now container-ws"
echo
echo "Selesai. Buka ${APP_URL:-http://localhost/container-server}"
