<?php
declare(strict_types=1);

namespace AIOpsKit\Apps;

use AIOpsKit\Cli;
use AIOpsKit\Config;
use AIOpsKit\Db;
use AIOpsKit\Util;

final class EmbeddingCache
{
    private const SCHEMA = [
        'CREATE TABLE IF NOT EXISTS embeddings (
            cache_key TEXT PRIMARY KEY,
            model TEXT NOT NULL,
            text_hash TEXT NOT NULL,
            vector TEXT NOT NULL,
            dims INTEGER NOT NULL,
            created_at INTEGER NOT NULL,
            expires_at INTEGER NOT NULL
        )',
        'CREATE INDEX IF NOT EXISTS embeddings_model ON embeddings (model)',
        'CREATE INDEX IF NOT EXISTS embeddings_expires ON embeddings (expires_at)',
        'CREATE TABLE IF NOT EXISTS stats (
            model TEXT PRIMARY KEY,
            hits INTEGER NOT NULL DEFAULT 0,
            misses INTEGER NOT NULL DEFAULT 0
        )',
    ];

    public static function pdo(?string $path = null): \PDO
    {
        $pdo = Db::open($path ?? Config::storage('embedding-cache.sqlite'));
        Db::migrate($pdo, self::SCHEMA);
        return $pdo;
    }

    public static function key(string $model, string $text): string
    {
        return hash('sha256', $model . "\0" . $text);
    }

    public static function put(string $model, string $text, array $vector, int $ttl = 86400, ?string $path = null): void
    {
        $now = time();
        $pdo = self::pdo($path);
        $stmt = $pdo->prepare('INSERT INTO embeddings (cache_key, model, text_hash, vector, dims, created_at, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(cache_key) DO UPDATE SET vector = excluded.vector, dims = excluded.dims,
            created_at = excluded.created_at, expires_at = excluded.expires_at');
        $stmt->execute([
            self::key($model, $text),
            $model,
            hash('sha256', $text),
            Util::json(array_values($vector)),
            count($vector),
            $now,
            $now + max(60, $ttl),
        ]);
    }

    /**
     * @return array<int,array{model:string,text:string,vector:?array{0:array<int,float>,1:bool}}
     */
    public static function getMany(array $items, ?string $path = null): array
    {
        $pdo = self::pdo($path);
        $stmt = $pdo->prepare('SELECT vector, expires_at FROM embeddings WHERE cache_key = ?');
        $out = [];
        foreach ($items as $item) {
            $model = (string) ($item['model'] ?? '');
            $text = (string) ($item['text'] ?? '');
            $stmt->execute([self::key($model, $text)]);
            $row = $stmt->fetch();
            if ($row && (int) $row['expires_at'] > time()) {
                $vector = json_decode((string) $row['vector'], true);
                $out[] = ['model' => $model, 'text' => $text, 'vector' => is_array($vector) ? $vector : null];
                self::bump($model, 'hits', $path);
            } else {
                $out[] = ['model' => $model, 'text' => $text, 'vector' => null];
                self::bump($model, 'misses', $path);
            }
        }
        return $out;
    }

    public static function get(string $model, string $text, ?string $path = null): ?array
    {
        $result = self::getMany([['model' => $model, 'text' => $text]], $path)[0];
        return $result['vector'];
    }

    private static function bump(string $model, string $column, ?string $path): void
    {
        $pdo = self::pdo($path);
        $pdo->prepare('INSERT INTO stats (model, hits, misses) VALUES (?, 0, 0) ON CONFLICT(model) DO NOTHING')->execute([$model]);
        $pdo->prepare('UPDATE stats SET ' . ($column === 'hits' ? 'hits' : 'misses') . ' = ' . ($column === 'hits' ? 'hits' : 'misses') . ' + 1 WHERE model = ?')->execute([$model]);
    }

    public static function stats(?string $path = null): array
    {
        $pdo = self::pdo($path);
        $rows = $pdo->query('SELECT model, hits, misses FROM stats ORDER BY model')->fetchAll();
        $totalHits = 0;
        $totalMisses = 0;
        foreach ($rows as &$row) {
            $row['hits'] = (int) $row['hits'];
            $row['misses'] = (int) $row['misses'];
            $totalHits += $row['hits'];
            $totalMisses += $row['misses'];
        }
        $total = $totalHits + $totalMisses;
        return [
            'rows' => $rows,
            'hits' => $totalHits,
            'misses' => $totalMisses,
            'hit_rate' => $total > 0 ? round($totalHits * 100 / $total, 1) : 0.0,
            'entries' => (int) $pdo->query('SELECT COUNT(*) FROM embeddings')->fetchColumn(),
        ];
    }

    public static function prune(?string $path = null): int
    {
        $stmt = self::pdo($path)->prepare('DELETE FROM embeddings WHERE expires_at <= ?');
        $stmt->execute([time()]);
        return $stmt->rowCount();
    }

    public static function cli(array $args): int
    {
        $cmd = $args[0] ?? 'help';
        $options = Cli::options(array_slice($args, 1));
        if ($cmd === 'put') {
            $vector = json_decode((string) ($options['vector'] ?? '[]'), true);
            if (!is_array($vector)) {
                Cli::err('--vector must be a JSON array, e.g. [0.1,0.2]');
                return 2;
            }
            self::put((string) ($options['model'] ?? 'default'), (string) ($options['text'] ?? ''), $vector, (int) ($options['ttl'] ?? 86400));
            Cli::out('cached');
            return 0;
        }
        if ($cmd === 'get') {
            $vector = self::get((string) ($options['model'] ?? 'default'), (string) ($options['text'] ?? ''));
            Cli::out($vector === null ? 'miss' : Util::json($vector));
            return $vector === null ? 1 : 0;
        }
        if ($cmd === 'stats') {
            Cli::out(Util::json(self::stats(), JSON_PRETTY_PRINT));
            return 0;
        }
        if ($cmd === 'prune') {
            Cli::out('pruned: ' . self::prune());
            return 0;
        }
        Cli::out("Usage: aiopskit cache <put|get|stats|prune>\n\n" .
            "  put --model=text-embedding-3-small --text='hello' --vector='[0.1,0.2]' [--ttl=86400]\n" .
            "  get --model=... --text='hello'\n" .
            "  stats\n" .
            "  prune");
        return 0;
    }

    public static function web(array $query): array
    {
        return ['stats' => self::stats()];
    }
}
