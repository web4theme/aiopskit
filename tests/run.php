<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/aiopskit-test-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
putenv('AIOPSKIT_STORAGE=' . $tmp);

require $root . '/src/bootstrap.php';

use AIOpsKit\Apps\AgentGuard;
use AIOpsKit\Apps\AnyPhpMcp;
use AIOpsKit\Apps\DataForge;
use AIOpsKit\Apps\EmbeddingCache;
use AIOpsKit\Apps\LlmBridge;
use AIOpsKit\Apps\McpGateway;
use AIOpsKit\Apps\OdooMcp;
use AIOpsKit\Apps\PromptAudit;
use AIOpsKit\Apps\TokenLedger;
use AIOpsKit\Apps\UsageDashboard;
use AIOpsKit\Config;
use AIOpsKit\Http;
use AIOpsKit\Util;

$tests = 0;
$failures = [];
$current = '';

function section(string $name): void
{
    global $current;
    $current = $name;
}

function check(string $name, bool $condition, string $detail = ''): void
{
    global $tests, $failures, $current;
    $tests++;
    if (!$condition) {
        $failures[] = $current . ' :: ' . $name . ($detail !== '' ? ' — ' . $detail : '');
    }
}

function same(string $name, mixed $expected, mixed $actual): void
{
    check($name, $expected === $actual, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function throws(string $name, callable $fn): void
{
    try {
        $fn();
        check($name, false, 'no exception');
    } catch (Throwable $e) {
        check($name, true);
    }
}

// ---------------------------------------------------------------- Util
section('Util');
same('csvRow quotes commas', 'a,"b,c","d""e"', Util::csvRow(['a', 'b,c', 'd"e']));
same('csvRow plain', 'a,b,c', Util::csvRow(['a', 'b', 'c']));
same('truncate adds ellipsis', 'abc…', Util::truncate('abcdef', 4));
same('mask keeps ends', '1234****7890', Util::mask('1234567890'));
same('mask short', '***', Util::mask('abc'));

// ---------------------------------------------------------------- Http
section('Http');
check('loopback forbidden', Http::isForbiddenHost('http://127.0.0.1/'));
check('private forbidden', Http::isForbiddenHost('http://10.0.0.1/'));
check('localhost forbidden', Http::isForbiddenHost('http://localhost/'));
check('public ip allowed', !Http::isForbiddenHost('https://8.8.8.8/'));
same('non-http refused', 'Only http/https URLs are supported.', Http::request('ftp://example.com')['error']);

Http::setTransport(static fn (string $url, array $options): array => [
    'status' => 200, 'headers' => [], 'body' => '{"ok":true}', 'url' => $url, 'error' => null, 'time' => 0.01,
]);
$stub = Http::request('https://example.com/x');
same('transport stub used', 200, $stub['status']);
Http::setTransport(null);

// ---------------------------------------------------------------- Odoo MCP
section('Odoo MCP');
$init = OdooMcp::handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]);
same('initialize protocol', '2024-11-05', $init['result']['protocolVersion']);
$list = OdooMcp::handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
$names = array_column($list['result']['tools'], 'name');
check('tools list has search_read', in_array('odoo_search_read', $names, true));
check('tools list has create', in_array('odoo_create', $names, true));
$unknown = OdooMcp::handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'nope']]);
check('unknown tool is error', $unknown['result']['isError'] === true);

putenv('ODOO_URL=https://odoo.example.com');
putenv('ODOO_DB=test');
putenv('ODOO_USER=api');
putenv('ODOO_PASSWORD=secret');
putenv('ODOO_MODELS=res.partner');
putenv('ODOO_ALLOW_WRITE=0');
Http::setTransport(static function (string $url, array $options): array {
    $payload = json_decode((string) ($options['body'] ?? '{}'), true);
    $method = $payload['params']['method'] ?? '';
    if ($method === 'login') {
        $body = ['jsonrpc' => '2.0', 'id' => 1, 'result' => 7];
    } else {
        $body = ['jsonrpc' => '2.0', 'id' => 1, 'result' => [['id' => 1, 'name' => 'Acme']]];
    }
    return ['status' => 200, 'headers' => [], 'body' => Util::json($body), 'url' => $url, 'error' => null, 'time' => 0.01];
});
$rows = OdooMcp::callTool('odoo_search_read', ['model' => 'res.partner', 'limit' => 1]);
same('search_read returns rows', 'Acme', $rows[0]['name']);
throws('write blocked when disabled', static fn () => OdooMcp::callTool('odoo_create', ['model' => 'res.partner', 'values' => []]));
throws('model not in allowlist', static fn () => OdooMcp::callTool('odoo_search_read', ['model' => 'sale.order']));
Http::setTransport(null);

// ---------------------------------------------------------------- AnyPHP MCP
section('AnyPHP MCP');
$toolsFile = $tmp . '/tools.php';
file_put_contents($toolsFile, '<?php return [["name" => "server_time", "description" => "now", "command" => "php", "args" => ["-r", "echo gmdate(\\"c\\");"]]];');
putenv('AIOPSKIT_TOOLS=' . $toolsFile);
$loaded = AnyPhpMcp::loadTools();
same('one tool loaded', 1, count($loaded));
$call = AnyPhpMcp::call('server_time', []);
check('tool output looks like a date', (bool) preg_match('/^\d{4}-\d{2}-\d{2}T/', trim($call['stdout'])), $call['stdout']);
$anyList = AnyPhpMcp::handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
same('anyphp mcp tool name', 'server_time', $anyList['result']['tools'][0]['name']);
throws('unknown anyphp tool', static fn () => AnyPhpMcp::call('missing', []));

// ---------------------------------------------------------------- Gateway
section('MCP Gateway');
$gatewayFile = $tmp . '/gateway.php';
file_put_contents($gatewayFile, '<?php return ["servers" => ["mock" => ["url" => "https://mock.example.com/mcp"]], "tokens" => ["t1" => ["allow" => ["mock.*"]], "t2" => ["allow" => ["other.*"]]]];');
putenv('AIOPSKIT_GATEWAY=' . $gatewayFile);
Http::setTransport(static function (string $url, array $options): array {
    $payload = json_decode((string) ($options['body'] ?? '{}'), true);
    $method = $payload['method'] ?? '';
    $result = $method === 'tools/list'
        ? ['tools' => [['name' => 'echo', 'description' => 'echo', 'inputSchema' => ['type' => 'object']]]]
        : ['content' => [['type' => 'text', 'text' => 'ok']], 'isError' => false];
    return ['status' => 200, 'headers' => [], 'body' => Util::json(['jsonrpc' => '2.0', 'id' => $payload['id'] ?? 1, 'result' => $result]), 'url' => $url, 'error' => null, 'time' => 0.01];
});
$unauthorized = McpGateway::handle('', '{}');
same('gateway rejects missing token', 401, $unauthorized['status']);
$aggr = McpGateway::handle('Bearer t1', Util::json(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']));
same('gateway prefixed tool', 'mock.echo', $aggr['body']['result']['tools'][0]['name']);
$callResult = McpGateway::handle('Bearer t1', Util::json(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'mock.echo', 'arguments' => []]]));
same('gateway proxies call', 'ok', $callResult['body']['result']['content'][0]['text']);
$forbidden = McpGateway::handle('Bearer t2', Util::json(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'mock.echo']]));
check('gateway enforces ACL', $forbidden['body']['result']['isError'] === true);
Http::setTransport(null);

// ---------------------------------------------------------------- TokenLedger
section('TokenLedger');
$ledgerPath = $tmp . '/ledger.sqlite';
TokenLedger::record(['project' => 'acme', 'model' => 'gpt-4o', 'prompt_tokens' => 1000, 'completion_tokens' => 200, 'cost_usd' => 0.01, 'latency_ms' => 800], $ledgerPath);
TokenLedger::record(['project' => 'acme', 'model' => 'gpt-4o', 'prompt_tokens' => 2000, 'completion_tokens' => 400, 'cost_usd' => 0.02, 'latency_ms' => 1200], $ledgerPath);
TokenLedger::record(['project' => 'beta', 'model' => 'claude', 'prompt_tokens' => 500, 'completion_tokens' => 100, 'cost_usd' => 0.03, 'latency_ms' => 600], $ledgerPath);
$summary = TokenLedger::summary(30, null, $ledgerPath);
same('ledger total micros', 60000, $summary['total_micros']);
same('ledger groups', 2, count($summary['rows']));
TokenLedger::setBudget('acme', 0.05, 80, $ledgerPath);
$budget = TokenLedger::budgetStatus('acme', $ledgerPath);
same('budget percent', 60, $budget['percent']);
same('budget ok at 60%', 'ok', $budget['status']);
TokenLedger::record(['project' => 'acme', 'model' => 'gpt-4o', 'cost_usd' => 0.01], $ledgerPath);
same('budget alert at 80%', 'alert', TokenLedger::budgetStatus('acme', $ledgerPath)['status']);
$csv = TokenLedger::exportCsv(30, $ledgerPath);
check('ledger csv header', str_contains($csv, 'prompt_tokens'));

// ---------------------------------------------------------------- PromptAudit
section('PromptAudit');
$redaction = PromptAudit::redact('mail a@b.com phone 13800138000 id 110101199001011234 key sk-abcdefghijklmnop1234 card 4111111111111111');
check('redacts email', isset($redaction['counts']['email']));
check('redacts cn phone', isset($redaction['counts']['phone_cn']));
check('redacts cn id', isset($redaction['counts']['id_cn']));
check('redacts api key', isset($redaction['counts']['api_key']));
check('redacts card', isset($redaction['counts']['card']));
check('raw email absent', !str_contains($redaction['text'], 'a@b.com'), $redaction['text']);
$auditPath = $tmp . '/audit.sqlite';
$recorded = PromptAudit::record('acme', 'contact a@b.com', 'reply 13800138000', 'gpt', $auditPath);
same('audit record id', 1, $recorded['id']);
$rows = PromptAudit::search('', 10, null, $auditPath);
check('stored prompt is redacted', !str_contains($rows[0]['prompt'], 'a@b.com'));
$pdo = PromptAudit::pdo($auditPath);
$pdo->exec("INSERT INTO prompts (ts, project, model, prompt, response, redactions) VALUES ('2020-01-01 00:00:00', 'old', '', 'x', '', '{}')");
same('prune old rows', 1, PromptAudit::prune(30, $auditPath));

// ---------------------------------------------------------------- AgentGuard
section('AgentGuard');
$guardPath = $tmp . '/guard.sqlite';
$request = AgentGuard::request('refund', ['order' => 42], 900, $guardPath);
same('guard starts pending', 'pending', AgentGuard::list(1, null, $guardPath)[0]['status']);
$early = AgentGuard::execute($request['id'], $request['token'], $guardPath);
check('cannot execute before approval', $early['ok'] === false);
check('approve works', AgentGuard::approve($request['id'], 'alex', $guardPath));
$wrong = AgentGuard::execute($request['id'], 'bad-token', $guardPath);
check('wrong token rejected', $wrong['ok'] === false);
$executed = AgentGuard::execute($request['id'], $request['token'], $guardPath);
check('execute with token', $executed['ok'] === true && $executed['payload']['order'] === 42);
$again = AgentGuard::execute($request['id'], $request['token'], $guardPath);
check('token is one-time', $again['ok'] === false);
$expired = AgentGuard::request('delete', [], 900, $guardPath);
AgentGuard::approve($expired['id'], 'alex', $guardPath);
AgentGuard::pdo($guardPath)->exec('UPDATE requests SET expires_at = ' . (time() - 10) . ' WHERE id = ' . $expired['id']);
check('expired request rejected', AgentGuard::execute($expired['id'], $expired['token'], $guardPath)['ok'] === false);
check('hmac verify', AgentGuard::verify('payload', AgentGuard::sign('payload', 'secret'), 'secret'));
check('hmac rejects', !AgentGuard::verify('payload', 'nope', 'secret'));

// ---------------------------------------------------------------- EmbeddingCache
section('EmbeddingCache');
$cachePath = $tmp . '/cache.sqlite';
EmbeddingCache::put('text-embedding-3-small', 'hello', [0.1, 0.2], 86400, $cachePath);
same('cache hit', [0.1, 0.2], EmbeddingCache::get('text-embedding-3-small', 'hello', $cachePath));
same('cache model isolation', null, EmbeddingCache::get('other-model', 'hello', $cachePath));
EmbeddingCache::put('text-embedding-3-small', 'expired', [0.3], 60, $cachePath);
EmbeddingCache::pdo($cachePath)->exec("UPDATE embeddings SET expires_at = 1 WHERE text_hash = '" . hash('sha256', 'expired') . "'");
same('cache ttl expires', null, EmbeddingCache::get('text-embedding-3-small', 'expired', $cachePath));
$stats = EmbeddingCache::stats($cachePath);
same('cache hits', 1, $stats['hits']);
same('cache misses', 2, $stats['misses']);
same('prune expired', 1, EmbeddingCache::prune($cachePath));

// ---------------------------------------------------------------- DataForge
section('DataForge');
$csv = "\xEF\xBB\xBFname;note\r\nAlice;\"hello; world\"\r\nBob;\"line1\nline2\"\r\n";
$parsed = DataForge::parseCsv($csv);
same('csv delimiter detected', ';', $parsed['delimiter']);
same('csv bom detected', true, $parsed['bom']);
same('csv rows', 2, count($parsed['rows']));
same('quoted semicolon kept', 'hello; world', $parsed['rows'][0]['note']);
same('quoted newline kept', "line1\nline2", $parsed['rows'][1]['note']);
same('number eu format', '1234.56', DataForge::normalizeNumber('1.234,56'));
same('number parentheses negative', '-1234.56', DataForge::normalizeNumber('(1,234.56)'));
same('number thousands', '1234', DataForge::normalizeNumber('1,234'));
same('date slashes', '2026-11-19', DataForge::normalizeDate('2026/11/19'));
same('date day first', '2026-11-19', DataForge::normalizeDate('19/11/2026'));
$dedupeParsed = DataForge::parseCsv("email,name\na@b.com,Alice\na@b.com,Alice 2\nb@b.com,Bob\n");
$cleaned = DataForge::clean($dedupeParsed, ['dedupe' => ['email']]);
same('dedupe keeps one', 2, $cleaned['report']['rows_out']);
same('dedupe report', 1, $cleaned['report']['duplicates']);
$mapped = DataForge::clean($dedupeParsed, ['map' => ['email' => 'email_address']]);
check('mapping renames header', in_array('email_address', $mapped['headers'], true));

// ---------------------------------------------------------------- Dashboard
section('Dashboard');
$snapshot = UsageDashboard::snapshot(7, $ledgerPath);
check('dashboard totals', $snapshot['total_micros'] > 0);
check('dashboard series length', count($snapshot['series']) === 7);
check('dashboard svg has bars', str_contains(UsageDashboard::bars($snapshot['series']), '<rect'));

// ---------------------------------------------------------------- LlmBridge
section('LLM Bridge');
$bridgeFile = $tmp . '/bridge.php';
file_put_contents($bridgeFile, '<?php return ["providers" => [["name" => "primary", "base_url" => "https://primary.example.com/v1", "api_key" => "k1", "models" => ["m" => "m"]], ["name" => "backup", "base_url" => "https://backup.example.com/v1", "api_key" => "k2", "models" => ["m" => "m"]]]];');
putenv('AIOPSKIT_BRIDGE=' . $bridgeFile);
$calls = 0;
Http::setTransport(static function (string $url, array $options) use (&$calls): array {
    $calls++;
    if (str_contains($url, 'primary')) {
        return ['status' => 500, 'headers' => [], 'body' => '{"error":"down"}', 'url' => $url, 'error' => null, 'time' => 0.01];
    }
    return ['status' => 200, 'headers' => [], 'body' => '{"choices":[{"message":{"content":"ok"}}]}', 'url' => $url, 'error' => null, 'time' => 0.01];
});
$forward = LlmBridge::forward(['model' => 'm', 'messages' => [['role' => 'user', 'content' => 'hi']]]);
check('bridge fails over', $forward['ok'] === true);
same('bridge used backup', 'backup', $forward['provider']);
same('bridge attempted twice', 2, $calls);
$log = LlmBridge::pdo($tmp . '/bridge.sqlite')->query('SELECT COUNT(*) FROM requests')->fetchColumn();
same('bridge log rows', 2, (int) $log);
Http::setTransport(null);

// ---------------------------------------------------------------- summary
$passed = $tests - count($failures);
echo PHP_EOL . 'AIOpsKit tests: ' . $passed . ' passed, ' . count($failures) . ' failed' . PHP_EOL;
foreach ($failures as $failure) {
    echo '  FAIL ' . $failure . PHP_EOL;
}

// cleanup
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($iterator as $item) {
    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
}
@rmdir($tmp);

exit($failures === [] ? 0 : 1);
