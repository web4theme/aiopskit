<?php
declare(strict_types=1);

namespace AIOpsKit\Apps;

use AIOpsKit\Cli;
use AIOpsKit\Config;
use AIOpsKit\Db;
use AIOpsKit\Http;
use AIOpsKit\Util;

final class McpGateway
{
    private const VERSION = '1.0.0';

    public static function cli(array $args): int
    {
        $cmd = $args[0] ?? 'help';
        if ($cmd === 'serve') {
            $options = Cli::options(array_slice($args, 1));
            $port = max(1024, min(65535, (int) ($options['port'] ?? 8787)));
            $router = AIOPSKIT_ROOT . '/public/gateway.php';
            Cli::out('MCP Gateway listening on http://127.0.0.1:' . $port . '/mcp');
            $command = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg($router);
            passthru($command, $code);
            return (int) $code;
        }
        if ($cmd === 'check') {
            $config = self::config();
            Cli::out('servers: ' . implode(', ', array_keys($config['servers'] ?? [])));
            Cli::out('tokens: ' . count($config['tokens'] ?? []));
            return 0;
        }
        Cli::out("Usage: aiopskit gateway <serve [--port=8787]|check>\n\n" .
            "Configure config/gateway.php with servers (command or url) and tokens.\n" .
            "Clients POST JSON-RPC to http://127.0.0.1:8787/mcp with Authorization: Bearer <token>.");
        return 0;
    }

    public static function config(): array
    {
        $path = Config::env('AIOPSKIT_GATEWAY', AIOPSKIT_ROOT . '/config/gateway.php');
        $config = is_file($path) ? (require $path) : [];
        return is_array($config) ? $config + ['servers' => [], 'tokens' => []] : ['servers' => [], 'tokens' => []];
    }

    /**
     * @return array{status:int,body:array}
     */
    public static function handle(string $authorization, string $body): array
    {
        $config = self::config();
        $token = self::bearer($authorization);
        if ($token === '' || !isset($config['tokens'][$token])) {
            return ['status' => 401, 'body' => ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001, 'message' => 'Unauthorized']]];
        }
        $allow = (array) ($config['tokens'][$token]['allow'] ?? []);
        $message = json_decode($body, true);
        if (!is_array($message)) {
            return ['status' => 400, 'body' => ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]];
        }
        $id = $message['id'] ?? null;
        $method = (string) ($message['method'] ?? '');
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        $started = microtime(true);

        if ($method === 'initialize') {
            return ['status' => 200, 'body' => self::ok($id, [
                'protocolVersion' => '2024-11-05',
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'aiopskit-gateway', 'version' => self::VERSION],
            ])];
        }
        if ($method === 'ping') {
            return ['status' => 200, 'body' => self::ok($id, ['pong' => true])];
        }
        if ($method === 'tools/list') {
            $tools = [];
            foreach (array_keys((array) $config['servers']) as $server) {
                if (!self::serverVisible($allow, $server)) {
                    continue;
                }
                foreach (self::backendTools($server, $config['servers'][$server]) as $tool) {
                    $full = $server . '.' . (string) ($tool['name'] ?? '');
                    if (!self::allowed($allow, $full) && !self::allowed($allow, $server . '.*') && !self::allowed($allow, '*')) {
                        continue;
                    }
                    if (isset($tool['inputSchema']['properties']) && $tool['inputSchema']['properties'] === []) {
                        $tool['inputSchema']['properties'] = new \stdClass();
                    }
                    $tool['name'] = $full;
                    $tools[] = $tool;
                }
            }
            return ['status' => 200, 'body' => self::ok($id, ['tools' => $tools])];
        }
        if ($method === 'tools/call') {
            $full = (string) ($params['name'] ?? '');
            if (!str_contains($full, '.')) {
                return ['status' => 200, 'body' => self::ok($id, ['content' => [['type' => 'text', 'text' => 'Tool name must be server.tool']], 'isError' => true])];
            }
            [$server, $tool] = explode('.', $full, 2);
            if (!isset($config['servers'][$server]) || (!self::allowed($allow, $full) && !self::allowed($allow, $server . '.*') && !self::allowed($allow, '*'))) {
                self::audit($token, $full, false, $started);
                return ['status' => 200, 'body' => self::ok($id, ['content' => [['type' => 'text', 'text' => 'Forbidden tool: ' . $full]], 'isError' => true])];
            }
            try {
                $result = self::backendCall($server, $config['servers'][$server], [
                    'jsonrpc' => '2.0',
                    'id' => 2,
                    'method' => 'tools/call',
                    'params' => ['name' => $tool, 'arguments' => (array) ($params['arguments'] ?? [])],
                ]);
                self::audit($token, $full, empty($result['isError']), $started);
                return ['status' => 200, 'body' => self::ok($id, $result)];
            } catch (\Throwable $e) {
                self::audit($token, $full, false, $started);
                return ['status' => 200, 'body' => self::ok($id, ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true])];
            }
        }
        return ['status' => 200, 'body' => ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found']]];
    }

    private static function bearer(string $header): string
    {
        if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
            return trim($m[1]);
        }
        return '';
    }

    private static function serverVisible(array $allow, string $server): bool
    {
        if (self::allowed($allow, '*') || self::allowed($allow, $server . '.*')) {
            return true;
        }
        foreach ($allow as $pattern) {
            if (str_starts_with($pattern, $server . '.')) {
                return true;
            }
        }
        return false;
    }

    private static function allowed(array $allow, string $needle): bool
    {
        foreach ($allow as $pattern) {
            if ($pattern === '*' || $pattern === $needle) {
                return true;
            }
            if (str_ends_with($pattern, '.*') && str_starts_with($needle, substr($pattern, 0, -1))) {
                return true;
            }
        }
        return false;
    }

    private static function backendTools(string $server, array $config): array
    {
        try {
            $result = self::backendCall($server, $config, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => [],
            ], true);
            return (array) ($result['tools'] ?? []);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function backendCall(string $server, array $config, array $request, bool $listOnly = false): array
    {
        if (isset($config['url'])) {
            $response = Http::postJson((string) $config['url'], $request, [
                'timeout' => (int) ($config['timeout'] ?? 20),
                'headers' => array_merge(
                    ['Content-Type: application/json'],
                    isset($config['token']) ? ['Authorization: Bearer ' . $config['token']] : []
                ),
            ]);
            $data = Http::decode($response);
            if (!is_array($data)) {
                throw new \RuntimeException('Backend returned non-JSON (HTTP ' . $response['status'] . ').');
            }
            return $listOnly ? (array) ($data['result'] ?? []) : (array) ($data['result'] ?? []);
        }
        $command = array_merge([(string) ($config['command'] ?? 'php')], array_map('strval', (array) ($config['args'] ?? [])));
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, (string) ($config['cwd'] ?? AIOPSKIT_ROOT));
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot start backend: ' . $server);
        }
        $payload = Util::json([
            'jsonrpc' => '2.0',
            'id' => 0,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2024-11-05', 'capabilities' => new \stdClass()],
        ]) . "\n" . Util::json($request) . "\n";
        fwrite($pipes[0], $payload);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $started = microtime(true);
        $timeout = (int) ($config['timeout'] ?? 20);
        while (microtime(true) - $started < $timeout) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            if (str_contains($stdout, '"id":' . $request['id']) && substr_count($stdout, "\n") >= 2) {
                break;
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            usleep(20000);
        }
        proc_terminate($process, 9);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $stdout))));
        foreach (array_reverse($lines) as $line) {
            $data = json_decode($line, true);
            if (is_array($data) && ($data['id'] ?? null) === $request['id']) {
                if (isset($data['error'])) {
                    throw new \RuntimeException((string) ($data['error']['message'] ?? 'Backend error'));
                }
                return (array) ($data['result'] ?? []);
            }
        }
        throw new \RuntimeException('Backend did not answer: ' . $server);
    }

    private static function audit(string $token, string $tool, bool $ok, float $started): void
    {
        $pdo = Db::open(Config::storage('gateway.sqlite'));
        Db::migrate($pdo, [
            'CREATE TABLE IF NOT EXISTS audit (id INTEGER PRIMARY KEY AUTOINCREMENT, ts TEXT NOT NULL, token_hash TEXT NOT NULL, tool TEXT NOT NULL, ok INTEGER NOT NULL, ms INTEGER NOT NULL)',
        ]);
        $stmt = $pdo->prepare('INSERT INTO audit (ts, token_hash, tool, ok, ms) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([Util::now(), substr(hash('sha256', $token), 0, 16), $tool, $ok ? 1 : 0, (int) round((microtime(true) - $started) * 1000)]);
    }

    private static function ok(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }
}
