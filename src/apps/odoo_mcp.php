<?php
declare(strict_types=1);

namespace AIOpsKit\Apps;

use AIOpsKit\Cli;
use AIOpsKit\Config;
use AIOpsKit\Http;
use AIOpsKit\Util;

/**
 * Minimal Odoo JSON-RPC client (no XML-RPC dependency, no Composer).
 */
final class OdooClient
{
    private ?int $uid = null;

    public function __construct(
        private string $url,
        private string $db,
        private string $user,
        private string $password
    ) {
    }

    public static function fromConfig(): self
    {
        $cfg = Config::get('odoo');
        return new self(
            rtrim((string) Config::env('ODOO_URL', (string) ($cfg['url'] ?? '')), '/'),
            (string) Config::env('ODOO_DB', (string) ($cfg['db'] ?? '')),
            (string) Config::env('ODOO_USER', (string) ($cfg['user'] ?? '')),
            (string) Config::env('ODOO_PASSWORD', (string) ($cfg['password'] ?? ''))
        );
    }

    public function configured(): bool
    {
        return $this->url !== '' && $this->db !== '' && $this->user !== '' && $this->password !== '';
    }

    public function login(): int
    {
        if ($this->uid !== null) {
            return $this->uid;
        }
        $response = $this->call('common', 'login', [$this->db, $this->user, $this->password]);
        if (!is_int($response) || $response <= 0) {
            throw new \RuntimeException('Odoo login failed: check URL, database, user and password.');
        }
        return $this->uid = $response;
    }

    public function executeKw(string $model, string $method, array $args = [], array $kwargs = []): mixed
    {
        $uid = $this->login();
        return $this->call('object', 'execute_kw', [$this->db, $uid, $this->password, $model, $method, $args, $kwargs]);
    }

    private function call(string $service, string $method, array $args): mixed
    {
        $response = Http::postJson($this->url . '/jsonrpc', [
            'jsonrpc' => '2.0',
            'method' => 'call',
            'id' => bin2hex(random_bytes(6)),
            'params' => ['service' => $service, 'method' => $method, 'args' => $args],
        ], ['timeout' => 30, 'allow_private' => (bool) Config::get('odoo.allow_private', true)]);
        if ($response['error'] !== null) {
            throw new \RuntimeException('Odoo transport error: ' . $response['error']);
        }
        $data = Http::decode($response);
        if (!is_array($data)) {
            throw new \RuntimeException('Odoo returned a non-JSON response (HTTP ' . $response['status'] . ').');
        }
        if (isset($data['error'])) {
            $error = $data['error'];
            $message = $error['data']['message'] ?? $error['message'] ?? 'Odoo error';
            throw new \RuntimeException(Util::truncate((string) $message, 400));
        }
        return $data['result'] ?? null;
    }
}

final class OdooMcp
{
    private const VERSION = '1.0.0';
    private const MAX_LIMIT = 500;
    private const MAX_FIELDS = 80;

    public static function cli(array $args): int
    {
        $cmd = $args[0] ?? 'help';
        if ($cmd === 'serve') {
            self::serve();
            return 0;
        }
        if ($cmd === 'check') {
            $client = OdooClient::fromConfig();
            if (!$client->configured()) {
                Cli::err('Odoo is not configured. Set ODOO_URL/ODOO_DB/ODOO_USER/ODOO_PASSWORD or config/config.php.');
                return 2;
            }
            try {
                $uid = $client->login();
                Cli::out('Connected. uid=' . $uid);
                return 0;
            } catch (\Throwable $e) {
                Cli::err($e->getMessage());
                return 1;
            }
        }
        if ($cmd === 'tools') {
            Cli::out(Util::json(['tools' => array_column(self::tools(), 'name')], JSON_PRETTY_PRINT));
            return 0;
        }
        Cli::out("Usage: aiopskit odoo-mcp <serve|check|tools>\n\n" .
            "Environment: ODOO_URL, ODOO_DB, ODOO_USER, ODOO_PASSWORD,\n" .
            "             ODOO_MODELS (comma list), ODOO_ALLOW_WRITE=1\n" .
            "Serve speaks MCP over stdio (newline-delimited JSON).");
        return 0;
    }

    public static function serve(): void
    {
        while (($line = fgets(STDIN)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (stripos($line, 'Content-Length:') === 0) {
                $length = (int) trim(substr($line, 15));
                while (($header = fgets(STDIN)) !== false && trim($header) !== '') {
                }
                $body = '';
                while (strlen($body) < $length) {
                    $chunk = fread(STDIN, $length - strlen($body));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $body .= $chunk;
                }
                $line = trim($body);
            }
            $message = json_decode($line, true);
            if (!is_array($message)) {
                self::emit(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]);
                continue;
            }
            $response = self::handle($message);
            if ($response !== null) {
                self::emit($response);
            }
        }
    }

    public static function handle(array $message): ?array
    {
        $id = $message['id'] ?? null;
        $method = (string) ($message['method'] ?? '');
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        if ($method === 'notifications/initialized' || $method === 'notifications/cancelled') {
            return null;
        }
        if ($method === 'initialize') {
            return self::ok($id, [
                'protocolVersion' => '2024-11-05',
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'aiopskit-odoo-mcp', 'version' => self::VERSION],
            ]);
        }
        if ($method === 'ping') {
            return self::ok($id, ['pong' => true]);
        }
        if ($method === 'tools/list') {
            return self::ok($id, ['tools' => self::tools()]);
        }
        if ($method === 'tools/call') {
            $name = (string) ($params['name'] ?? '');
            $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
            try {
                $result = self::callTool($name, $arguments);
                return self::ok($id, [
                    'content' => [['type' => 'text', 'text' => is_string($result) ? $result : Util::json($result, JSON_PRETTY_PRINT)]],
                    'isError' => false,
                ]);
            } catch (\Throwable $e) {
                return self::ok($id, [
                    'content' => [['type' => 'text', 'text' => $e->getMessage()]],
                    'isError' => true,
                ]);
            }
        }
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => -32601, 'message' => 'Method not found: ' . $method],
        ];
    }

    public static function tools(): array
    {
        $modelProp = ['type' => 'string', 'description' => 'Odoo model, e.g. res.partner'];
        return [
            self::tool('odoo_search_read', 'Search and read records (read-only).', [
                'model' => $modelProp,
                'domain' => ['type' => 'array', 'description' => 'Odoo domain, e.g. [["is_company","=",true]]', 'items' => []],
                'fields' => ['type' => 'array', 'items' => ['type' => 'string']],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT],
                'offset' => ['type' => 'integer', 'minimum' => 0],
                'order' => ['type' => 'string'],
            ], ['model']),
            self::tool('odoo_read', 'Read records by id (read-only).', [
                'model' => $modelProp,
                'ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'fields' => ['type' => 'array', 'items' => ['type' => 'string']],
            ], ['model', 'ids']),
            self::tool('odoo_fields_get', 'Inspect model fields (read-only).', [
                'model' => $modelProp,
                'attributes' => ['type' => 'array', 'items' => ['type' => 'string']],
            ], ['model']),
            self::tool('odoo_models', 'List installed models (read-only).', [
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT],
            ], []),
            self::tool('odoo_create', 'Create one record (requires ODOO_ALLOW_WRITE=1 and a model in ODOO_MODELS).', [
                'model' => $modelProp,
                'values' => ['type' => 'object', 'description' => 'Field values'],
            ], ['model', 'values']),
            self::tool('odoo_write', 'Update records (requires write access).', [
                'model' => $modelProp,
                'ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'values' => ['type' => 'object'],
            ], ['model', 'ids', 'values']),
            self::tool('odoo_unlink', 'Delete records (requires write access).', [
                'model' => $modelProp,
                'ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            ], ['model', 'ids']),
            self::tool('odoo_execute_kw', 'Call any Odoo model method (write access required except for known read methods).', [
                'model' => $modelProp,
                'method' => ['type' => 'string'],
                'args' => ['type' => 'array', 'items' => []],
                'kwargs' => ['type' => 'object'],
            ], ['model', 'method']),
        ];
    }

    private static function tool(string $name, string $description, array $properties, array $required): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'inputSchema' => [
                'type' => 'object',
                'properties' => $properties,
                'required' => $required,
                'additionalProperties' => false,
            ],
        ];
    }

    public static function callTool(string $name, array $args): mixed
    {
        $allowed = self::allowedModels();
        $write = self::writeEnabled();
        $client = OdooClient::fromConfig();
        if (!$client->configured()) {
            throw new \RuntimeException('Odoo is not configured on the server.');
        }
        $started = microtime(true);
        try {
            $result = match ($name) {
                'odoo_search_read' => self::searchRead($client, $args, $allowed),
                'odoo_read' => self::read($client, $args, $allowed),
                'odoo_fields_get' => self::fieldsGet($client, $args, $allowed),
                'odoo_models' => self::models($client, $args),
                'odoo_create' => self::create($client, $args, $allowed, $write),
                'odoo_write' => self::writeRecords($client, $args, $allowed, $write),
                'odoo_unlink' => self::unlink($client, $args, $allowed, $write),
                'odoo_execute_kw' => self::executeKw($client, $args, $allowed, $write),
                default => throw new \RuntimeException('Unknown tool: ' . $name),
            };
            self::audit($name, $args, true, $started, null);
            return $result;
        } catch (\Throwable $e) {
            self::audit($name, $args, false, $started, $e->getMessage());
            throw $e;
        }
    }

    private static function allowedModels(): array
    {
        $raw = (string) Config::env('ODOO_MODELS', implode(',', (array) Config::get('odoo.models', [])));
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    private static function writeEnabled(): bool
    {
        return in_array(strtolower((string) Config::env('ODOO_ALLOW_WRITE', Config::get('odoo.allow_write') ? '1' : '0')), ['1', 'true', 'yes', 'on'], true);
    }

    private static function guardModel(string $model, array $allowed): void
    {
        if ($allowed !== [] && !in_array($model, $allowed, true)) {
            throw new \RuntimeException('Model is not in ODOO_MODELS: ' . $model);
        }
    }

    private static function guardWrite(array $allowed, bool $write, string $model): void
    {
        if (!$write) {
            throw new \RuntimeException('Write operations are disabled. Set ODOO_ALLOW_WRITE=1 to enable them.');
        }
        if ($allowed === [] || !in_array($model, $allowed, true)) {
            throw new \RuntimeException('Write requires an explicit model allowlist (ODOO_MODELS).');
        }
    }

    private static function limit(array $args): int
    {
        return max(1, min(self::MAX_LIMIT, (int) ($args['limit'] ?? 80)));
    }

    private static function fields(array $args): array
    {
        $fields = array_values(array_filter(array_map('strval', (array) ($args['fields'] ?? []))));
        return array_slice($fields, 0, self::MAX_FIELDS);
    }

    private static function searchRead(OdooClient $client, array $args, array $allowed): array
    {
        $model = (string) ($args['model'] ?? '');
        self::guardModel($model, $allowed);
        $kwargs = ['limit' => self::limit($args)];
        if (isset($args['offset'])) {
            $kwargs['offset'] = max(0, (int) $args['offset']);
        }
        if (isset($args['order'])) {
            $kwargs['order'] = (string) $args['order'];
        }
        if (self::fields($args) !== []) {
            $kwargs['fields'] = self::fields($args);
        }
        return $client->executeKw($model, 'search_read', [$args['domain'] ?? []], $kwargs);
    }

    private static function read(OdooClient $client, array $args, array $allowed): array
    {
        $model = (string) ($args['model'] ?? '');
        self::guardModel($model, $allowed);
        $ids = array_values(array_map('intval', (array) ($args['ids'] ?? [])));
        $kwargs = self::fields($args) !== [] ? ['fields' => self::fields($args)] : [];
        return $client->executeKw($model, 'read', [$ids], $kwargs);
    }

    private static function fieldsGet(OdooClient $client, array $args, array $allowed): mixed
    {
        $model = (string) ($args['model'] ?? '');
        self::guardModel($model, $allowed);
        $attributes = (array) ($args['attributes'] ?? ['string', 'type', 'required', 'readonly', 'relation']);
        return $client->executeKw($model, 'fields_get', [], ['attributes' => $attributes]);
    }

    private static function models(OdooClient $client, array $args): array
    {
        return $client->executeKw('ir.model', 'search_read', [[['model', 'not like', 'ir.%']]], [
            'fields' => ['model', 'name'],
            'limit' => self::limit($args),
            'order' => 'model',
        ]);
    }

    private static function create(OdooClient $client, array $args, array $allowed, bool $write): mixed
    {
        $model = (string) ($args['model'] ?? '');
        self::guardWrite($allowed, $write, $model);
        return $client->executeKw($model, 'create', [(array) ($args['values'] ?? [])]);
    }

    private static function writeRecords(OdooClient $client, array $args, array $allowed, bool $write): mixed
    {
        $model = (string) ($args['model'] ?? '');
        self::guardWrite($allowed, $write, $model);
        $ids = array_values(array_map('intval', (array) ($args['ids'] ?? [])));
        return $client->executeKw($model, 'write', [$ids, (array) ($args['values'] ?? [])]);
    }

    private static function unlink(OdooClient $client, array $args, array $allowed, bool $write): mixed
    {
        $model = (string) ($args['model'] ?? '');
        self::guardWrite($allowed, $write, $model);
        $ids = array_values(array_map('intval', (array) ($args['ids'] ?? [])));
        return $client->executeKw($model, 'unlink', [$ids]);
    }

    private static function executeKw(OdooClient $client, array $args, array $allowed, bool $write): mixed
    {
        $model = (string) ($args['model'] ?? '');
        $method = (string) ($args['method'] ?? '');
        $readOnly = ['search', 'search_count', 'search_read', 'read', 'fields_get', 'name_get', 'name_search', 'default_get'];
        if (!in_array($method, $readOnly, true)) {
            self::guardWrite($allowed, $write, $model);
        } else {
            self::guardModel($model, $allowed);
        }
        return $client->executeKw($model, $method, (array) ($args['args'] ?? []), (array) ($args['kwargs'] ?? []));
    }

    private static function audit(string $tool, array $args, bool $ok, float $started, ?string $error): void
    {
        Util::appendLine(Config::storage('odoo-mcp-audit.jsonl'), [
            'ts' => Util::now(),
            'tool' => $tool,
            'model' => $args['model'] ?? null,
            'ok' => $ok,
            'ms' => (int) round((microtime(true) - $started) * 1000),
            'error' => $error === null ? null : Util::truncate($error, 200),
        ]);
    }

    private static function ok(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private static function emit(array $payload): void
    {
        fwrite(STDOUT, Util::json($payload) . "\n");
        fflush(STDOUT);
    }
}
