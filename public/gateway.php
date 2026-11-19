<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use AIOpsKit\Apps\McpGateway;

header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}
$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if ($authorization === '' && function_exists('apache_request_headers')) {
    $headers = apache_request_headers();
    foreach ($headers as $name => $value) {
        if (strtolower((string) $name) === 'authorization') {
            $authorization = (string) $value;
            break;
        }
    }
}
$result = McpGateway::handle((string) $authorization, (string) file_get_contents('php://input'));
http_response_code($result['status']);
echo json_encode($result['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
