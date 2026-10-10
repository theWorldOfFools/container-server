<?php
declare(strict_types=1);

namespace App;

/** Token sekali pakai untuk otorisasi koneksi WebSocket terminal. */
class ExecToken
{
    public static function create(int $userId, string $dockerId, int $ttlSec = 60): string
    {
        $token = bin2hex(random_bytes(24));
        $stmt = Db::pdo()->prepare(
            'INSERT INTO exec_tokens(token, user_id, docker_id, expires_at) VALUES(:t,:u,:d, NOW() + (:ttl || \' seconds\')::interval)'
        );
        $stmt->execute([':t' => $token, ':u' => $userId, ':d' => $dockerId, ':ttl' => (string) $ttlSec]);
        return $token;
    }

    /** Dipakai WS server (Node) untuk validasi + tandai used. */
    public static function consume(string $token): ?array
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM exec_tokens WHERE token = :t FOR UPDATE');
            $stmt->execute([':t' => $token]);
            $row = $stmt->fetch();
            if (!$row || ($row['used'] ?? false) || strtotime((string) $row['expires_at']) < time()) {
                $pdo->rollBack();
                return null;
            }
            $upd = $pdo->prepare('UPDATE exec_tokens SET used = TRUE WHERE token = :t');
            $upd->execute([':t' => $token]);
            $pdo->commit();
            return $row;
        } catch (\Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return null;
        }
    }

    public static function cleanup(): void
    {
        try {
            Db::pdo()->exec("DELETE FROM exec_tokens WHERE expires_at < NOW() - INTERVAL '1 hour'");
        } catch (\Throwable) {
        }
    }
}
