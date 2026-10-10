<?php
declare(strict_types=1);

use App\Auth;
use App\Config;
use App\Db;
use App\DockerClient;
use App\DockerException;
use App\ExecToken;

require __DIR__ . '/../src/Config.php';
require __DIR__ . '/../src/Helpers.php';
require __DIR__ . '/../src/Db.php';
require __DIR__ . '/../src/Auth.php';
require __DIR__ . '/../src/DockerClient.php';
require __DIR__ . '/../src/ExecToken.php';

use function App\base_url;
use function App\csrf_check;
use function App\csrf_token;
use function App\e;
use function App\json;
use function App\parse_env_text;
use function App\parse_ports_text;
use function App\redirect;
use function App\valid_container_name;

Config::load(dirname(__DIR__));
if (!is_file(dirname(__DIR__) . '/.env')) {
    http_response_code(500);
    exit('File .env belum ada. Copy dari .env.example lalu sesuaikan, kemudian jalankan scripts/install.sh');
}
Auth::start();

$r = $_GET['r'] ?? 'dashboard';
$method = $_SERVER['REQUEST_METHOD'];

// ---------- helpers lokal ----------
function view(string $name, array $vars = []): void
{
    extract($vars);
    $viewFile = __DIR__ . '/../views/' . $name . '.php';
    require __DIR__ . '/../views/layout.php';
}

function setting(string $key, string $fallback = ''): string
{
    try {
        $s = Db::pdo()->prepare('SELECT value FROM settings WHERE key = :k');
        $s->execute([':k' => $key]);
        $v = $s->fetchColumn();
        return $v === false ? $fallback : (string) $v;
    } catch (Throwable) {
        return $fallback;
    }
}

function allowed_images(): array
{
    $fromDb = setting('allowed_images', '');
    if ($fromDb !== '') {
        return array_values(array_filter(array_map('trim', explode(',', $fromDb))));
    }
    return Config::allowedImages();
}

/** Gabungkan docker list + tabel containers (ownership). */
function merged_containers(DockerClient $docker, ?array $user): array
{
    $isAdmin = $user && ($user['role'] ?? '') === 'admin';
    try {
        $list = $docker->listContainers(true);
    } catch (DockerException $ex) {
        return ['error' => $ex->getMessage(), 'rows' => []];
    }
    $owners = [];
    try {
        $q = Db::pdo()->query('SELECT docker_id, owner_id, username FROM containers LEFT JOIN users ON users.id = containers.owner_id');
        foreach ($q->fetchAll() as $row) {
            $owners[substr((string) $row['docker_id'], 0, 12)] = $row;
            $owners[(string) $row['docker_id']] = $row;
        }
    } catch (Throwable) {
    }
    $rows = [];
    foreach ($list as $c) {
        $id = (string) ($c['Id'] ?? '');
        $short = substr($id, 0, 12);
        $owner = $owners[$id] ?? $owners[$short] ?? null;
        if (!$isAdmin) {
            if (!$owner || (int) ($owner['owner_id'] ?? -1) !== (int) $user['id']) {
                continue;
            }
        }
        $name = ltrim((string) (($c['Names'][0] ?? '')), '/');
        $rows[] = [
            'Id' => $id,
            'Short' => $short,
            'Name' => $name,
            'Image' => (string) ($c['Image'] ?? ''),
            'State' => (string) ($c['State'] ?? ''),
            'Status' => (string) ($c['Status'] ?? ''),
            'Ports' => $c['Ports'] ?? [],
            'Owner' => $owner['username'] ?? ($isAdmin ? '(unmanaged)' : ''),
        ];
    }
    return ['error' => null, 'rows' => $rows];
}

// ================= ROUTES =================
if ($r === 'login') {
    if (Auth::check()) {
        redirect(base_url('index.php'));
    }
    view('login', ['title' => 'Login']);
    exit;
}

if ($r === 'do-login' && $method === 'POST') {
    csrf_check();
    $u = trim($_POST['username'] ?? '');
    $p = (string) ($_POST['password'] ?? '');
    if (Auth::login($u, $p)) {
        Auth::log(Auth::user()['id'] ?? null, 'login');
        redirect(base_url('index.php'));
    }
    view('login', ['title' => 'Login', 'error' => 'Username / password salah']);
    exit;
}

if ($r === 'logout') {
    Auth::log(Auth::user()['id'] ?? null, 'logout');
    Auth::logout();
    redirect(base_url('index.php?r=login'));
}

// Semua route di bawah wajib login
$user = Auth::user();
if (!$user) {
    redirect(base_url('index.php?r=login'));
}
Auth::refresh();
$user = Auth::user();

$docker = new DockerClient();

// ---------- JSON API ----------
if ($r === 'api-containers') {
    $m = merged_containers($docker, $user);
    if ($m['error']) {
        json(['ok' => false, 'error' => $m['error']], 500);
    }
    json(['ok' => true, 'containers' => $m['rows']]);
}

if ($r === 'api-logs') {
    $id = (string) ($_GET['id'] ?? '');
    if ($id === '' || !Auth::canAccessContainer($id)) {
        json(['ok' => false, 'error' => 'Forbidden'], 403);
    }
    try {
        $tail = max(50, min(2000, (int) ($_GET['tail'] ?? 300)));
        json(['ok' => true, 'logs' => $docker->logs($id, $tail)]);
    } catch (DockerException $ex) {
        json(['ok' => false, 'error' => $ex->getMessage()], 500);
    }
}

if ($r === 'api-stats') {
    $id = (string) ($_GET['id'] ?? '');
    if ($id === '' || !Auth::canAccessContainer($id)) {
        json(['ok' => false, 'error' => 'Forbidden'], 403);
    }
    try {
        json(['ok' => true, 'stats' => $docker->stats($id)]);
    } catch (DockerException $ex) {
        json(['ok' => false, 'error' => $ex->getMessage()], 500);
    }
}

if ($r === 'api-exec-token' && $method === 'POST') {
    csrf_check();
    $id = (string) ($_POST['id'] ?? '');
    if ($id === '' || !Auth::canAccessContainer($id)) {
        json(['ok' => false, 'error' => 'Forbidden'], 403);
    }
    try {
        $info = $docker->inspect($id);
        if (!($info['State']['Running'] ?? false)) {
            json(['ok' => false, 'error' => 'Container tidak running — start dulu sebelum buka terminal'], 400);
        }
    } catch (DockerException $ex) {
        json(['ok' => false, 'error' => $ex->getMessage()], 500);
    }
    ExecToken::cleanup();
    $token = ExecToken::create((int) $user['id'], $id, 60);
    Auth::log((int) $user['id'], 'exec-token', $id);
    $wsBase = rtrim(Config::get('WS_URL', 'ws://localhost:6789'), '/');
    json(['ok' => true, 'token' => $token, 'ws_url' => $wsBase]);
}

// ---------- PAGES ----------
if ($r === 'container-new') {
    view('container-new', ['title' => 'Buat Container', 'images' => allowed_images(), 'user' => $user]);
    exit;
}

if ($r === 'container-create' && $method === 'POST') {
    csrf_check();
    $name = trim((string) ($_POST['name'] ?? ''));
    $image = trim((string) ($_POST['image'] ?? ''));
    $portsText = (string) ($_POST['ports'] ?? '');
    $envText = (string) ($_POST['env'] ?? '');
    $memory = max(64, min(32768, (int) ($_POST['memory'] ?? 512)));
    $restart = in_array(($_POST['restart'] ?? ''), ['no', 'always', 'unless-stopped', 'on-failure'], true) ? $_POST['restart'] : 'unless-stopped';

    $error = null;
    if (!valid_container_name($name)) {
        $error = 'Nama container tidak valid (huruf/angka, boleh _ . -)';
    } elseif (!in_array($image, allowed_images(), true) && !Auth::isAdmin()) {
        $error = 'Image tidak diizinkan. Pilih dari daftar.';
    }
    // Limit user
    $pdo = Db::pdo();
    $me = $pdo->prepare('SELECT max_containers, max_memory_mb FROM users WHERE id = :id')->execute([':id' => $user['id']])
        ? $pdo->prepare('SELECT max_containers, max_memory_mb FROM users WHERE id = :id') : null;
    $limits = ['max_containers' => 5, 'max_memory_mb' => 2048];
    try {
        $s = Db::pdo()->prepare('SELECT max_containers, max_memory_mb FROM users WHERE id = :id');
        $s->execute([':id' => $user['id']]);
        $limits = $s->fetch() ?: $limits;
        $cnt = Db::pdo()->prepare('SELECT COUNT(*) FROM containers WHERE owner_id = :o');
        $cnt->execute([':o' => $user['id']]);
        if (!Auth::isAdmin() && (int) $cnt->fetchColumn() >= (int) $limits['max_containers']) {
            $error = $error ?? ('Limit tercapai: maksimal ' . $limits['max_containers'] . ' container');
        }
        if (!Auth::isAdmin() && $memory > (int) $limits['max_memory_mb']) {
            $error = $error ?? ('Memory melebihi limit user (' . $limits['max_memory_mb'] . ' MB)');
        }
    } catch (Throwable $t) {
        $error = $error ?? ('DB error: ' . $t->getMessage());
    }

    $hostPorts = parse_ports_text($portsText);
    $pmin = (int) setting('port_min', Config::get('PORT_RANGE_MIN', '8000'));
    $pmax = (int) setting('port_max', Config::get('PORT_RANGE_MAX', '9000'));
    if ($error === null) {
        try {
            $used = $docker->usedHostPorts();
            foreach ($hostPorts as [$hp]) {
                if (!Auth::isAdmin() && ($hp < $pmin || $hp > $pmax)) {
                    $error = "Host port $hp di luar range $pmin-$pmax";
                    break;
                }
                if (isset($used[$hp])) {
                    $error = "Host port $hp sudah dipakai container lain";
                    break;
                }
            }
        } catch (DockerException $ex) {
            $error = $ex->getMessage();
        }
    }

    if ($error !== null) {
        view('container-new', ['title' => 'Buat Container', 'images' => allowed_images(), 'user' => $user, 'error' => $error, 'old' => $_POST]);
        exit;
    }

    try {
        if (!$docker->imageExists($image)) {
            $docker->pullImage($image);
        }
        $env = parse_env_text($envText);
        $id = $docker->createContainer($name, [
            'Image' => $image,
            'Env' => $env,
            'HostPorts' => $hostPorts,
            'MemoryMB' => $memory,
            'NanoCPUs' => 0,
            'Restart' => $restart,
        ]);
        $docker->start($id);
        $ins = Db::pdo()->prepare('INSERT INTO containers(docker_id, owner_id, name, image, status_cache) VALUES(:d,:o,:n,:i,:s) ON CONFLICT (docker_id) DO UPDATE SET owner_id=EXCLUDED.owner_id, name=EXCLUDED.name, image=EXCLUDED.image');
        $ins->execute([':d' => $id, ':o' => $user['id'], ':n' => $name, ':i' => $image, ':s' => 'running']);
        Auth::log((int) $user['id'], 'create+start ' . $name . ' (' . $image . ')', $id);
        redirect(base_url('index.php?r=container-detail&id=' . urlencode($id)));
    } catch (DockerException $ex) {
        view('container-new', ['title' => 'Buat Container', 'images' => allowed_images(), 'user' => $user, 'error' => $ex->getMessage(), 'old' => $_POST]);
        exit;
    }
}

if ($r === 'container-action' && $method === 'POST') {
    csrf_check();
    $id = (string) ($_POST['id'] ?? '');
    $op = (string) ($_POST['op'] ?? '');
    if (!Auth::canAccessContainer($id)) {
        http_response_code(403);
        exit('Forbidden');
    }
    try {
        match ($op) {
            'start' => $docker->start($id),
            'stop' => $docker->stop($id),
            'restart' => $docker->restart($id),
            'remove' => (function () use ($docker, $id) {
                $docker->stop($id);
                // abaikan error stop kalau sudah berhenti
            })(),
            default => throw new DockerException('Operasi tidak dikenal'),
        };
        if ($op === 'remove') {
            try {
                $docker->remove($id, true, false);
            } catch (DockerException $e) {
                // coba force tanpa stop
                $docker->remove($id, true, false);
            }
            try {
                Db::pdo()->prepare('DELETE FROM containers WHERE docker_id = :d')->execute([':d' => $id]);
            } catch (Throwable) {
            }
        } else {
            try {
                Db::pdo()->prepare('UPDATE containers SET status_cache = :s WHERE docker_id = :d')->execute([':s' => $op, ':d' => $id]);
            } catch (Throwable) {
            }
        }
        Auth::log((int) $user['id'], $op, $id);
        $back = $_POST['back'] ?? base_url('index.php');
        redirect($op === 'remove' ? base_url('index.php') : $back);
    } catch (DockerException $ex) {
        http_response_code(500);
        exit('Docker error: ' . e($ex->getMessage()));
    }
}

if ($r === 'container-detail') {
    $id = (string) ($_GET['id'] ?? '');
    if ($id === '' || !Auth::canAccessContainer($id)) {
        http_response_code(403);
        exit('Forbidden / container tidak ditemukan');
    }
    try {
        $info = $docker->inspect($id);
    } catch (DockerException $ex) {
        http_response_code(500);
        exit('Docker error: ' . e($ex->getMessage()));
    }
    view('container-detail', ['title' => 'Detail Container', 'info' => $info, 'user' => $user, 'id' => $id]);
    exit;
}

if ($r === 'users') {
    Auth::requireAdmin();
    $users = Db::pdo()->query('SELECT u.*, (SELECT COUNT(*) FROM containers c WHERE c.owner_id = u.id) AS n_containers FROM users u ORDER BY u.id')->fetchAll();
    view('users', ['title' => 'Kelola User', 'users' => $users, 'user' => $user]);
    exit;
}

if ($r === 'user-create' && $method === 'POST') {
    Auth::requireAdmin();
    csrf_check();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
    $maxc = max(1, min(100, (int) ($_POST['max_containers'] ?? 5)));
    $maxm = max(128, min(32768, (int) ($_POST['max_memory_mb'] ?? 2048)));
    if ($username === '' || $password === '') {
        redirect(base_url('index.php?r=users'));
    }
    try {
        $s = Db::pdo()->prepare('INSERT INTO users(username, password_hash, role, max_containers, max_memory_mb) VALUES(:u,:p,:r,:c,:m)');
        $s->execute([':u' => $username, ':p' => password_hash($password, PASSWORD_DEFAULT), ':r' => $role, ':c' => $maxc, ':m' => $maxm]);
        Auth::log((int) $user['id'], 'user-create ' . $username);
    } catch (Throwable) {
    }
    redirect(base_url('index.php?r=users'));
}

if ($r === 'user-delete' && $method === 'POST') {
    Auth::requireAdmin();
    csrf_check();
    $delId = (int) ($_POST['id'] ?? 0);
    if ($delId !== (int) $user['id'] && $delId > 0) {
        try {
            Db::pdo()->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $delId]);
            Auth::log((int) $user['id'], 'user-delete #' . $delId);
        } catch (Throwable) {
        }
    }
    redirect(base_url('index.php?r=users'));
}

// default: dashboard
$m = merged_containers($docker, $user);
$dockerVer = [];
try {
    $dockerVer = $docker->version();
} catch (Throwable) {
}
view('dashboard', ['title' => 'Dashboard', 'rows' => $m['rows'], 'listError' => $m['error'], 'dockerVer' => $dockerVer, 'user' => $user]);
