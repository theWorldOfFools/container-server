<?php
use function App\base_url;
use function App\csrf_token;
use function App\e;
$st = $info['State'] ?? [];
$cfg = $info['Config'] ?? [];
$net = $info['NetworkSettings'] ?? [];
$running = (bool) ($st['Running'] ?? false);
?>
<div class="flex items-center gap-3 mb-4 flex-wrap">
  <h1 class="text-2xl font-bold font-mono"><?= e(ltrim($info['Name'] ?? '', '/')) ?></h1>
  <span class="px-2 py-1 rounded text-xs <?= $running ? 'bg-green-900 text-green-200' : 'bg-slate-700' ?>"><?= e($st['Status'] ?? 'unknown') ?></span>
  <div class="ml-auto flex gap-1">
    <?php if ($running): ?>
    <form method="post" action="<?= e(base_url('index.php?r=container-action')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="op" value="stop"><input type="hidden" name="back" value="<?= e(base_url('index.php?r=container-detail&id=' . urlencode($id))) ?>"><button class="text-xs bg-amber-600 px-3 py-2 rounded">Stop</button></form>
    <form method="post" action="<?= e(base_url('index.php?r=container-action')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="op" value="restart"><input type="hidden" name="back" value="<?= e(base_url('index.php?r=container-detail&id=' . urlencode($id))) ?>"><button class="text-xs bg-slate-700 px-3 py-2 rounded">Restart</button></form>
    <?php else: ?>
    <form method="post" action="<?= e(base_url('index.php?r=container-action')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="op" value="start"><input type="hidden" name="back" value="<?= e(base_url('index.php?r=container-detail&id=' . urlencode($id))) ?>"><button class="text-xs bg-green-600 px-3 py-2 rounded">Start</button></form>
    <?php endif; ?>
    <a href="<?= e(base_url('index.php')) ?>" class="text-xs bg-slate-800 px-3 py-2 rounded">← Kembali</a>
  </div>
</div>

<div class="grid md:grid-cols-3 gap-4 mb-4">
  <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 text-sm space-y-1">
    <div class="text-slate-400 text-xs uppercase">Info</div>
    <div>Image: <span class="font-mono"><?= e($cfg['Image'] ?? '') ?></span></div>
    <div>ID: <span class="font-mono text-xs"><?= e(substr($id, 0, 12)) ?></span></div>
    <div>PID: <?= e((string)($st['Pid'] ?? '')) ?> · Exit: <?= e((string)($st['ExitCode'] ?? '')) ?></div>
    <div>IP: <span class="font-mono"><?= e($net['IPAddress'] ?? '') ?></span></div>
  </div>
  <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 text-sm md:col-span-2">
    <div class="text-slate-400 text-xs uppercase mb-2">Live stats <span id="stats-ts" class="text-slate-600"></span></div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-center">
      <div class="bg-slate-800 rounded p-3"><div class="text-xs text-slate-400">CPU</div><div id="stat-cpu" class="text-xl font-bold">–</div></div>
      <div class="bg-slate-800 rounded p-3"><div class="text-xs text-slate-400">RAM</div><div id="stat-mem" class="text-xl font-bold">–</div><div id="stat-memp" class="text-xs text-slate-400"></div></div>
      <div class="bg-slate-800 rounded p-3"><div class="text-xs text-slate-400">NET RX/TX</div><div id="stat-net" class="text-sm font-mono pt-1">–</div></div>
      <div class="bg-slate-800 rounded p-3"><div class="text-xs text-slate-400">PIDs</div><div id="stat-pids" class="text-xl font-bold">–</div></div>
    </div>
  </div>
</div>

<div class="bg-slate-900 border border-slate-800 rounded-xl p-4 mb-4">
  <div class="flex items-center gap-2 mb-2">
    <h2 class="font-bold">🖥️ Web Terminal (full interaktif)</h2>
    <span id="ws-status" class="text-xs text-slate-400">disconnected</span>
    <button id="btn-term" class="ml-auto text-xs bg-sky-600 hover:bg-sky-500 px-3 py-2 rounded font-semibold" <?= $running ? '' : 'disabled' ?>>Connect</button>
    <button id="btn-disconnect" class="text-xs bg-slate-700 px-3 py-2 rounded">Disconnect</button>
  </div>
  <?php if (!$running): ?><p class="text-xs text-amber-400 mb-2">Container tidak running — start dulu untuk buka terminal.</p><?php endif; ?>
  <div id="terminal" class="rounded bg-black" style="height:380px"></div>
  <p class="text-xs text-slate-500 mt-2">Alur aman: PHP terbitkan token sekali pakai (60 dtk) → browser buka WebSocket → Node validasi ke PostgreSQL → attach ke Docker Exec TTY.</p>
</div>

<div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
  <div class="flex items-center gap-2 mb-2">
    <h2 class="font-bold">📜 Logs</h2>
    <button id="btn-logs" class="ml-auto text-xs bg-slate-700 hover:bg-slate-600 px-3 py-1 rounded">Refresh</button>
    <label class="text-xs text-slate-400">tail <input id="logs-tail" type="number" value="300" min="50" max="2000" class="w-20 bg-slate-800 border border-slate-700 rounded px-2 py-1 text-xs"></label>
  </div>
  <pre id="logs" class="bg-black rounded p-3 text-xs h-64 overflow-auto">memuat…</pre>
</div>

<script src="https://cdn.jsdelivr.net/npm/xterm@5.3.0/lib/xterm.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xterm-addon-fit@0.8.0/lib/xterm-addon-fit.js"></script>
<script>
const CID = <?= json_encode($id) ?>;
const BASE = <?= json_encode(base_url('index.php')) ?>;
const CSRF = <?= json_encode(csrf_token()) ?>;
function fmtBytes(n){ if(!n) return '0 B'; const u=['B','KB','MB','GB']; let i=0; while(n>=1024&&i<3){n/=1024;i++;} return n.toFixed(1)+' '+u[i]; }

// ---- stats polling ----
async function loadStats(){
  try{
    const r = await fetch(`${BASE}?r=api-stats&id=${encodeURIComponent(CID)}`);
    const j = await r.json();
    if(!j.ok) return;
    document.getElementById('stat-cpu').textContent = j.stats.cpu_percent + ' %';
    document.getElementById('stat-mem').textContent = fmtBytes(j.stats.mem_usage);
    document.getElementById('stat-memp').textContent = j.stats.mem_percent + '% dari ' + fmtBytes(j.stats.mem_limit);
    document.getElementById('stat-net').textContent = fmtBytes(j.stats.net_rx) + ' / ' + fmtBytes(j.stats.net_tx);
    document.getElementById('stat-pids').textContent = j.stats.pids;
    document.getElementById('stats-ts').textContent = new Date().toLocaleTimeString();
  }catch(e){}
}
// ---- logs ----
async function loadLogs(){
  const tail = document.getElementById('logs-tail').value || 300;
  const el = document.getElementById('logs');
  try{
    const r = await fetch(`${BASE}?r=api-logs&id=${encodeURIComponent(CID)}&tail=${encodeURIComponent(tail)}`);
    const j = await r.json();
    el.textContent = j.ok ? (j.logs || '(kosong)') : ('Error: ' + j.error);
    el.scrollTop = el.scrollHeight;
  }catch(e){ el.textContent = 'Gagal memuat logs: ' + e; }
}
document.getElementById('btn-logs').onclick = loadLogs;
loadStats(); loadLogs();
setInterval(loadStats, 3000);

// ---- terminal ----
const term = new Terminal({cursorBlink:true, fontSize:14});
const fit = new FitAddon.FitAddon();
term.loadAddon(fit);
term.open(document.getElementById('terminal'));
fit.fit();
let ws = null;
function setStatus(s){ document.getElementById('ws-status').textContent = s; }
document.getElementById('btn-term').onclick = async () => {
  if(ws) ws.close();
  setStatus('meminta token…');
  const fd = new FormData();
  fd.append('id', CID); fd.append('csrf', CSRF);
  let t;
  try{
    const r = await fetch(`${BASE}?r=api-exec-token`, {method:'POST', body:fd});
    const j = await r.json();
    if(!j.ok){ term.writeln('\r\nError: ' + j.error); setStatus('token gagal'); return; }
    t = j;
  }catch(e){ term.writeln('Gagal minta token: ' + e); return; }
  const url = t.ws_url.replace(/^http/, 'ws').replace(/\/$/, '') + '/?token=' + encodeURIComponent(t.token);
  term.writeln('Menghubungkan ke ' + t.ws_url + ' …');
  ws = new WebSocket(url);
  ws.binaryType = 'arraybuffer';
  setStatus('connecting…');
  ws.onopen = () => { setStatus('connected'); term.focus(); fit.fit(); sendResize(); };
  ws.onmessage = (ev) => {
    if(typeof ev.data === 'string'){ term.write(ev.data); }
    else { term.write(new Uint8Array(ev.data)); }
  };
  ws.onclose = () => { setStatus('disconnected'); term.writeln('\r\n--- koneksi terminal ditutup ---'); ws = null; };
  ws.onerror = () => setStatus('error');
  term.onData(d => { if(ws && ws.readyState === 1) ws.send(JSON.stringify({t:'in', d})); });
  term.onResize(({cols, rows}) => sendResize(cols, rows));
  function sendResize(cols, rows){
    cols = cols || term.cols; rows = rows || term.rows;
    if(ws && ws.readyState === 1) ws.send(JSON.stringify({t:'resize', cols, rows}));
  }
  window.addEventListener('resize', () => { fit.fit(); });
};
document.getElementById('btn-disconnect').onclick = () => { if(ws) ws.close(); };
</script>
