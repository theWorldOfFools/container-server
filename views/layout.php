<?php
// $title, $user (opsional), $viewFile dari index.php
use function App\base_url;
use function App\csrf_token;
use function App\e;

$u = $user ?? \App\Auth::user();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Container Server') ?> — Container Server</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/xterm@5.3.0/css/xterm.css">
<style>body{font-family:ui-sans-serif,system-ui} pre{white-space:pre-wrap}</style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen">
<nav class="border-b border-slate-800 bg-slate-900/80 sticky top-0 z-10">
  <div class="max-w-6xl mx-auto px-4 py-3 flex items-center gap-4">
    <a href="<?= e(base_url('index.php')) ?>" class="font-bold text-lg">🐳 Container Server</a>
    <?php if ($u): ?>
      <a href="<?= e(base_url('index.php')) ?>" class="text-sm text-slate-300 hover:text-white">Dashboard</a>
      <a href="<?= e(base_url('index.php?r=container-new')) ?>" class="text-sm text-slate-300 hover:text-white">+ Buat</a>
      <?php if (($u['role'] ?? '') === 'admin'): ?>
        <a href="<?= e(base_url('index.php?r=users')) ?>" class="text-sm text-slate-300 hover:text-white">Users</a>
      <?php endif; ?>
      <span class="ml-auto text-sm text-slate-400"><?= e($u['username']) ?> (<?= e($u['role']) ?>)</span>
      <a href="<?= e(base_url('index.php?r=logout')) ?>" class="text-sm bg-slate-800 hover:bg-slate-700 px-3 py-1 rounded">Logout</a>
    <?php endif; ?>
  </div>
</nav>
<main class="max-w-6xl mx-auto px-4 py-6">
  <?php require $viewFile; ?>
</main>
<footer class="max-w-6xl mx-auto px-4 pb-8 text-xs text-slate-500">PHP + PostgreSQL + Docker Engine API · WS terminal :6789</footer>
</body>
</html>
