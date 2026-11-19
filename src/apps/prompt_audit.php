<?php
declare(strict_types=1);

namespace AIOpsKit\Apps;

use AIOpsKit\Cli;
use AIOpsKit\Config;
use AIOpsKit\Db;
use AIOpsKit\Util;

final class PromptAudit
{
    private const SCHEMA = [
        'CREATE TABLE IF NOT EXISTS prompts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ts TEXT NOT NULL,
            project TEXT NOT NULL,
            model TEXT NOT NULL DEFAULT "",
            prompt TEXT NOT NULL,
            response TEXT NOT NULL DEFAULT "",
            redactions TEXT NOT NULL DEFAULT ""
        )',
        'CREATE INDEX IF NOT EXISTS prompts_ts ON prompts (ts)',
        'CREATE INDEX IF NOT EXISTS prompts_project ON prompts (project, ts)',
    ];

    /** @var array<string,string> */
    private const PATTERNS = [
        'email' => '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
        'phone_cn' => '/(?<!\d)1[3-9]\d{9}(?!\d)/',
        'phone_intl' => '/(?<!\d)\+\d{8,15}(?!\d)/',
        'id_cn' => '/(?<!\d)\d{17}[\dXx](?!\d)/',
        'api_key' => '/\b(?:sk-[A-Za-z0-9_\-]{16,}|AKIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9]{20,}|xox[baprs]-[A-Za-z0-9\-]{10,})\b/',
        'card' => '/(?<!\d)(?:\d[ -]?){13,19}(?!\d)/',
    ];

    public static function pdo(?string $path = null): \PDO
    {
        $pdo = Db::open($path ?? Config::storage('prompt-audit.sqlite'));
        Db::migrate($pdo, self::SCHEMA);
        return $pdo;
    }

    /**
     * @return array{text:string,counts:array<string,int>}
     */
    public static function redact(string $text): array
    {
        $counts = [];
        foreach (self::PATTERNS as $name => $pattern) {
            if ($name === 'card') {
                $count = 0;
                $text = (string) preg_replace_callback($pattern, static function (array $m) use (&$count): string {
                    $digits = preg_replace('/\D/', '', $m[0]) ?? '';
                    if (strlen($digits) < 13 || strlen($digits) > 19) {
                        return $m[0];
                    }
                    if (!self::luhn($digits)) {
                        return $m[0];
                    }
                    $count++;
                    return '[REDACTED:card]';
                }, $text);
                if ($count > 0) {
                    $counts['card'] = $count;
                }
                continue;
            }
            $count = 0;
            $text = (string) preg_replace_callback($pattern, static function () use (&$count, $name): string {
                $count++;
                return '[REDACTED:' . $name . ']';
            }, $text);
            if ($count > 0) {
                $counts[$name] = $count;
            }
        }
        return ['text' => $text, 'counts' => $counts];
    }

    private static function luhn(string $digits): bool
    {
        $sum = 0;
        $alternate = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($alternate) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alternate = !$alternate;
        }
        return $sum % 10 === 0;
    }

    public static function record(string $project, string $prompt, string $response = '', string $model = '', ?string $path = null): array
    {
        $promptResult = self::redact($prompt);
        $responseResult = self::redact($response);
        $counts = $promptResult['counts'];
        foreach ($responseResult['counts'] as $key => $value) {
            $counts[$key] = ($counts[$key] ?? 0) + $value;
        }
        $pdo = self::pdo($path);
        $stmt = $pdo->prepare('INSERT INTO prompts (ts, project, model, prompt, response, redactions) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            Util::now(),
            $project,
            $model,
            $promptResult['text'],
            $responseResult['text'],
            Util::json($counts),
        ]);
        return ['id' => (int) $pdo->lastInsertId(), 'counts' => $counts];
    }

    public static function search(string $term = '', int $limit = 50, ?string $project = null, ?string $path = null): array
    {
        $pdo = self::pdo($path);
        $sql = 'SELECT id, ts, project, model, prompt, response, redactions FROM prompts WHERE 1=1';
        $params = [];
        if ($term !== '') {
            $sql .= ' AND (prompt LIKE ? OR response LIKE ?)';
            $params[] = '%' . $term . '%';
            $params[] = '%' . $term . '%';
        }
        if ($project !== null && $project !== '') {
            $sql .= ' AND project = ?';
            $params[] = $project;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(500, $limit));
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function prune(int $days, ?string $path = null): int
    {
        $pdo = self::pdo($path);
        $cutoff = gmdate('Y-m-d H:i:s', time() - max(1, $days) * 86400);
        $stmt = $pdo->prepare('DELETE FROM prompts WHERE ts < ?');
        $stmt->execute([$cutoff]);
        return $stmt->rowCount();
    }

    public static function exportJsonl(int $limit = 1000, ?string $path = null): string
    {
        $rows = self::search('', $limit, null, $path);
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = Util::json($row);
        }
        return implode("\n", $lines) . ($lines === [] ? '' : "\n");
    }

    public static function cli(array $args): int
    {
        $cmd = $args[0] ?? 'help';
        $options = Cli::options(array_slice($args, 1));
        if ($cmd === 'record') {
            $prompt = (string) ($options['prompt'] ?? '');
            if ($prompt === '' && isset($options['prompt_file'])) {
                $prompt = (string) file_get_contents((string) $options['prompt_file']);
            }
            $response = (string) ($options['response'] ?? '');
            if ($response === '' && isset($options['response_file'])) {
                $response = (string) file_get_contents((string) $options['response_file']);
            }
            if ($prompt === '') {
                Cli::err('Provide --prompt or --prompt_file.');
                return 2;
            }
            $result = self::record((string) ($options['project'] ?? 'default'), $prompt, $response, (string) ($options['model'] ?? ''));
            Cli::out('recorded #' . $result['id'] . ' redactions=' . Util::json($result['counts']));
            return 0;
        }
        if ($cmd === 'redact') {
            $text = (string) ($options['text'] ?? '');
            Cli::out(Util::json(self::redact($text), JSON_PRETTY_PRINT));
            return 0;
        }
        if ($cmd === 'search') {
            $rows = self::search((string) ($options['q'] ?? ''), (int) ($options['limit'] ?? 20), isset($options['project']) ? (string) $options['project'] : null);
            Cli::out(Util::json($rows, JSON_PRETTY_PRINT));
            return 0;
        }
        if ($cmd === 'prune') {
            Cli::out('deleted: ' . self::prune((int) ($options['days'] ?? 30)));
            return 0;
        }
        if ($cmd === 'export') {
            $out = (string) ($options['out'] ?? 'audit.jsonl');
            Util::atomicWrite($out, self::exportJsonl((int) ($options['limit'] ?? 1000)));
            Cli::out('written: ' . $out);
            return 0;
        }
        Cli::out("Usage: aiopskit audit <record|redact|search|prune|export>\n\n" .
            "  record --project=acme --prompt='...' [--response='...'] [--model=gpt]\n" .
            "  redact --text='mail me at a@b.com'\n" .
            "  search --q=refund --limit=20\n" .
            "  prune --days=30\n" .
            "  export --out=audit.jsonl");
        return 0;
    }

    public static function web(array $query): array
    {
        return [
            'rows' => self::search((string) ($query['q'] ?? ''), 50, isset($query['project']) ? (string) $query['project'] : null),
            'q' => (string) ($query['q'] ?? ''),
        ];
    }
}
