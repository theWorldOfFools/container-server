<?php
use function App\base_url;
use function App\csrf_token;
use function App\e;
?>
<div class="max-w-md mx-auto bg-slate-900 border border-slate-800 rounded-xl p-6 mt-10">
  <h1 class="text-xl font-bold mb-1">Login</h1>
  <p class="text-sm text-slate-400 mb-4">Masuk untuk kelola container Docker.</p>
  <?php if (!empty($error)): ?><div class="bg-red-900/50 border border-red-700 text-sm rounded p-2 mb-3"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e(base_url('index.php?r=do-login')) ?>" class="space-y-3">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div><label class="text-sm">Username</label><input name="username" required class="mt-1 w-full bg-slate-800 border border-slate-700 rounded px-3 py-2"></div>
    <div><label class="text-sm">Password</label><input name="password" type="password" required class="mt-1 w-full bg-slate-800 border border-slate-700 rounded px-3 py-2"></div>
    <button class="w-full bg-sky-600 hover:bg-sky-500 rounded py-2 font-semibold">Login</button>
  </form>
  <p class="text-xs text-slate-500 mt-3">User awal dibuat via <code>scripts/install.sh --seed-admin</code>.</p>
</div>
