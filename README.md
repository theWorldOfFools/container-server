# 🐳 Container Server — Web Base Docker Panel (1 Server)

PHP 8.2 + PostgreSQL 16 + Docker Engine API + WebSocket terminal full-interaktif (xterm.js).

## Fitur v1
- Login multi-user, role `admin`/`user`, limit per user (jumlah container, RAM, whitelist image, port range)
- CRUD container: create (auto-pull + auto-start), start/stop/restart/remove, inspect
- Logs (polling) + stats live CPU/RAM/NET/PIDs (polling 3 dtk)
- Web terminal **full PTY** di browser: `xterm.js` → Apache ProxyPass → Node WS `:6789` → Docker Exec TTY. Otorisasi via token sekali pakai 60 dtk (`exec_tokens`)

## Struktur
```
public/index.php      # front controller (pages + JSON API)
src/{Config,Db,Auth,DockerClient,ExecToken,Helpers}.php
views/{layout,login,dashboard,container-new,container-detail,users}.php
migrations/001_init.sql
ws/{terminal-server.js,package.json}
scripts/install.sh
deploy/{apache-container-server.conf,container-ws.service}
```

## Install cepat (Ubuntu)
```bash
cd /var/www/html/container-server
cp .env.example .env   # edit DB_PASSWORD!
bash scripts/install.sh --seed-admin
# sekali sebagai root:
usermod -aG docker www-data
a2enmod proxy proxy_http proxy_wstunnel rewrite
cp deploy/apache-container-server.conf /etc/apache2/conf-available/container-server.conf
a2enconf container-server && systemctl reload apache2
useradd -r -g docker -s /usr/sbin/nologin container-ws || true
cp deploy/container-ws.service /etc/systemd/system/
systemctl enable --now container-ws
```
Buka `http://<host>/container-server` → login admin.

## API (butuh login)
- `GET ?r=api-containers` — list + ownership
- `GET ?r=api-logs&id=&tail=` — logs
- `GET ?r=api-stats&id=` — `{cpu_percent, mem_usage, mem_limit, mem_percent, net_rx, net_tx, pids}`
- `POST ?r=api-exec-token` (`id`, `csrf`) — `{token, ws_url}` untuk WS

## WS_URL
- Dev langsung: `ws://127.0.0.1:6789/?token=...`
- Produksi via Apache: set `WS_URL=http://host/container-server-ws/` di `.env` → frontend otomatis pakai path proxy.

## Keamanan
- Token exec sekali pakai + expiry; WS validasi ke PG + cek ownership (admin bebas)
- Validasi nama/image/port; port dicek tabrakan via `usedHostPorts()`; memory & jumlah container dibatasi per user
- Jangan mount `/var/run/docker.sock` ke container user; `www-data` & `container-ws` hanya boleh baca socket host.
