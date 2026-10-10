<?php
declare(strict_types=1);

namespace App;

class DockerException extends \RuntimeException
{
}

class DockerClient
{
    private string $socket;
    private string $ver;

    public function __construct()
    {
        $this->socket = Config::get('DOCKER_SOCKET', '/var/run/docker.sock');
        $this->ver = Config::get('DOCKER_API_VERSION', 'v1.53');
        if (!str_starts_with($this->ver, 'v')) {
            $this->ver = 'v' . $this->ver;
        }
    }

    /** @return array{status:int, body:mixed, raw:string} */
    private function request(string $method, string $path, mixed $body = null, array $query = [], int $timeout = 30): array
    {
        if (!file_exists($this->socket) && $this->socket !== '') {
            // Biarkan curl yang error dengan pesan jelas.
        }
        $qs = $query ? '?' . http_build_query($query) : '';
        $url = 'http://localhost/' . $this->ver . $path . $qs;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, $this->socket);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        $headers = ['Accept: application/json'];
        if ($body !== null) {
            $payload = is_string($body) ? $body : json_encode($body);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new DockerException('Docker socket error: ' . $err . ' (pastikan www-data boleh baca ' . $this->socket . ')');
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $decoded = json_decode((string) $raw, true);
        if ($status >= 400) {
            $msg = is_array($decoded) ? ($decoded['message'] ?? (string) $raw) : (string) $raw;
            throw new DockerException('Docker API ' . $status . ': ' . substr($msg, 0, 500), $status);
        }
        return ['status' => $status, 'body' => $decoded, 'raw' => (string) $raw];
    }

    public function version(): array
    {
        $r = $this->request('GET', '/version');
        return is_array($r['body']) ? $r['body'] : [];
    }

    /** @return array<int,array> */
    public function listContainers(bool $all = true): array
    {
        $r = $this->request('GET', '/containers/json', null, ['all' => $all ? '1' : '0', 'size' => '1']);
        return is_array($r['body']) ? $r['body'] : [];
    }

    public function inspect(string $id): array
    {
        $r = $this->request('GET', '/containers/' . urlencode($id) . '/json');
        return is_array($r['body']) ? $r['body'] : [];
    }

    /** @return array<int,array> */
    public function listImages(): array
    {
        $r = $this->request('GET', '/images/json');
        return is_array($r['body']) ? $r['body'] : [];
    }

    public function imageExists(string $image): bool
    {
        try {
            $this->request('GET', '/images/' . urlencode($image) . '/json');
            return true;
        } catch (DockerException) {
            // fallback: cocokkan RepoTags dari list
            foreach ($this->listImages() as $img) {
                foreach (($img['RepoTags'] ?? []) as $tag) {
                    if ($tag === $image) {
                        return true;
                    }
                }
            }
            return false;
        }
    }

    public function pullImage(string $image): void
    {
        // POST /images/create?fromImage=...&tag=...
        $name = $image;
        $tag = 'latest';
        if (str_contains($image, ':') && !str_ends_with($image, ':')) {
            [$name, $tag] = explode(':', $image, 2);
        }
        $this->request('POST', '/images/create', null, ['fromImage' => $name, 'tag' => $tag], 300);
    }

    /**
     * @param array{Image:string, Env:string[], HostPorts:array, MemoryMB:int, NanoCPUs:int, Restart:string} $opts
     */
    public function createContainer(string $name, array $opts): string
    {
        $portBindings = [];
        $exposed = [];
        foreach ($opts['HostPorts'] as [$hp, $cp, $proto]) {
            $key = $cp . '/' . $proto;
            $exposed[$key] = new \stdClass();
            $portBindings[$key] = [['HostPort' => (string) $hp]];
        }
        $memBytes = max(0, (int) ($opts['MemoryMB'] ?? 0)) * 1024 * 1024;
        // Docker mengharapkan object {} (bukan array []) untuk map kosong.
        $exposedJson = $exposed === [] ? new \stdClass() : $exposed;
        $portBindingsJson = $portBindings === [] ? new \stdClass() : $portBindings;
        $body = [
            'Image' => $opts['Image'],
            'Env' => array_values($opts['Env'] ?? []),
            'ExposedPorts' => $exposedJson,
            'HostConfig' => [
                'PortBindings' => $portBindingsJson,
                'Memory' => $memBytes,
                'NanoCpus' => (int) ($opts['NanoCPUs'] ?? 0),
                'RestartPolicy' => ['Name' => $opts['Restart'] ?? 'unless-stopped'],
            ],
        ];
        $q = ['name' => ltrim($name, '/')];
        $r = $this->request('POST', '/containers/create', $body, $q);
        $id = $r['body']['Id'] ?? '';
        if (!$id) {
            throw new DockerException('Gagal create container: respon kosong dari Docker');
        }
        return $id;
    }

    public function start(string $id): void
    {
        $this->request('POST', '/containers/' . urlencode($id) . '/start');
    }

    public function stop(string $id, int $timeoutSec = 10): void
    {
        $this->request('POST', '/containers/' . urlencode($id) . '/stop', null, ['t' => (string) $timeoutSec]);
    }

    public function restart(string $id, int $timeoutSec = 10): void
    {
        $this->request('POST', '/containers/' . urlencode($id) . '/restart', null, ['t' => (string) $timeoutSec]);
    }

    public function remove(string $id, bool $force = false, bool $volumes = false): void
    {
        $this->request('DELETE', '/containers/' . urlencode($id), null, ['force' => $force ? '1' : '0', 'v' => $volumes ? '1' : '0']);
    }

    public function logs(string $id, int $tail = 200): string
    {
        // Docker mengembalikan multiplexed stream; untuk stdout+stderr non-tty ada header 8 byte.
        $qs = ['stdout' => '1', 'stderr' => '1', 'tail' => (string) $tail, 'timestamps' => '0'];
        $url = 'http://localhost/' . $this->ver . '/containers/' . urlencode($id) . '/logs?' . http_build_query($qs);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, $this->socket);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new DockerException('Gagal ambil logs: ' . $err);
        }
        curl_close($ch);
        return self::demux((string) $raw);
    }

    /** Stats one-shot (stream=false). */
    public function stats(string $id): array
    {
        $r = $this->request('GET', '/containers/' . urlencode($id) . '/stats', null, ['stream' => 'false'], 15);
        $s = is_array($r['body']) ? $r['body'] : [];
        return self::summarizeStats($s);
    }

    /** Ubah stats mentah jadi ringkas untuk chart. */
    public static function summarizeStats(array $s): array
    {
        $cpu = 0.0;
        try {
            $cpuDelta = ($s['cpu_stats']['cpu_usage']['total_usage'] ?? 0) - ($s['precpu_stats']['cpu_usage']['total_usage'] ?? 0);
            $sysDelta = ($s['cpu_stats']['system_cpu_usage'] ?? 0) - ($s['precpu_stats']['system_cpu_usage'] ?? 0);
            $cpus = count($s['cpu_stats']['cpu_usage']['percpu_usage'] ?? [1]);
            if ($sysDelta > 0 && $cpuDelta >= 0) {
                $cpu = round($cpuDelta / $sysDelta * $cpus * 100, 2);
            }
        } catch (\Throwable) {
        }
        $memUsage = (int) ($s['memory_stats']['usage'] ?? 0);
        $memLimit = (int) ($s['memory_stats']['limit'] ?? 0);
        $rx = $tx = 0;
        foreach (($s['networks'] ?? []) as $n) {
            $rx += (int) ($n['rx_bytes'] ?? 0);
            $tx += (int) ($n['tx_bytes'] ?? 0);
        }
        return [
            'cpu_percent' => $cpu,
            'mem_usage' => $memUsage,
            'mem_limit' => $memLimit,
            'mem_percent' => $memLimit > 0 ? round($memUsage / $memLimit * 100, 2) : 0,
            'net_rx' => $rx,
            'net_tx' => $tx,
            'pids' => (int) ($s['pids_stats']['current'] ?? 0),
        ];
    }

    /** Demux Docker multiplexed log stream (header 8 byte per frame). */
    public static function demux(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        // Heuristik: kalau tidak terlihat seperti frame multiplex, kembalikan apa adanya.
        $out = '';
        $len = strlen($raw);
        $pos = 0;
        $frames = 0;
        while ($pos + 8 <= $len) {
            $type = ord($raw[$pos]);
            if ($type > 2) {
                break; // bukan frame multiplex (misal container TTY) -> raw text
            }
            $size = unpack('N', substr($raw, $pos + 4, 4))[1] ?? 0;
            if ($size < 0 || $pos + 8 + $size > $len) {
                break;
            }
            $out .= substr($raw, $pos + 8, $size);
            $pos += 8 + $size;
            $frames++;
            if ($frames > 10000) {
                break;
            }
        }
        if ($frames === 0) {
            return $raw;
        }
        // Sisa yang tidak terparse ikutkan.
        if ($pos < $len) {
            $out .= substr($raw, $pos);
        }
        return $out;
    }

    /** Kumpulkan semua host port yang sedang dipakai container lain. */
    public function usedHostPorts(): array
    {
        $used = [];
        foreach ($this->listContainers(true) as $c) {
            foreach (($c['Ports'] ?? []) as $p) {
                if (isset($p['PublicPort'])) {
                    $used[(int) $p['PublicPort']] = true;
                }
            }
        }
        return $used;
    }
}
