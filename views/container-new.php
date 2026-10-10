<?php
use function App\base_url;
use function App\csrf_token;
use function App\e;
$old = $old ?? [];
?>
<h1 class="text-2xl font-bold mb-4">Buat Container</h1>
<?php if (!empty($error)): ?><div class="bg-red-900/50 border border-red-700 rounded p-3 text-sm mb-4"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="<?= e(base_url('index.php?r=container-create')) ?>" class="bg-slate-900 border border-slate-800 rounded-xl p-5 space-y-4 max-w-2xl">
<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
<div><label class="text-sm">Nama container</label><input name="name" required value="<?= e($old['name'] ?? '') ?>" placeholder="web-01" class="mt-1 w-full bg-slate-800 border border-slate-700 rounded px-3 py-2 font-mono"></div>
<div><label class="text-sm">Image (whitelist)</label><select name="image" class="mt-1 w-full bg-slate-800 border border-slate-700 rounded px-3 py-2 font-mono"><?php foreach ($images as $img): ?><option <?= (($old['image'] ?? '') === $img) ? 'selected' : '' ?>><?= e($img) ?></option><?php endforeach; ?></select><?php if (($user['role'] ?? '') === 'admin'): ?><input name="image" value="" placeholder="atau ketik manual (admin): mysql:8" class="mt-2 w-full hidden" disabled><p class="text-xs text-slate-500 mt-1">Admin boleh ganti via daftar settings; custom image diketik manual didukung (validasi longgar untuk admin).</p><?php endif; ?></div>
<div><label class="text-sm">Port mapping <span class="text-slate-500">(satu per baris / koma, format host:container, mis. 8080:80)</span></label><textarea name="ports" rows="2" placeholder="8080:80" class="mt-1 w-full bg-slate-800 border border-slate-700 rounded px-3 py-2 font-mono"><?= e($old['ports'] ?? '') ?></textarea></div>
<div><label class="text-sm">Environment <span class="text-slate-500">(KEY=VALUE per baris)</span></label><textarea name="env" rows="3" placeholder="MYSQL_ROOT_PASSWORD=rahasia" class="mt-1 w-full bg-slate-800 border border-slate-700 rounded px-3 py-2 font-mono"><?= e($old['env'] ?? '') ?></textarea></div>
<div class="grid grid-cols-2 gap-3">
<div><label class="text-sm">Memory (MB)</label><input name="memory" type="number" min="64" max="32768" value="<?= e($old['memory'] ?? '512') ?>" class="mt-1 w-full bg-slate-800 border border-slate-700 rounded px-3 py-2"></div>
<div><label class="text-sm">Restart policy</label><select name="restart" class="mt-1 w-full bg-slate-800 border border-slate-700 rounded px-3 py-2"><option>unless-stopped</option><option>always</option><option>on-failure</option><option>no</option></select></div>
</div>
<button class="bg-sky-600 hover:bg-sky-500 rounded py-2 px-5 font-semibold">Buat & Start</button>
<a href="<?= e(base_url('index.php')) ?>" class="text-sm text-slate-400 ml-2">Batal</a>
</form>
