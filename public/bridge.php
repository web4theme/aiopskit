<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use AIOpsKit\Apps\LlmBridge;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path === '/health') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'providers' => count((array) (LlmBridge::config()['providers'] ?? []))]);
    exit;
}
if ($path !== '/v1/chat/completions' && $path !== '/chat/completions') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => ['message' => 'Not found']]);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => ['message' => 'POST only']]);
    exit;
}
$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => ['message' => 'Invalid JSON body']]);
    exit;
}
$result = LlmBridge::forward($payload);
header('Content-Type: application/json');
http_response_code($result['ok'] ? 200 : 502);
if ($result['ok']) {
    echo $result['body'];
} else {
    echo json_encode([
        'error' => [
            'message' => $result['error'] ?? 'All providers failed',
            'provider' => $result['provider'] ?? null,
            'attempts' => $result['attempts'] ?? [],
        ],
    ], JSON_UNESCAPED_SLASHES);
}
