<?php
use function App\base_url;
use function App\csrf_token;
use function App\e;
?>
<h1 class="text-2xl font-bold mb-4">Kelola User</h1>
<div class="bg-slate-900 border border-slate-800 rounded-xl p-4 mb-4 max-w-2xl">
<h2 class="font-bold mb-2">Tambah user</h2>
<form method="post" action="<?= e(base_url('index.php?r=user-create')) ?>" class="grid md:grid-cols-5 gap-2">
<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
<input name="username" required placeholder="username" class="bg-slate-800 border border-slate-700 rounded px-3 py-2 text-sm">
<input name="password" required type="password" placeholder="password" class="bg-slate-800 border border-slate-700 rounded px-3 py-2 text-sm">
<select name="role" class="bg-slate-800 border border-slate-700 rounded px-3 py-2 text-sm"><option value="user">user</option><option value="admin">admin</option></select>
<input name="max_containers" type="number" value="5" title="max containers" class="bg-slate-800 border border-slate-700 rounded px-3 py-2 text-sm">
<input name="max_memory_mb" type="number" value="2048" title="max memory MB" class="bg-slate-800 border border-slate-700 rounded px-3 py-2 text-sm">
<button class="bg-sky-600 rounded px-3 py-2 text-sm font-semibold md:col-span-5">Tambah</button>
</form>
</div>
<div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
<table class="w-full text-sm"><thead class="bg-slate-800/60 text-left"><tr><th class="p-3">ID</th><th class="p-3">Username</th><th class="p-3">Role</th><th class="p-3">Limit</th><th class="p-3">Containers</th><th class="p-3"></th></tr></thead><tbody>
<?php foreach ($users as $u): ?>
<tr class="border-t border-slate-800"><td class="p-3"><?= e((string)$u['id']) ?></td><td class="p-3 font-mono"><?= e($u['username']) ?></td><td class="p-3"><?= e($u['role']) ?></td><td class="p-3 text-xs"><?= e((string)$u['max_containers']) ?> ct · <?= e((string)$u['max_memory_mb']) ?> MB</td><td class="p-3"><?= e((string)$u['n_containers']) ?></td>
<td class="p-3 text-right"><?php if ((int)$u['id'] !== (int)$user['id']): ?><form method="post" action="<?= e(base_url('index.php?r=user-delete')) ?>" onsubmit="return confirm('Hapus user ini?')"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= e((string)$u['id']) ?>"><button class="text-xs bg-red-700 px-2 py-1 rounded">Hapus</button></form><?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table>
</div>
