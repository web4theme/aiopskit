# AIOpsKit

Ten self-hosted AI operations tools in plain PHP. No Composer, no npm, no cloud
account, no telemetry.

| Tool | What it does |
|---|---|
| **Odoo MCP Server** | Read and write Odoo from Cursor, Claude Desktop or any MCP client. Read-only by default. |
| **AnyPHP MCP Adapter** | Turn any PHP script or command into an MCP tool. |
| **MCP Gateway** | One authenticated JSON-RPC endpoint for several MCP servers, with per-token tool ACL. |
| **TokenLedger** | Token and cost accounting per project and model, with monthly budgets. |
| **AI Usage Dashboard** | Server-rendered spend charts (inline SVG, no JavaScript). |
| **Prompt Audit Log** | Store prompts/responses with PII redaction. |
| **Agent Guard** | Human approval and a one-time token before an agent writes. |
| **Embedding Cache** | Cache embeddings by content hash and cut RAG cost. |
| **LLM Bridge** | OpenAI-compatible endpoint with provider failover and request logging. |
| **DataForge** | Clean, dedupe and map CSV/JSON for AI ingestion. |

## Requirements

PHP 8.1+ with `pdo_sqlite`, `curl`, `json`, `mbstring`, `dom`. SQLite only.

## Install

```bash
git clone https://github.com/web4theme/aiopskit.git
cd aiopskit
mkdir -p storage && chmod 775 storage
php bin/aiopskit help
php -S 127.0.0.1:8080 -t public   # web UI
```

## Odoo MCP in 30 seconds

```bash
export ODOO_URL=https://odoo.example.com
export ODOO_DB=prod
export ODOO_USER=api
export ODOO_PASSWORD=...
export ODOO_MODELS=res.partner,sale.order
export ODOO_ALLOW_WRITE=0
php bin/aiopskit odoo-mcp check
php bin/aiopskit odoo-mcp serve
```

MCP client config:

```json
{
  "mcpServers": {
    "odoo": {
      "command": "php",
      "args": ["/absolute/path/to/aiopskit/bin/aiopskit", "odoo-mcp", "serve"],
      "env": { "ODOO_URL": "https://odoo.example.com", "ODOO_DB": "prod", "ODOO_USER": "api", "ODOO_PASSWORD": "..." }
    }
  }
}
```

Tools: `odoo_search_read`, `odoo_read`, `odoo_fields_get`, `odoo_models`,
`odoo_create`, `odoo_write`, `odoo_unlink`, `odoo_execute_kw`.

Writes require `ODOO_ALLOW_WRITE=1` **and** the model in `ODOO_MODELS`. Every
call is appended to `storage/odoo-mcp-audit.jsonl`.

## Security

- The web UI has no built-in login. Put it behind HTTP auth, a VPN or localhost.
- Deny web access to `storage/` and `config/`.
- AnyPHP MCP executes configured commands; treat `config/mcp-tools.php` as code.
- Gateway tokens are the only access control; use HTTPS.

## Tests

```bash
php tests/run.php
```

The test suite is pure PHP + temporary SQLite and covers the ten tools,
the MCP protocol handlers, redaction rules, budgets, approvals, CSV parsing
and the bridge failover.

## License

Free for one production site. Team license (unlimited sites, 12 months of
updates, priority support) at <https://web4theme.com/aiops/>.

## Limitations

Single-writer SQLite; no streaming in the bridge; regex-based redaction; Odoo
tested on 19/20 JSON-RPC only; the UI is server-rendered PHP.
