<?php
declare(strict_types=1);

use AIOpsKit\Util;
use AIOpsKit\Apps\UsageDashboard;

$e = static fn (?string $v): string => Util::e($v);
$nav = [
    'overview' => 'Overview',
    'odoo-mcp' => 'Odoo MCP',
    'anyphp' => 'AnyPHP MCP',
    'gateway' => 'Gateway',
    'ledger' => 'Ledger',
    'dashboard' => 'Dashboard',
    'audit' => 'Audit',
    'guard' => 'Guard',
    'cache' => 'Embedding Cache',
    'dataforge' => 'DataForge',
    'bridge' => 'LLM Bridge',
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($title ?? 'AIOpsKit') ?> · AIOpsKit</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<header class="top">
  <div class="wrap">
    <a class="brand" href="?app=overview">AI<span>Ops</span>Kit</a>
    <nav>
      <?php foreach ($nav as $key => $label): ?>
        <a href="?app=<?= $e($key) ?>" class="<?= $app === $key ? 'on' : '' ?>"><?= $e($label) ?></a>
      <?php endforeach; ?>
    </nav>
  </div>
</header>

<main class="wrap">
<?php if (!empty($error)): ?>
  <div class="alert"><?= $e($error) ?></div>
<?php endif; ?>

<?php if ($app === 'overview'): ?>
  <section class="hero">
    <h1>Self-hosted AI operations, in plain PHP.</h1>
    <p>Ten small tools for teams running AI in production: MCP servers, cost control, audit trails, approvals and data prep. No Composer, no cloud account, no telemetry.</p>
    <div class="chips"><span>PHP 8.1+</span><span>SQLite</span><span>Zero dependencies</span><span>Self-hosted</span></div>
  </section>
  <div class="cards">
    <?php
    $products = [
        ['Odoo MCP Server', 'Read and write Odoo from any MCP client.', 'Free / Pro $49', 'odoo-mcp'],
        ['AnyPHP MCP Adapter', 'Turn any PHP script into an MCP tool.', '$19', 'anyphp'],
        ['MCP Gateway', 'One authenticated endpoint for many MCP servers.', '$39', 'gateway'],
        ['TokenLedger', 'Token and cost accounting per project and model.', '$29', 'ledger'],
        ['AI Usage Dashboard', 'Self-hosted spend and latency charts.', '$29', 'dashboard'],
        ['Prompt Audit Log', 'Prompts and responses with PII redaction.', '$29', 'audit'],
        ['Agent Guard', 'Human approval before an agent writes.', '$49', 'guard'],
        ['Embedding Cache', 'Cache embeddings and cut RAG cost.', '$29', 'cache'],
        ['LLM Bridge', 'OpenAI-compatible failover across providers.', '$49', 'bridge'],
        ['DataForge', 'Clean, dedupe and map CSV for AI ingestion.', '$29', 'dataforge'],
    ];
    foreach ($products as [$name, $desc, $price, $key]): ?>
      <a class="card" href="?app=<?= $e($key) ?>">
        <h3><?= $e($name) ?></h3>
        <p><?= $e($desc) ?></p>
        <span class="price"><?= $e($price) ?></span>
      </a>
    <?php endforeach; ?>
  </div>

<?php elseif ($app === 'ledger'): ?>
  <h1>TokenLedger</h1>
  <p class="lead">Spend is recorded in micro-USD. Last <?= (int) $days ?> days.</p>
  <div class="stats">
    <div><strong><?= $e(UsageDashboard::money((int) $summary['total_micros'])) ?></strong><span>total</span></div>
    <div><strong><?= array_sum(array_map(static fn ($r) => (int) $r['calls'], $summary['rows'])) ?></strong><span>calls</span></div>
    <div><strong><?= count($summary['rows']) ?></strong><span>project × model</span></div>
  </div>
  <table>
    <thead><tr><th>Project</th><th>Model</th><th>Calls</th><th>Tokens</th><th>Cost</th><th>Avg ms</th><th>Budget</th></tr></thead>
    <tbody>
    <?php foreach ($summary['rows'] as $row): $budget = $budgets[$row['project']] ?? null; ?>
      <tr>
        <td><?= $e((string) $row['project']) ?></td>
        <td><?= $e((string) $row['model']) ?></td>
        <td><?= (int) $row['calls'] ?></td>
        <td><?= (int) $row['prompt_tokens'] + (int) $row['completion_tokens'] ?></td>
        <td><?= $e(UsageDashboard::money((int) $row['cost_micros'])) ?></td>
        <td><?= (int) $row['avg_latency'] ?></td>
        <td><?= $budget ? $e($budget['status'] . ' ' . $budget['percent'] . '%') : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($summary['rows'] === []): ?><tr><td colspan="7" class="muted">No calls recorded yet. Use <code>aiopskit ledger record</code>.</td></tr><?php endif; ?>
    </tbody>
  </table>

<?php elseif ($app === 'dashboard'): ?>
  <h1>AI Usage Dashboard</h1>
  <div class="stats">
    <div><strong><?= $e(UsageDashboard::money((int) $snapshot['total_micros'])) ?></strong><span>spend</span></div>
    <div><strong><?= (int) $snapshot['calls'] ?></strong><span>calls</span></div>
    <div><strong><?= count($snapshot['by_model']) ?></strong><span>models</span></div>
  </div>
  <div class="panel"><?= $bars ?></div>
  <table>
    <thead><tr><th>Model</th><th>Spend</th></tr></thead>
    <tbody>
    <?php foreach ($snapshot['by_model'] as $model => $micros): ?>
      <tr><td><?= $e((string) $model) ?></td><td><?= $e(UsageDashboard::money((int) $micros)) ?></td></tr>
    <?php endforeach; ?>
    <?php if ($snapshot['by_model'] === []): ?><tr><td colspan="2" class="muted">No data yet.</td></tr><?php endif; ?>
    </tbody>
  </table>

<?php elseif ($app === 'audit'): ?>
  <h1>Prompt Audit Log</h1>
  <form method="post" class="grid-form">
    <input name="project" placeholder="project" value="default">
    <input name="model" placeholder="model">
    <textarea name="prompt" rows="3" placeholder="Prompt (stored redacted)"></textarea>
    <textarea name="response" rows="3" placeholder="Response (optional)"></textarea>
    <button type="submit">Record</button>
  </form>
  <?php if (isset($recorded)): ?>
    <div class="alert ok">Recorded #<?= (int) $recorded['id'] ?> · redactions <?= $e(Util::json($recorded['counts'])) ?></div>
  <?php endif; ?>
  <form method="get" class="inline">
    <input type="hidden" name="app" value="audit">
    <input name="q" value="<?= $e($q ?? '') ?>" placeholder="search">
    <button type="submit">Search</button>
  </form>
  <table>
    <thead><tr><th>#</th><th>When</th><th>Project</th><th>Prompt</th><th>Redactions</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
      <tr>
        <td><?= (int) $row['id'] ?></td>
        <td><?= $e((string) $row['ts']) ?></td>
        <td><?= $e((string) $row['project']) ?></td>
        <td><?= $e(Util::truncate((string) $row['prompt'], 160)) ?></td>
        <td><?= $e((string) $row['redactions']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?><tr><td colspan="5" class="muted">No records.</td></tr><?php endif; ?>
    </tbody>
  </table>

<?php elseif ($app === 'guard'): ?>
  <h1>Agent Guard</h1>
  <p class="lead">A write action stays pending until a human approves it. Execution consumes a one-time token.</p>
  <table>
    <thead><tr><th>#</th><th>Action</th><th>Status</th><th>Expires</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
      <tr>
        <td><?= (int) $row['id'] ?></td>
        <td><?= $e((string) $row['action']) ?></td>
        <td><span class="badge <?= $e((string) $row['status']) ?>"><?= $e((string) $row['status']) ?></span></td>
        <td><?= $e(gmdate('Y-m-d H:i', (int) $row['expires_at'])) ?></td>
        <td>
          <?php if ($row['status'] === 'pending'): ?>
          <form method="post" class="inline">
            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
            <button name="do" value="approve">Approve</button>
            <button name="do" value="deny" class="danger">Deny</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?><tr><td colspan="5" class="muted">No requests.</td></tr><?php endif; ?>
    </tbody>
  </table>

<?php elseif ($app === 'cache'): ?>
  <h1>Embedding Cache</h1>
  <div class="stats">
    <div><strong><?= (int) $stats['entries'] ?></strong><span>entries</span></div>
    <div><strong><?= (int) $stats['hits'] ?></strong><span>hits</span></div>
    <div><strong><?= (int) $stats['misses'] ?></strong><span>misses</span></div>
    <div><strong><?= $e((string) $stats['hit_rate']) ?>%</strong><span>hit rate</span></div>
  </div>
  <table>
    <thead><tr><th>Model</th><th>Hits</th><th>Misses</th></tr></thead>
    <tbody>
    <?php foreach ($stats['rows'] as $row): ?>
      <tr><td><?= $e((string) $row['model']) ?></td><td><?= (int) $row['hits'] ?></td><td><?= (int) $row['misses'] ?></td></tr>
    <?php endforeach; ?>
    <?php if ($stats['rows'] === []): ?><tr><td colspan="3" class="muted">No data.</td></tr><?php endif; ?>
    </tbody>
  </table>

<?php elseif ($app === 'dataforge'): ?>
  <h1>DataForge</h1>
  <form method="post" class="grid-form">
    <textarea name="csv" rows="8" placeholder="Paste CSV here (BOM, ; or , delimiters supported)"></textarea>
    <div class="row">
      <input name="dedupe" placeholder="dedupe key (e.g. email)">
      <input name="numbers" placeholder="number columns (e.g. amount)">
      <input name="dates" placeholder="date columns (e.g. date)">
      <input name="delimiter" placeholder="delimiter (auto)">
    </div>
    <button type="submit">Clean</button>
  </form>
  <?php if ($result !== null): ?>
    <div class="stats">
      <div><strong><?= (int) $result['report']['rows_in'] ?></strong><span>rows in</span></div>
      <div><strong><?= (int) $result['report']['rows_out'] ?></strong><span>rows out</span></div>
      <div><strong><?= (int) $result['report']['duplicates'] ?></strong><span>duplicates</span></div>
      <div><strong><?= count($result['report']['errors']) ?></strong><span>errors</span></div>
    </div>
    <table>
      <thead><tr><?php foreach ($result['headers'] as $h): ?><th><?= $e((string) $h) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach (array_slice($result['rows'], 0, 20) as $row): ?>
        <tr><?php foreach ($result['headers'] as $h): ?><td><?= $e((string) ($row[$h] ?? '')) ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

<?php elseif ($app === 'odoo-mcp'): ?>
  <h1>Odoo MCP Server</h1>
  <p class="lead">Speaks MCP over stdio. Point Cursor or Claude Desktop at <code>bin/aiopskit odoo-mcp serve</code>.</p>
  <div class="alert <?= $configured ? 'ok' : '' ?>">Odoo config: <?= $configured ? 'configured' : 'not configured (set ODOO_URL/ODOO_DB/ODOO_USER/ODOO_PASSWORD)' ?></div>
  <pre>{
  "mcpServers": {
    "odoo": {
      "command": "php",
      "args": ["/path/to/aiopskit/bin/aiopskit", "odoo-mcp", "serve"],
      "env": {
        "ODOO_URL": "https://odoo.example.com",
        "ODOO_DB": "prod",
        "ODOO_USER": "api",
        "ODOO_PASSWORD": "…",
        "ODOO_MODELS": "res.partner,sale.order",
        "ODOO_ALLOW_WRITE": "0"
      }
    }
  }
}</pre>

<?php elseif ($app === 'gateway'): ?>
  <h1>MCP Gateway</h1>
  <p class="lead">POST JSON-RPC to <code>/mcp</code> with <code>Authorization: Bearer &lt;token&gt;</code>.</p>
  <div class="stats">
    <div><strong><?= count((array) ($config['servers'] ?? [])) ?></strong><span>servers</span></div>
    <div><strong><?= count((array) ($config['tokens'] ?? [])) ?></strong><span>tokens</span></div>
  </div>
  <table>
    <thead><tr><th>Server</th><th>Target</th></tr></thead>
    <tbody>
    <?php foreach ((array) ($config['servers'] ?? []) as $name => $server): ?>
      <tr><td><?= $e((string) $name) ?></td><td><?= $e((string) ($server['url'] ?? implode(' ', (array) ($server['args'] ?? [])))) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>

<?php elseif ($app === 'bridge'): ?>
  <h1>LLM Bridge</h1>
  <p class="lead">Point clients at <code>http://127.0.0.1:8790/v1/chat/completions</code>. Failover follows the order in <code>config/bridge.php</code>.</p>
  <table>
    <thead><tr><th>Provider</th><th>Base URL</th><th>Key</th></tr></thead>
    <tbody>
    <?php foreach ($providers as $provider): ?>
      <tr><td><?= $e((string) ($provider['name'] ?? '')) ?></td><td><?= $e((string) ($provider['base_url'] ?? '')) ?></td><td><?= $e(Util::mask((string) ($provider['api_key'] ?? ''))) ?></td></tr>
    <?php endforeach; ?>
    <?php if ($providers === []): ?><tr><td colspan="3" class="muted">No providers configured.</td></tr><?php endif; ?>
    </tbody>
  </table>

<?php elseif ($app === 'anyphp'): ?>
  <h1>AnyPHP MCP Adapter</h1>
  <p class="lead">Each entry in <code>config/mcp-tools.php</code> becomes an MCP tool.</p>
  <table>
    <thead><tr><th>Tool</th><th>Description</th></tr></thead>
    <tbody>
    <?php foreach ($tools as $tool): ?>
      <tr><td><?= $e((string) ($tool['name'] ?? '')) ?></td><td><?= $e((string) ($tool['description'] ?? '')) ?></td></tr>
    <?php endforeach; ?>
    <?php if ($tools === []): ?><tr><td colspan="2" class="muted">No tools configured.</td></tr><?php endif; ?>
    </tbody>
  </table>

<?php else: ?>
  <h1>Not found</h1>
<?php endif; ?>
</main>
<footer class="wrap">
  <span>AIOpsKit 1.0 · <a href="?app=overview">Overview</a></span>
  <span>No telemetry. Data stays in <code>storage/</code>.</span>
</footer>
</body>
</html>
