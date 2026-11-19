<?php
declare(strict_types=1);

namespace AIOpsKit\Apps;

use AIOpsKit\Cli;
use AIOpsKit\Config;
use AIOpsKit\Util;

final class AnyPhpMcp
{
    private const VERSION = '1.0.0';
    private const MAX_OUTPUT = 65536;

    public static function cli(array $args): int
    {
        $cmd = $args[0] ?? 'help';
        if ($cmd === 'serve') {
            self::serve();
            return 0;
        }
        if ($cmd === 'list') {
            $tools = self::loadTools();
            Cli::out(Util::json(array_map(static fn ($t) => [
                'name' => $t['name'] ?? '',
                'description' => $t['description'] ?? '',
            ], $tools), JSON_PRETTY_PRINT));
            return 0;
        }
        if ($cmd === 'call') {
            $name = $args[1] ?? '';
            $options = Cli::options(array_slice($args, 2));
            $payload = json_decode((string) ($options['json'] ?? '{}'), true);
            try {
                Cli::out(Util::json(self::call($name, is_array($payload) ? $payload : []), JSON_PRETTY_PRINT));
                return 0;
            } catch (\Throwable $e) {
                Cli::err($e->getMessage());
                return 1;
            }
        }
        Cli::out("Usage: aiopskit anyphp-mcp <serve|list|call <name> [--json='{}']>\n\n" .
            "Tools are read from config/mcp-tools.php. Each tool needs: name, command, args.");
        return 0;
    }

    public static function loadTools(): array
    {
        $path = Config::env('AIOPSKIT_TOOLS', AIOPSKIT_ROOT . '/config/mcp-tools.php');
        if (!is_file($path)) {
            return [];
        }
        $tools = require $path;
        return is_array($tools) ? array_values(array_filter($tools, static fn ($t) => is_array($t) && isset($t['name'], $t['command']))) : [];
    }

    public static function mcpTools(): array
    {
        $out = [];
        foreach (self::loadTools() as $tool) {
            $schema = $tool['inputSchema'] ?? ['type' => 'object', 'properties' => []];
            if (is_array($schema) && isset($schema['properties']) && $schema['properties'] === []) {
                $schema['properties'] = new \stdClass();
            }
            $out[] = [
                'name' => (string) $tool['name'],
                'description' => (string) ($tool['description'] ?? ''),
                'inputSchema' => $schema,
            ];
        }
        return $out;
    }

    public static function call(string $name, array $args): array
    {
        $tools = self::loadTools();
        $tool = null;
        foreach ($tools as $candidate) {
            if ($candidate['name'] === $name) {
                $tool = $candidate;
                break;
            }
        }
        if ($tool === null) {
            throw new \RuntimeException('Unknown tool: ' . $name);
        }
        $command = $tool['command'];
        $commandArgs = array_map('strval', (array) ($tool['args'] ?? []));
        $timeout = max(1, min(120, (int) ($tool['timeout'] ?? 15)));
        $cwd = (string) ($tool['cwd'] ?? AIOPSKIT_ROOT);
        if (!is_dir($cwd)) {
            throw new \RuntimeException('Tool working directory does not exist: ' . $cwd);
        }
        $env = [];
        foreach ((array) ($tool['env'] ?? []) as $key => $value) {
            if (preg_match('/^[A-Z_][A-Z0-9_]*$/', (string) $key)) {
                $env[$key] = (string) $value;
            }
        }
        $env['AIOPSKIT_TOOL_ARGS'] = Util::json($args);
        if (!isset($env['PATH']) && getenv('PATH') !== false) {
            $env['PATH'] = (string) getenv('PATH');
        }
        if (!isset($env['HOME']) && getenv('HOME') !== false) {
            $env['HOME'] = (string) getenv('HOME');
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(array_merge([$command], $commandArgs), $descriptors, $pipes, $cwd, $env ?: null);
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot start tool process.');
        }
        fwrite($pipes[0], Util::json($args));
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $started = microtime(true);
        $timedOut = false;
        while (true) {
            $status = proc_get_status($process);
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) - $started > $timeout) {
                $timedOut = true;
                proc_terminate($process, 9);
                break;
            }
            usleep(20000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($timedOut) {
            throw new \RuntimeException('Tool timed out after ' . $timeout . 's.');
        }
        if ($exit !== 0) {
            throw new \RuntimeException('Tool exited with code ' . $exit . ': ' . Util::truncate($stderr !== '' ? $stderr : $stdout, 400));
        }
        return [
            'stdout' => substr($stdout, 0, self::MAX_OUTPUT),
            'stderr' => substr($stderr, 0, self::MAX_OUTPUT),
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    public static function serve(): void
    {
        while (($line = fgets(STDIN)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $message = json_decode($line, true);
            if (!is_array($message)) {
                self::write(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]);
                continue;
            }
            $response = self::handle($message);
            if ($response !== null) {
                self::write($response);
            }
        }
    }

    public static function handle(array $message): ?array
    {
        $id = $message['id'] ?? null;
        $method = (string) ($message['method'] ?? '');
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        if (str_starts_with($method, 'notifications/')) {
            return null;
        }
        if ($method === 'initialize') {
            return self::ok($id, [
                'protocolVersion' => '2024-11-05',
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'aiopskit-anyphp-mcp', 'version' => self::VERSION],
            ]);
        }
        if ($method === 'ping') {
            return self::ok($id, ['pong' => true]);
        }
        if ($method === 'tools/list') {
            return self::ok($id, ['tools' => self::mcpTools()]);
        }
        if ($method === 'tools/call') {
            try {
                $result = self::call((string) ($params['name'] ?? ''), (array) ($params['arguments'] ?? []));
                return self::ok($id, [
                    'content' => [['type' => 'text', 'text' => $result['stdout'] !== '' ? $result['stdout'] : '(no output)']],
                    'isError' => false,
                ]);
            } catch (\Throwable $e) {
                return self::ok($id, ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true]);
            }
        }
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found']];
    }

    private static function ok(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private static function write(array $payload): void
    {
        fwrite(STDOUT, Util::json($payload) . "\n");
        fflush(STDOUT);
    }
}
