<?php
use function App\base_url;
use function App\csrf_token;
use function App\e;
?>
<div class="flex items-center gap-3 mb-4">
  <h1 class="text-2xl font-bold">Dashboard</h1>
  <a href="<?= e(base_url('index.php?r=container-new')) ?>" class="ml-auto bg-sky-600 hover:bg-sky-500 text-sm px-3 py-2 rounded font-semibold">+ Buat Container</a>
</div>
<?php if (!empty($dockerVer)): ?>
<div class="text-xs text-slate-400 mb-3">Docker <?= e($dockerVer['Version'] ?? '') ?> · API <?= e($dockerVer['ApiVersion'] ?? '') ?> · OS <?= e($dockerVer['Os'] ?? '') ?></div>
<?php endif; ?>
<?php if (!empty($listError)): ?>
<div class="bg-red-900/50 border border-red-700 rounded p-3 text-sm mb-4">Gagal konek Docker: <?= e($listError) ?><br><span class="text-slate-300">Pastikan <code>www-data</code> masuk group <code>docker</code> dan socket <code>/var/run/docker.sock</code> bisa dibaca.</span></div>
<?php endif; ?>
<div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
<table class="w-full text-sm">
<thead class="bg-slate-800/60 text-left"><tr><th class="p-3">Nama</th><th class="p-3">Image</th><th class="p-3">State</th><th class="p-3">Ports</th><?php if (($user['role'] ?? '') === 'admin'): ?><th class="p-3">Owner</th><?php endif; ?><th class="p-3 text-right">Aksi</th></tr></thead>
<tbody>
<?php if (empty($rows)): ?><tr><td colspan="6" class="p-6 text-center text-slate-400">Belum ada container. Klik “Buat Container”.</td></tr><?php endif; ?>
<?php foreach ($rows as $c): ?>
<tr class="border-t border-slate-800 hover:bg-slate-800/30">
  <td class="p-3 font-mono"><a class="text-sky-400 hover:underline" href="<?= e(base_url('index.php?r=container-detail&id=' . urlencode($c['Id']))) ?>"><?= e($c['Name'] ?: $c['Short']) ?></a><div class="text-xs text-slate-500"><?= e($c['Short']) ?></div></td>
  <td class="p-3 font-mono text-xs"><?= e($c['Image']) ?></td>
  <td class="p-3"><span class="px-2 py-1 rounded text-xs <?= ($c['State'] === 'running') ? 'bg-green-900 text-green-200' : 'bg-slate-700 text-slate-200' ?>"><?= e($c['State']) ?></span><div class="text-xs text-slate-500"><?= e($c['Status']) ?></div></td>
  <td class="p-3 font-mono text-xs"><?php foreach (($c['Ports'] ?? []) as $p): ?><?php if (isset($p['PublicPort'])): ?><div><?= e((string)$p['PublicPort']) ?>→<?= e((string)($p['PrivatePort'] ?? '')) ?></div><?php endif; ?><?php endforeach; ?></td>
  <?php if (($user['role'] ?? '') === 'admin'): ?><td class="p-3"><?= e($c['Owner']) ?></td><?php endif; ?>
  <td class="p-3"><div class="flex gap-1 justify-end">
    <?php if ($c['State'] === 'running'): ?>
      <form method="post" action="<?= e(base_url('index.php?r=container-action')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= e($c['Id']) ?>"><input type="hidden" name="op" value="stop"><button class="text-xs bg-amber-600 hover:bg-amber-500 px-2 py-1 rounded">Stop</button></form>
      <form method="post" action="<?= e(base_url('index.php?r=container-action')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= e($c['Id']) ?>"><input type="hidden" name="op" value="restart"><button class="text-xs bg-slate-700 hover:bg-slate-600 px-2 py-1 rounded">Restart</button></form>
    <?php else: ?>
      <form method="post" action="<?= e(base_url('index.php?r=container-action')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= e($c['Id']) ?>"><input type="hidden" name="op" value="start"><button class="text-xs bg-green-600 hover:bg-green-500 px-2 py-1 rounded">Start</button></form>
    <?php endif; ?>
    <form method="post" action="<?= e(base_url('index.php?r=container-action')) ?>" onsubmit="return confirm('Hapus container ini?')"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= e($c['Id']) ?>"><input type="hidden" name="op" value="remove"><button class="text-xs bg-red-700 hover:bg-red-600 px-2 py-1 rounded">Hapus</button></form>
  </div></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
