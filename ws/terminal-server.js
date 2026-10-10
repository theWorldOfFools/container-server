'use strict';
/**
 * WS bridge full-interaktif: xterm.js <--> Docker Exec TTY.
 *
 * Alur:
 *  1. Browser: POST /api exec-token (PHP) -> token sekali pakai 60 dtk (tabel exec_tokens)
 *  2. Browser: new WebSocket('ws://host:6789/?token=...')
 *  3. Server ini: validasi token ke PostgreSQL (tandai used), cek ownership/admin,
 *     lalu buat exec instance + attach hijacked stream ke /var/run/docker.sock
 *  4. stdin/stdout mentah (TTY, tanpa multiplex header) dipipe dua arah.
 */
const http = require('http');
const net = require('net');
const { URL } = require('url');
const { WebSocketServer } = require('ws');
const { Pool } = require('pg');
const fs = require('fs');

function loadEnv(baseDir) {
  const f = baseDir + '/.env';
  if (!fs.existsSync(f)) return;
  for (const line of fs.readFileSync(f, 'utf8').split('\n')) {
    const t = line.trim();
    if (!t || t.startsWith('#') || !t.includes('=')) continue;
    const i = t.indexOf('=');
    const k = t.slice(0, i).trim();
    let v = t.slice(i + 1).trim();
    if (v.length >= 2 && ((v[0] === '"' && v.slice(-1) === '"') || (v[0] === "'" && v.slice(-1) === "'"))) v = v.slice(1, -1);
    if (process.env[k] === undefined) process.env[k] = v;
  }
}
loadEnv(__dirname + '/..');

const WS_PORT = parseInt(process.env.WS_PORT || '6789', 10);
const WS_HOST = process.env.WS_HOST || '127.0.0.1';
const DOCKER_SOCKET = process.env.DOCKER_SOCKET || '/var/run/docker.sock';
const API_VER = (process.env.DOCKER_API_VERSION || 'v1.53').replace(/^v?/, 'v');

const dbHost = process.env.DB_HOST || '/var/run/postgresql';
const pool = new Pool({
  host: dbHost.startsWith('/') ? dbHost : dbHost,
  port: parseInt(process.env.DB_PORT || '5432', 10),
  database: process.env.DB_DATABASE || 'container_server',
  user: process.env.DB_USERNAME || 'container_app',
  password: process.env.DB_PASSWORD || '',
});

// --- Docker API helpers (non-hijack) via http over unix socket ---
function dockerRequest(method, path, body, timeoutMs = 15000) {
  return dockerRequestRaw(method, path, body, timeoutMs).then((buf) => {
    let j = null;
    try { j = buf.length ? JSON.parse(buf.toString()) : null; } catch { /* raw */ }
    return j;
  });
}

function dockerRequestRaw(method, path, body, timeoutMs = 15000) {
  return new Promise((resolve, reject) => {
    const payload = body ? JSON.stringify(body) : null;
    const req = http.request(
      { socketPath: DOCKER_SOCKET, path: `/${API_VER}${path}`, method,
        headers: { 'Content-Type': 'application/json', ...(payload ? { 'Content-Length': Buffer.byteLength(payload) } : {}) } },
      (res) => {
        const chunks = [];
        res.on('data', (c) => chunks.push(c));
        res.on('end', () => {
          const buf = Buffer.concat(chunks);
          if (res.statusCode >= 400) return reject(new Error(`Docker ${res.statusCode}: ${buf.toString().slice(0, 300)}`));
          resolve(buf);
        });
      }
    );
    req.on('error', reject);
    req.setTimeout(timeoutMs, () => req.destroy(new Error('Docker timeout')));
    if (payload) req.write(payload);
    req.end();
  });
}

// Probe shell yang benar-benar ada di image (exec create tidak validasi binary,
// jadi coba jalankan `<shell> -c 'echo probe-ok'` non-interaktif per kandidat).
async function pickShell(dockerId) {
  const candidates = [['/bin/bash'], ['/bin/sh'], ['sh']];
  for (const cmd of candidates) {
    try {
      const r = await dockerRequest('POST', `/containers/${encodeURIComponent(dockerId)}/exec`, {
        AttachStdin: false, AttachStdout: true, AttachStderr: true,
        Tty: true, Cmd: [...cmd, '-c', 'echo probe-ok'],
      });
      if (!r || !r.Id) continue;
      const out = await dockerRequestRaw('POST', `/exec/${encodeURIComponent(r.Id)}/start`, { Detach: false, Tty: true });
      if (out.toString().includes('probe-ok')) return cmd;
    } catch (e) { /* kandidat berikutnya */ }
  }
  return ['/bin/sh'];
}

async function createExec(dockerId) {
  const shell = await pickShell(dockerId);
  const r = await dockerRequest('POST', `/containers/${encodeURIComponent(dockerId)}/exec`, {
    AttachStdin: true, AttachStdout: true, AttachStderr: true,
    Tty: true, Cmd: shell, Env: [`TERM=xterm-256color`],
  });
  if (!r || !r.Id) throw new Error('Gagal membuat exec instance');
  return r.Id;
}

// Attach hijack: koneksi mentah ke docker.sock, kirim POST /exec/{id}/start, lalu stream raw.
function attachExecRaw(execId) {
  return new Promise((resolve, reject) => {
    const sock = net.connect(DOCKER_SOCKET);
    const payload = JSON.stringify({ Detach: false, Tty: true });
    const req =
      `POST /${API_VER}/exec/${execId}/start HTTP/1.1\r\n` +
      `Host: localhost\r\nContent-Type: application/json\r\n` +
      `Content-Length: ${Buffer.byteLength(payload)}\r\n` +
      `Connection: Upgrade\r\nUpgrade: tcp\r\n\r\n` + payload;
    let headerBuf = Buffer.alloc(0);
    let settled = false;
    const onData = (chunk) => {
      if (settled) return;
      headerBuf = Buffer.concat([headerBuf, chunk]);
      const idx = headerBuf.indexOf('\r\n\r\n');
      if (idx === -1) return;
      const header = headerBuf.slice(0, idx).toString();
      const rest = headerBuf.slice(idx + 4);
      if (!/^HTTP\/1\.1 (101|200|201) /.test(header)) {
        settled = true;
        sock.removeListener('data', onData);
        reject(new Error('Exec attach ditolak: ' + header.split('\r\n')[0]));
        sock.destroy();
        return;
      }
      settled = true;
      sock.removeListener('data', onData);
      resolve({ sock, prelude: rest });
    };
    sock.on('data', onData);
    sock.on('error', (e) => { if (!settled) { settled = true; reject(e); } });
    sock.on('connect', () => sock.write(req));
    setTimeout(() => { if (!settled) { settled = true; reject(new Error('Timeout attach exec')); sock.destroy(); } }, 15000);
  });
}

async function validateToken(token) {
  const client = await pool.connect();
  try {
    await client.query('BEGIN');
    const r = await client.query('SELECT * FROM exec_tokens WHERE token = $1 FOR UPDATE', [token]);
    const row = r.rows[0];
    if (!row || row.used || new Date(row.expires_at).getTime() < Date.now()) {
      await client.query('ROLLBACK');
      return null;
    }
    await client.query('UPDATE exec_tokens SET used = TRUE WHERE token = $1', [token]);
    const u = await client.query('SELECT id, username, role FROM users WHERE id = $1', [row.user_id]);
    const user = u.rows[0];
    if (!user) { await client.query('ROLLBACK'); return null; }
    if (user.role !== 'admin') {
      const own = await client.query('SELECT 1 FROM containers WHERE docker_id = $1 AND owner_id = $2', [row.docker_id, user.id]);
      // Izinkan juga docker short-id (12 char) karena PHP kadang kirim full tapi list pakai short.
      let ok = own.rowCount > 0;
      if (!ok) {
        const own2 = await client.query(
          "SELECT 1 FROM containers WHERE (docker_id LIKE $1 || '%') AND owner_id = $2",
          [String(row.docker_id).slice(0, 12), user.id]
        );
        ok = own2.rowCount > 0;
      }
      if (!ok) { await client.query('ROLLBACK'); return null; }
    }
    await client.query('COMMIT');
    try {
      await pool.query("DELETE FROM exec_tokens WHERE expires_at < NOW() - INTERVAL '1 hour'");
    } catch {}
    return { user, dockerId: row.docker_id };
  } catch (e) {
    try { await client.query('ROLLBACK'); } catch {}
    return null;
  } finally {
    client.release();
  }
}

const wss = new WebSocketServer({ host: WS_HOST, port: WS_PORT });
console.log(`[ws] listening on ${WS_HOST}:${WS_PORT} -> ${DOCKER_SOCKET} (${API_VER})`);

wss.on('connection', async (ws, req) => {
  let token = '';
  try {
    const u = new URL(req.url || '/', 'http://x');
    token = u.searchParams.get('token') || '';
  } catch {}
  if (!token) { ws.close(4401, 'token required'); return; }

  const auth = await validateToken(token);
  if (!auth) { ws.close(4403, 'token invalid/expired'); return; }

  let execId;
  try {
    execId = await createExec(auth.dockerId);
  } catch (e) {
    try { ws.send(`Gagal buka shell di container: ${e.message}\r\n`); } catch {}
    ws.close(4411, 'exec create failed');
    return;
  }

  let dock;
  try {
    dock = await attachExecRaw(execId);
  } catch (e) {
    try { ws.send(`Gagal attach ke container: ${e.message}\r\n`); } catch {}
    ws.close(4412, 'exec attach failed');
    return;
  }
  const dsock = dock.sock;
  if (dock.prelude && dock.prelude.length) {
    try { ws.send(dock.prelude); } catch {}
  }

  let closed = false;
  const closeBoth = () => {
    if (closed) return;
    closed = true;
    try { dsock.destroy(); } catch {}
    try { ws.close(); } catch {}
  };

  dsock.on('data', (chunk) => {
    if (ws.readyState === 1) { try { ws.send(chunk); } catch {} }
  });
  dsock.on('close', closeBoth);
  dsock.on('error', () => { try { ws.send('\r\n[docker socket error]\r\n'); } catch {} closeBoth(); });

  ws.on('message', async (raw) => {
    let msg;
    try { msg = JSON.parse(String(raw)); } catch {
      // fallback: kirim mentah sebagai keystroke
      try { dsock.write(Buffer.from(String(raw))); } catch {}
      return;
    }
    if (msg.t === 'in' && typeof msg.d === 'string') {
      try { dsock.write(Buffer.from(msg.d, 'utf8')); } catch {}
    } else if (msg.t === 'resize') {
      const cols = Math.max(20, Math.min(300, parseInt(msg.cols || '80', 10)));
      const rows = Math.max(10, Math.min(100, parseInt(msg.rows || '24', 10)));
      try { await dockerRequest('POST', `/exec/${encodeURIComponent(execId)}/resize`, null, 5000).catch(() => null); } catch {}
      // docker API resize pakai query h/w — kirim via request mentah agar pasti:
      try {
        await new Promise((resolve) => {
          const r = http.request(
            { socketPath: DOCKER_SOCKET, path: `/${API_VER}/exec/${encodeURIComponent(execId)}/resize?h=${rows}&w=${cols}`, method: 'POST' },
            (res) => { res.resume(); res.on('end', resolve); }
          );
          r.on('error', () => resolve());
          r.end();
        });
      } catch {}
    }
  });
  ws.on('close', closeBoth);
  ws.on('error', closeBoth);
});
