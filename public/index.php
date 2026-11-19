<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use AIOpsKit\Apps\AgentGuard;
use AIOpsKit\Apps\DataForge;
use AIOpsKit\Apps\EmbeddingCache;
use AIOpsKit\Apps\PromptAudit;
use AIOpsKit\Apps\TokenLedger;
use AIOpsKit\Apps\UsageDashboard;
use AIOpsKit\View;

/**
 * The UI has no session auth. Reject cross-site POSTs so that a third-party
 * page cannot approve a pending Agent Guard request through HTTP auth.
 */
function same_origin(): bool
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $source = $origin !== '' ? $origin : $referer;
    if ($source === '') {
        return false;
    }
    $sourceHost = parse_url($source, PHP_URL_HOST);
    return is_string($sourceHost) && $sourceHost === parse_url('http://' . $host, PHP_URL_HOST);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !same_origin()) {
    http_response_code(403);
    echo 'Forbidden: cross-origin POST.';
    exit;
}

$app = (string) ($_GET['app'] ?? 'overview');
$query = $_GET;
$post = $_POST;
$data = ['app' => $app, 'title' => 'AIOpsKit', 'error' => null];

try {
    switch ($app) {
        case 'overview':
            $data['title'] = 'Self-hosted AI operations';
            break;
        case 'ledger':
            $data['title'] = 'TokenLedger';
            $data += TokenLedger::web($query);
            break;
        case 'dashboard':
            $data['title'] = 'AI Usage Dashboard';
            $days = max(1, min(365, (int) ($query['days'] ?? 30)));
            $data['snapshot'] = UsageDashboard::snapshot($days);
            $data['bars'] = UsageDashboard::bars($data['snapshot']['series']);
            break;
        case 'audit':
            $data['title'] = 'Prompt Audit Log';
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
                $result = PromptAudit::record(
                    (string) ($post['project'] ?? 'default'),
                    (string) ($post['prompt'] ?? ''),
                    (string) ($post['response'] ?? ''),
                    (string) ($post['model'] ?? '')
                );
                $data['recorded'] = $result;
            }
            $data += PromptAudit::web($query);
            break;
        case 'guard':
            $data['title'] = 'Agent Guard';
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
                $action = (string) ($post['do'] ?? '');
                if ($action === 'approve' || $action === 'deny') {
                    $id = (int) ($post['id'] ?? 0);
                    $action === 'approve' ? AgentGuard::approve($id, 'web') : AgentGuard::deny($id, 'web');
                }
            }
            $data += AgentGuard::web($query);
            break;
        case 'cache':
            $data['title'] = 'Embedding Cache';
            $data += EmbeddingCache::web($query);
            break;
        case 'dataforge':
            $data['title'] = 'DataForge';
            $data += DataForge::web($query, $post);
            break;
        case 'odoo-mcp':
            $data['title'] = 'Odoo MCP Server';
            $data['configured'] = AIOpsKit\Apps\OdooClient::fromConfig()->configured();
            break;
        case 'gateway':
            $data['title'] = 'MCP Gateway';
            $data['config'] = AIOpsKit\Apps\McpGateway::config();
            break;
        case 'bridge':
            $data['title'] = 'LLM Bridge';
            $data['providers'] = (array) (AIOpsKit\Apps\LlmBridge::config()['providers'] ?? []);
            break;
        case 'anyphp':
            $data['title'] = 'AnyPHP MCP Adapter';
            $data['tools'] = AIOpsKit\Apps\AnyPhpMcp::mcpTools();
            break;
        default:
            http_response_code(404);
            $data['title'] = 'Not found';
    }
} catch (Throwable $e) {
    $data['error'] = $e->getMessage();
}

echo View::render('page', $data);
