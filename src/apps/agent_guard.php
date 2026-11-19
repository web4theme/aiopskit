<?php
declare(strict_types=1);

namespace AIOpsKit\Apps;

use AIOpsKit\Cli;
use AIOpsKit\Config;
use AIOpsKit\Db;
use AIOpsKit\Util;

final class AgentGuard
{
    private const SCHEMA = [
        'CREATE TABLE IF NOT EXISTS requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ts TEXT NOT NULL,
            action TEXT NOT NULL,
            payload TEXT NOT NULL,
            status TEXT NOT NULL,
            token_hash TEXT NOT NULL,
            expires_at INTEGER NOT NULL,
            actor TEXT NOT NULL DEFAULT "",
            executed_at TEXT NOT NULL DEFAULT "",
            result TEXT NOT NULL DEFAULT ""
        )',
        'CREATE INDEX IF NOT EXISTS requests_status ON requests (status, expires_at)',
    ];

    public static function pdo(?string $path = null): \PDO
    {
        $pdo = Db::open($path ?? Config::storage('agent-guard.sqlite'));
        Db::migrate($pdo, self::SCHEMA);
        return $pdo;
    }

    /**
     * @return array{id:int,token:string,expires_at:int}
     */
    public static function request(string $action, array $payload, int $ttl = 900, ?string $path = null): array
    {
        $token = bin2hex(random_bytes(16));
        $expires = time() + max(60, min(86400, $ttl));
        $pdo = self::pdo($path);
        $stmt = $pdo->prepare('INSERT INTO requests (ts, action, payload, status, token_hash, expires_at) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            Util::now(),
            $action,
            Util::json($payload),
            'pending',
            hash('sha256', $token),
            $expires,
        ]);
        return ['id' => (int) $pdo->lastInsertId(), 'token' => $token, 'expires_at' => $expires];
    }

    public static function approve(int $id, string $actor = '', ?string $path = null): bool
    {
        return self::transition($id, 'pending', 'approved', $actor, $path);
    }

    public static function deny(int $id, string $actor = '', ?string $path = null): bool
    {
        return self::transition($id, 'pending', 'denied', $actor, $path);
    }

    private static function transition(int $id, string $from, string $to, string $actor, ?string $path): bool
    {
        $pdo = self::pdo($path);
        $stmt = $pdo->prepare('UPDATE requests SET status = ?, actor = ? WHERE id = ? AND status = ? AND expires_at > ?');
        $stmt->execute([$to, $actor, $id, $from, time()]);
        return $stmt->rowCount() === 1;
    }

    /**
     * @return array{ok:bool,error?:string,payload?:array}
     */
    public static function execute(int $id, string $token, ?string $path = null): array
    {
        $pdo = self::pdo($path);
        $stmt = $pdo->prepare('SELECT * FROM requests WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['ok' => false, 'error' => 'Request not found'];
        }
        if ($row['status'] !== 'approved') {
            return ['ok' => false, 'error' => 'Request is ' . $row['status'] . ', not approved'];
        }
        if ((int) $row['expires_at'] <= time()) {
            $pdo->prepare('UPDATE requests SET status = "expired" WHERE id = ?')->execute([$id]);
            return ['ok' => false, 'error' => 'Request expired'];
        }
        if (!hash_equals((string) $row['token_hash'], hash('sha256', $token))) {
            return ['ok' => false, 'error' => 'Invalid token'];
        }
        $update = $pdo->prepare('UPDATE requests SET status = "executed", executed_at = ? WHERE id = ? AND status = "approved"');
        $update->execute([Util::now(), $id]);
        if ($update->rowCount() !== 1) {
            return ['ok' => false, 'error' => 'Request already consumed'];
        }
        $payload = json_decode((string) $row['payload'], true);
        return ['ok' => true, 'payload' => is_array($payload) ? $payload : []];
    }

    public static function expire(?string $path = null): int
    {
        $pdo = self::pdo($path);
        $stmt = $pdo->prepare('UPDATE requests SET status = "expired" WHERE status IN ("pending", "approved") AND expires_at <= ?');
        $stmt->execute([time()]);
        return $stmt->rowCount();
    }

    public static function list(int $limit = 50, ?string $status = null, ?string $path = null): array
    {
        $sql = 'SELECT id, ts, action, status, expires_at, actor, executed_at, payload FROM requests';
        $params = [];
        if ($status !== null && $status !== '') {
            $sql .= ' WHERE status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(500, $limit));
        $stmt = self::pdo($path)->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function sign(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    public static function verify(string $payload, string $signature, string $secret): bool
    {
        return hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
    }

    public static function cli(array $args): int
    {
        $cmd = $args[0] ?? 'help';
        $options = Cli::options(array_slice($args, 1));
        if ($cmd === 'request') {
            $payload = json_decode((string) ($options['payload'] ?? '{}'), true);
            $result = self::request((string) ($options['action'] ?? 'unknown'), is_array($payload) ? $payload : [], (int) ($options['ttl'] ?? 900));
            Cli::out(Util::json($result, JSON_PRETTY_PRINT));
            return 0;
        }
        if ($cmd === 'approve' || $cmd === 'deny') {
            $id = (int) ($options['id'] ?? 0);
            $ok = $cmd === 'approve' ? self::approve($id, (string) ($options['actor'] ?? '')) : self::deny($id, (string) ($options['actor'] ?? ''));
            Cli::out($ok ? $cmd . 'd #' . $id : 'cannot ' . $cmd . ' #' . $id);
            return $ok ? 0 : 1;
        }
        if ($cmd === 'execute') {
            $result = self::execute((int) ($options['id'] ?? 0), (string) ($options['token'] ?? ''));
            Cli::out(Util::json($result, JSON_PRETTY_PRINT));
            return $result['ok'] ? 0 : 1;
        }
        if ($cmd === 'list') {
            $rows = self::list((int) ($options['limit'] ?? 20), isset($options['status']) ? (string) $options['status'] : null);
            Cli::out(Cli::table(array_map(static fn ($r) => [
                'id' => $r['id'],
                'action' => $r['action'],
                'status' => $r['status'],
                'expires' => gmdate('Y-m-d H:i:s', (int) $r['expires_at']),
                'actor' => $r['actor'],
            ], $rows)));
            return 0;
        }
        if ($cmd === 'expire') {
            Cli::out('expired: ' . self::expire());
            return 0;
        }
        if ($cmd === 'sign') {
            Cli::out(self::sign((string) ($options['payload'] ?? ''), (string) ($options['secret'] ?? '')));
            return 0;
        }
        Cli::out("Usage: aiopskit guard <request|approve|deny|execute|list|expire|sign>\n\n" .
            "  request --action=refund --payload='{\"order\":123}' --ttl=900\n" .
            "  approve --id=1 --actor=alex\n" .
            "  execute --id=1 --token=<token>\n" .
            "  list [--status=pending]\n" .
            "  sign --payload='...' --secret='...'");
        return 0;
    }

    public static function web(array $query): array
    {
        return ['rows' => self::list(50, isset($query['status']) ? (string) $query['status'] : null)];
    }
}
