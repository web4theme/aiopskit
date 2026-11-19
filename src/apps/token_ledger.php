<?php
declare(strict_types=1);

namespace AIOpsKit\Apps;

use AIOpsKit\Cli;
use AIOpsKit\Config;
use AIOpsKit\Db;
use AIOpsKit\Util;

final class TokenLedger
{
    private const SCHEMA = [
        'CREATE TABLE IF NOT EXISTS calls (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ts TEXT NOT NULL,
            project TEXT NOT NULL,
            provider TEXT NOT NULL DEFAULT "",
            model TEXT NOT NULL,
            prompt_tokens INTEGER NOT NULL DEFAULT 0,
            completion_tokens INTEGER NOT NULL DEFAULT 0,
            cost_micros INTEGER NOT NULL DEFAULT 0,
            latency_ms INTEGER NOT NULL DEFAULT 0,
            key_id TEXT NOT NULL DEFAULT "",
            meta TEXT NOT NULL DEFAULT ""
        )',
        'CREATE INDEX IF NOT EXISTS calls_ts ON calls (ts)',
        'CREATE INDEX IF NOT EXISTS calls_project ON calls (project, ts)',
        'CREATE TABLE IF NOT EXISTS budgets (
            project TEXT PRIMARY KEY,
            monthly_micros INTEGER NOT NULL DEFAULT 0,
            alert_percent INTEGER NOT NULL DEFAULT 80
        )',
    ];

    public static function pdo(?string $path = null): \PDO
    {
        $pdo = Db::open($path ?? Config::storage('ledger.sqlite'));
        Db::migrate($pdo, self::SCHEMA);
        return $pdo;
    }

    public static function record(array $row, ?string $path = null): int
    {
        $pdo = self::pdo($path);
        $stmt = $pdo->prepare('INSERT INTO calls (ts, project, provider, model, prompt_tokens, completion_tokens, cost_micros, latency_ms, key_id, meta)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            (string) ($row['ts'] ?? Util::now()),
            (string) ($row['project'] ?? 'default'),
            (string) ($row['provider'] ?? ''),
            (string) ($row['model'] ?? 'unknown'),
            max(0, (int) ($row['prompt_tokens'] ?? 0)),
            max(0, (int) ($row['completion_tokens'] ?? 0)),
            max(0, (int) round(((float) ($row['cost_usd'] ?? 0)) * 1000000)),
            max(0, (int) ($row['latency_ms'] ?? 0)),
            (string) ($row['key_id'] ?? ''),
            Util::truncate((string) ($row['meta'] ?? ''), 500),
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function summary(int $days = 30, ?string $project = null, ?string $path = null): array
    {
        $pdo = self::pdo($path);
        $since = gmdate('Y-m-d 00:00:00', time() - max(0, $days - 1) * 86400);
        $sql = 'SELECT project, model, COUNT(*) AS calls, SUM(prompt_tokens) AS prompt_tokens,
                SUM(completion_tokens) AS completion_tokens, SUM(cost_micros) AS cost_micros,
                AVG(latency_ms) AS avg_latency
                FROM calls WHERE ts >= ?';
        $params = [$since];
        if ($project !== null && $project !== '') {
            $sql .= ' AND project = ?';
            $params[] = $project;
        }
        $sql .= ' GROUP BY project, model ORDER BY cost_micros DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $total = 0;
        foreach ($rows as &$row) {
            $row['cost_micros'] = (int) $row['cost_micros'];
            $row['calls'] = (int) $row['calls'];
            $row['avg_latency'] = (int) round((float) $row['avg_latency']);
            $total += $row['cost_micros'];
        }
        return ['days' => $days, 'since' => $since, 'rows' => $rows, 'total_micros' => $total];
    }

    public static function daily(int $days = 14, ?string $path = null): array
    {
        $pdo = self::pdo($path);
        $since = gmdate('Y-m-d 00:00:00', time() - max(0, $days - 1) * 86400);
        $stmt = $pdo->prepare('SELECT substr(ts, 1, 10) AS day, SUM(cost_micros) AS micros, COUNT(*) AS calls
            FROM calls WHERE ts >= ? GROUP BY day ORDER BY day');
        $stmt->execute([$since]);
        return $stmt->fetchAll();
    }

    public static function setBudget(string $project, float $usd, int $alert = 80, ?string $path = null): void
    {
        $pdo = self::pdo($path);
        $stmt = $pdo->prepare('INSERT INTO budgets (project, monthly_micros, alert_percent) VALUES (?, ?, ?)
            ON CONFLICT(project) DO UPDATE SET monthly_micros = excluded.monthly_micros, alert_percent = excluded.alert_percent');
        $stmt->execute([$project, max(0, (int) round($usd * 1000000)), max(1, min(100, $alert))]);
    }

    public static function budgetStatus(string $project, ?string $path = null): array
    {
        $pdo = self::pdo($path);
        $stmt = $pdo->prepare('SELECT monthly_micros, alert_percent FROM budgets WHERE project = ?');
        $stmt->execute([$project]);
        $budget = $stmt->fetch();
        if (!$budget) {
            return ['project' => $project, 'budget_micros' => 0, 'spent_micros' => 0, 'percent' => 0, 'status' => 'unset'];
        }
        $monthStart = gmdate('Y-m-01 00:00:00');
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(cost_micros), 0) FROM calls WHERE project = ? AND ts >= ?');
        $stmt->execute([$project, $monthStart]);
        $spent = (int) $stmt->fetchColumn();
        $budgetMicros = (int) $budget['monthly_micros'];
        $percent = $budgetMicros > 0 ? (int) round($spent * 100 / $budgetMicros) : 0;
        $status = $percent >= 100 ? 'over' : ($percent >= (int) $budget['alert_percent'] ? 'alert' : 'ok');
        return [
            'project' => $project,
            'budget_micros' => $budgetMicros,
            'spent_micros' => $spent,
            'percent' => $percent,
            'status' => $status,
        ];
    }

    public static function exportCsv(int $days = 30, ?string $path = null): string
    {
        $pdo = self::pdo($path);
        $since = gmdate('Y-m-d 00:00:00', time() - max(0, $days - 1) * 86400);
        $stmt = $pdo->prepare('SELECT ts, project, provider, model, prompt_tokens, completion_tokens, cost_micros, latency_ms, key_id FROM calls WHERE ts >= ? ORDER BY id');
        $stmt->execute([$since]);
        $lines = [Util::csvRow(['ts', 'project', 'provider', 'model', 'prompt_tokens', 'completion_tokens', 'cost_usd', 'latency_ms', 'key_id'])];
        foreach ($stmt->fetchAll() as $row) {
            $row['cost_usd'] = number_format(((int) $row['cost_micros']) / 1000000, 6, '.', '');
            unset($row['cost_micros']);
            $lines[] = Util::csvRow($row);
        }
        return implode("\n", $lines) . "\n";
    }

    public static function cli(array $args): int
    {
        $cmd = $args[0] ?? 'help';
        $options = Cli::options(array_slice($args, 1));
        if ($cmd === 'record') {
            $id = self::record([
                'project' => (string) ($options['project'] ?? 'default'),
                'provider' => (string) ($options['provider'] ?? ''),
                'model' => (string) ($options['model'] ?? 'unknown'),
                'prompt_tokens' => (int) ($options['in'] ?? 0),
                'completion_tokens' => (int) ($options['out'] ?? 0),
                'cost_usd' => (float) ($options['cost'] ?? 0),
                'latency_ms' => (int) ($options['latency'] ?? 0),
                'key_id' => (string) ($options['key'] ?? ''),
            ]);
            Cli::out('recorded #' . $id);
            return 0;
        }
        if ($cmd === 'report') {
            $summary = self::summary((int) ($options['days'] ?? 30), isset($options['project']) ? (string) $options['project'] : null);
            $rows = array_map(static fn ($r) => [
                'project' => $r['project'],
                'model' => $r['model'],
                'calls' => $r['calls'],
                'tokens' => (int) $r['prompt_tokens'] + (int) $r['completion_tokens'],
                'cost_usd' => number_format($r['cost_micros'] / 1000000, 4, '.', ''),
                'avg_ms' => $r['avg_latency'],
            ], $summary['rows']);
            Cli::out(Cli::table($rows));
            Cli::out('total: $' . number_format($summary['total_micros'] / 1000000, 4, '.', ''));
            return 0;
        }
        if ($cmd === 'budget') {
            $project = (string) ($options['project'] ?? 'default');
            if (isset($options['usd'])) {
                self::setBudget($project, (float) $options['usd'], (int) ($options['alert'] ?? 80));
                Cli::out('budget set for ' . $project);
                return 0;
            }
            $status = self::budgetStatus($project);
            Cli::out(Util::json($status, JSON_PRETTY_PRINT));
            return $status['status'] === 'over' ? 1 : 0;
        }
        if ($cmd === 'export') {
            $csv = self::exportCsv((int) ($options['days'] ?? 30));
            $out = (string) ($options['out'] ?? '');
            if ($out !== '') {
                Util::atomicWrite($out, $csv);
                Cli::out('written: ' . $out);
            } else {
                Cli::out($csv);
            }
            return 0;
        }
        Cli::out("Usage: aiopskit ledger <record|report|budget|export>\n\n" .
            "  record --project=acme --model=gpt --in=1200 --out=300 --cost=0.012 --latency=840\n" .
            "  report --days=30 [--project=acme]\n" .
            "  budget --project=acme --usd=50 [--alert=80]\n" .
            "  export --days=30 --out=report.csv");
        return 0;
    }

    public static function web(array $query): array
    {
        $days = max(1, min(365, (int) ($query['days'] ?? 30)));
        $summary = self::summary($days);
        $budgets = [];
        foreach ($summary['rows'] as $row) {
            $budgets[$row['project']] = self::budgetStatus((string) $row['project']);
        }
        return ['days' => $days, 'summary' => $summary, 'daily' => self::daily(min(30, $days)), 'budgets' => $budgets];
    }
}
