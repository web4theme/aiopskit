<?php
declare(strict_types=1);

namespace AIOpsKit\Apps;

/**
 * App registry. Each app is a class with a static cli(array $options): int
 * and optionally a static web(array $query, array $post): array method.
 */
final class Registry
{
    public const APPS = [
        'odoo-mcp' => OdooMcp::class,
        'anyphp-mcp' => AnyPhpMcp::class,
        'gateway' => McpGateway::class,
        'ledger' => TokenLedger::class,
        'bridge' => LlmBridge::class,
        'audit' => PromptAudit::class,
        'guard' => AgentGuard::class,
        'cache' => EmbeddingCache::class,
        'dashboard' => UsageDashboard::class,
        'dataforge' => DataForge::class,
    ];

    public static function resolve(string $name): ?string
    {
        return self::APPS[$name] ?? null;
    }

    public static function all(): array
    {
        return self::APPS;
    }
}

require_once __DIR__ . '/odoo_mcp.php';
require_once __DIR__ . '/anyphp_mcp.php';
require_once __DIR__ . '/mcp_gateway.php';
require_once __DIR__ . '/token_ledger.php';
require_once __DIR__ . '/llm_bridge.php';
require_once __DIR__ . '/prompt_audit.php';
require_once __DIR__ . '/agent_guard.php';
require_once __DIR__ . '/embedding_cache.php';
require_once __DIR__ . '/usage_dashboard.php';
require_once __DIR__ . '/dataforge.php';
