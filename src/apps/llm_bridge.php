<?php
declare(strict_types=1);

namespace AIOpsKit\Apps;

use AIOpsKit\Cli;
use AIOpsKit\Config;
use AIOpsKit\Db;
use AIOpsKit\Http;
use AIOpsKit\Util;

final class LlmBridge
{
    public static function config(): array
    {
        $path = Config::env('AIOPSKIT_BRIDGE', AIOPSKIT_ROOT . '/config/bridge.php');
        $config = is_file($path) ? (require $path) : [];
        return is_array($config) ? $config + ['providers' => []] : ['providers' => []];
    }

    public static function pdo(?string $path = null): \PDO
    {
        $pdo = Db::open($path ?? Config::storage('bridge.sqlite'));
        Db::migrate($pdo, [
            'CREATE TABLE IF NOT EXISTS requests (id INTEGER PRIMARY KEY AUTOINCREMENT, ts TEXT NOT NULL, provider TEXT NOT NULL, model TEXT NOT NULL, status INTEGER NOT NULL, ok INTEGER NOT NULL, ms INTEGER NOT NULL, error TEXT NOT NULL DEFAULT "")',
        ]);
        return $pdo;
    }

    /**
     * @return array{ok:bool,provider?:string,status?:int,body?:string,error?:string,attempts:array<int,array{provider:string,status:int,error:?string}>}
     */
    public static function forward(array $payload): array
    {
        $config = self::config();
        $providers = array_values((array) ($config['providers'] ?? []));
        if ($providers === []) {
            return ['ok' => false, 'error' => 'No providers configured in config/bridge.php', 'attempts' => []];
        }
        $requested = (string) ($payload['model'] ?? '');
        $attempts = [];
        foreach ($providers as $provider) {
            $name = (string) ($provider['name'] ?? 'provider');
            $modelMap = (array) ($provider['models'] ?? []);
            if ($modelMap !== [] && $requested !== '' && !isset($modelMap[$requested])) {
                continue;
            }
            $model = $modelMap[$requested] ?? ($requested !== '' ? $requested : (string) ($provider['default_model'] ?? ''));
            $body = $payload;
            if ($model !== '') {
                $body['model'] = $model;
            }
            $started = microtime(true);
            $response = Http::postJson(rtrim((string) $provider['base_url'], '/') . '/chat/completions', $body, [
                'timeout' => (int) ($provider['timeout'] ?? 60),
                'allow_private' => (bool) ($provider['allow_private'] ?? false),
                'headers' => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . (string) ($provider['api_key'] ?? ''),
                ],
            ]);
            $ms = (int) round((microtime(true) - $started) * 1000);
            $decoded = Http::decode($response);
            $ok = $response['error'] === null && $response['status'] >= 200 && $response['status'] < 300 && is_array($decoded) && isset($decoded['choices']);
            self::log($name, $model, $response['status'], $ok, $ms, $ok ? '' : (string) ($response['error'] ?? 'HTTP ' . $response['status']));
            $attempts[] = ['provider' => $name, 'status' => $response['status'], 'error' => $response['error']];
            if ($ok) {
                return ['ok' => true, 'provider' => $name, 'status' => $response['status'], 'body' => $response['body'], 'attempts' => $attempts];
            }
        }
        return ['ok' => false, 'error' => 'All providers failed', 'attempts' => $attempts];
    }

    public static function cli(array $args): int
    {
        $cmd = $args[0] ?? 'help';
        $options = Cli::options(array_slice($args, 1));
        if ($cmd === 'providers') {
            foreach ((array) (self::config()['providers'] ?? []) as $provider) {
                Cli::out(str_pad((string) ($provider['name'] ?? '?'), 18) . ' ' . ($provider['base_url'] ?? '') . '  key=' . Util::mask((string) ($provider['api_key'] ?? '')));
            }
            return 0;
        }
        if ($cmd === 'test') {
            $result = self::forward([
                'model' => (string) ($options['model'] ?? ''),
                'messages' => [['role' => 'user', 'content' => (string) ($options['prompt'] ?? 'Say OK')]],
                'max_tokens' => 16,
            ]);
            Cli::out(Util::json($result, JSON_PRETTY_PRINT));
            return $result['ok'] ? 0 : 1;
        }
        if ($cmd === 'log') {
            $stmt = self::pdo()->query('SELECT ts, provider, model, status, ok, ms FROM requests ORDER BY id DESC LIMIT 20');
            Cli::out(Cli::table($stmt->fetchAll()));
            return 0;
        }
        if ($cmd === 'serve') {
            $port = max(1024, min(65535, (int) ($options['port'] ?? 8790)));
            Cli::out('LLM Bridge on http://127.0.0.1:' . $port . '/v1/chat/completions');
            passthru(escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg(AIOPSKIT_ROOT . '/public/bridge.php'), $code);
            return (int) $code;
        }
        Cli::out("Usage: aiopskit bridge <providers|test|log|serve>\n\n" .
            "  providers\n  test --prompt='Say OK' [--model=gpt-4o]\n  log\n  serve --port=8790");
        return 0;
    }

    private static function log(string $provider, string $model, int $status, bool $ok, int $ms, string $error): void
    {
        $stmt = self::pdo()->prepare('INSERT INTO requests (ts, provider, model, status, ok, ms, error) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([Util::now(), $provider, $model, $status, $ok ? 1 : 0, $ms, Util::truncate($error, 300)]);
    }
}
