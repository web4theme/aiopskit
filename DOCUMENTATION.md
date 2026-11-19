# AIOpsKit 1.0 — documentation

AIOpsKit is a set of ten small, self-hosted tools for teams that run AI in
production. Everything is plain PHP 8.1+ with SQLite. There is no Composer, no
npm, no build step, no external service and no telemetry.

The tools share one code base and one CLI. You can install the whole kit or
copy a single app folder; each app only needs `src/Core.php`, its own file in
`src/apps/`, and the shared `bin/aiopskit` dispatcher.

---

## 1. Requirements

| Item | Requirement |
|---|---|
| PHP | 8.1 or newer, CLI + web SAPI |
| Extensions | `pdo_sqlite`, `curl`, `json`, `mbstring`, `dom` (for any HTML work) |
| Database | SQLite only (created automatically) |
| Web server | Any PHP-capable server. `php -S` is enough for a demo. |
| Disk | A few MB; logs grow with use |

Nothing else. There is no package manager step.

## 2. Install

```bash
unzip aiopskit-1.0.0.zip
cd aiopskit
cp config/config.sample.php config/config.php   # optional
mkdir -p storage && chmod 775 storage
php bin/aiopskit help
```

Run the web UI:

```bash
php -S 127.0.0.1:8080 -t public
# open http://127.0.0.1:8080
```

For Apache/Nginx, point the document root at `public/` and deny direct access
to `storage/` and `config/`. The bundled `storage/.htaccess` does this on
Apache; on Nginx add:

```nginx
location ~ ^/(storage|config)/ { deny all; }
```

## 3. The ten tools

### 3.1 Odoo MCP Server (`odoo-mcp`)

Speaks MCP over stdio. Point Cursor, Claude Desktop or any MCP client at it.

```bash
php bin/aiopskit odoo-mcp check   # test the connection
php bin/aiopskit odoo-mcp tools  # list tool names
php bin/aiopskit odoo-mcp serve   # MCP over stdio
```

Environment:

```bash
ODOO_URL=https://odoo.example.com
ODOO_DB=prod
ODOO_USER=api
ODOO_PASSWORD=…
ODOO_MODELS=res.partner,sale.order   # empty = all models readable
ODOO_ALLOW_WRITE=0                   # 1 enables create/write/unlink
```

Tools: `odoo_search_read`, `odoo_read`, `odoo_fields_get`, `odoo_models`,
`odoo_create`, `odoo_write`, `odoo_unlink`, `odoo_execute_kw`.

Safety rules:

- Reads work on any model unless `ODOO_MODELS` is set.
- Writes require `ODOO_ALLOW_WRITE=1` **and** the model in `ODOO_MODELS`.
- Limits: `limit` is capped at 500 rows, `fields` at 80 columns.
- Every call is appended to `storage/odoo-mcp-audit.jsonl`.

### 3.2 AnyPHP MCP Adapter (`anyphp-mcp`)

Turns configured commands into MCP tools. Edit `config/mcp-tools.php`:

```php
return [[
    'name' => 'server_time',
    'description' => 'Return the current UTC time.',
    'command' => 'php',
    'args' => ['-r', 'echo gmdate("c");'],
    'timeout' => 5,
]];
```

```bash
php bin/aiopskit anyphp-mcp serve
php bin/aiopskit anyphp-mcp list
php bin/aiopskit anyphp-mcp call server_time
```

Arguments are passed as JSON on stdin and in `AIOPSKIT_TOOL_ARGS`. Commands are
executed without a shell; only `env` keys matching `[A-Z_][A-Z0-9_]*` are passed.
Output is truncated at 64 KB. This is an adapter, not a sandbox: only configure
commands you would run yourself.

### 3.3 MCP Gateway (`gateway`)

One authenticated JSON-RPC endpoint in front of several MCP servers. Edit
`config/gateway.php`:

```php
return [
    'servers' => [
        'anyphp' => [
            'command' => PHP_BINARY,
            'args' => [__DIR__ . '/../bin/aiopskit', 'anyphp-mcp', 'serve'],
        ],
        'remote' => ['url' => 'https://example.com/mcp', 'token' => '…'],
    ],
    'tokens' => [
        'change-me-please' => ['allow' => ['anyphp.*']],
    ],
];
```

```bash
php bin/aiopskit gateway serve --port=8787
curl -s http://127.0.0.1:8787/mcp \
  -H 'Authorization: Bearer change-me-please' \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

Tool names are namespaced as `server.tool`. A token may allow exact names,
`server.*`, or `*`. Every call is written to `storage/gateway.sqlite`.

The gateway is HTTP + Bearer token. Put it behind HTTPS and a private network;
it does not implement OAuth.

### 3.4 TokenLedger (`ledger`)

```bash
php bin/aiopskit ledger record --project=acme --model=gpt-4o \
  --in=1200 --out=300 --cost=0.012 --latency=840
php bin/aiopskit ledger report --days=30
php bin/aiopskit ledger budget --project=acme --usd=50 --alert=80
php bin/aiopskit ledger budget --project=acme
php bin/aiopskit ledger export --days=30 --out=report.csv
```

Costs are stored as integer micro-USD. The budget check uses the current UTC
month. `budget` exits with code 1 when the budget is over.

### 3.5 AI Usage Dashboard (`dashboard`)

Reads the TokenLedger database and renders a self-contained page with inline
SVG. No JavaScript, no CDN.

```bash
php bin/aiopskit dashboard --days=30
php bin/aiopskit dashboard html --days=14
```

### 3.6 Prompt Audit Log (`audit`)

Stores prompts and responses after redacting emails, CN mobile numbers,
international phone numbers, CN ID numbers, common API key formats and
Luhn-valid card numbers. The original values are not written to disk.

```bash
php bin/aiopskit audit record --project=acme --prompt='mail a@b.com' --response='ok'
php bin/aiopskit audit redact --text='card 4111 1111 1111 1111'
php bin/aiopskit audit search --q=refund
php bin/aiopskit audit prune --days=30
php bin/aiopskit audit export --out=audit.jsonl
```

Redaction is pattern-based. It reduces accidental storage of sensitive data; it
is not a compliance guarantee and it does not understand arbitrary formats.

### 3.7 Agent Guard (`guard`)

A write action stays pending until a human approves it. Execution consumes a
one-time token.

```bash
php bin/aiopskit guard request --action=refund --payload='{"order":42}' --ttl=900
php bin/aiopskit guard approve --id=1 --actor=alex
php bin/aiopskit guard execute --id=1 --token=<token>
php bin/aiopskit guard list --status=pending
php bin/aiopskit guard expire
php bin/aiopskit guard sign --payload='{"a":1}' --secret=…
```

`execute` returns the approved payload. Your caller performs the action; the
guard does not run commands. HMAC helpers are available for callback checks.

### 3.8 Embedding Cache (`cache`)

```bash
php bin/aiopskit cache put --model=text-embedding-3-small --text='hello' --vector='[0.1,0.2]'
php bin/aiopskit cache get --model=text-embedding-3-small --text='hello'
php bin/aiopskit cache stats
php bin/aiopskit cache prune
```

The cache key is `sha256(model + "\0" + text)`. Vectors are stored as JSON, so
the cache is model-agnostic but not a vector index: it saves the call, not the
similarity search.

### 3.9 LLM Bridge (`bridge`)

OpenAI-compatible endpoint with provider failover. Edit `config/bridge.php`,
then:

```bash
php bin/aiopskit bridge providers
php bin/aiopskit bridge test --prompt='Say OK'
php bin/aiopskit bridge serve --port=8790
```

Clients call `http://127.0.0.1:8790/v1/chat/completions`. Providers are tried in
configuration order. A request is retried on the next provider when the HTTP
status is not 2xx or the response has no `choices`. Keys are never logged; each
attempt is written to `storage/bridge.sqlite` with provider, model, status and
latency.

The bridge forwards the JSON body unchanged except for the model mapping. It
does not rewrite prompts, count tokens or enforce quotas — use TokenLedger for
accounting.

### 3.10 DataForge (`dataforge`)

```bash
php bin/aiopskit dataforge stats --in=raw.csv
php bin/aiopskit dataforge clean --in=raw.csv --out=clean.csv \
  --dedupe=email --numbers=amount --dates=date --map=map.json
```

Handles UTF-8 BOM, `, ; \t |` delimiters, quoted fields and newlines inside
quotes. Number normalisation understands `1.234,56`, `(1,234.56)` and plain
thousands separators. Dates accept `YYYY-MM-DD`, `YYYY/MM/DD` and `DD/MM/YYYY`
(day-first is used when the first component is greater than 12).

The web page in `?app=dataforge` accepts pasted CSV.

## 4. Storage

| File | Written by |
|---|---|
| `storage/ledger.sqlite` | TokenLedger, Dashboard |
| `storage/prompt-audit.sqlite` | Prompt Audit Log |
| `storage/agent-guard.sqlite` | Agent Guard |
| `storage/embedding-cache.sqlite` | Embedding Cache |
| `storage/gateway.sqlite` | MCP Gateway audit |
| `storage/bridge.sqlite` | LLM Bridge log |
| `storage/odoo-mcp-audit.jsonl` | Odoo MCP Server |

Back up the `storage/` directory if the data matters. Nothing is sent anywhere.

## 5. Security notes

- The web UI has no authentication of its own. Put it behind HTTP auth, a VPN
  or a private network. Do not expose it to the public internet.
- The gateway token is the only access control. Use a long random value and
  HTTPS.
- The LLM Bridge holds provider keys in `config/bridge.php`. Keep that file out
  of version control and readable only by the PHP user.
- Odoo writes are off by default and require an explicit model allowlist.
- AnyPHP MCP executes configured commands. Treat `config/mcp-tools.php` as code.
- There is no multi-user mode, no roles and no audit of the web UI itself.

## 6. Limitations (read this before buying)

- Single-process SQLite. Fine for a team; not a distributed system.
- No queue, no retries for TokenLedger/Embedding Cache writes.
- No streaming in the LLM Bridge. It forwards non-streaming requests; a
  `stream: true` request is passed through and may not behave as clients expect.
- Odoo support is tested against Odoo 19/20 JSON-RPC. Older versions usually
  work but are not tested.
- Prompt redaction is regex-based and can miss unusual formats.
- Agent Guard records decisions; it cannot stop an agent that ignores it.
- The UI is server-rendered PHP with no JavaScript framework.

## 7. Troubleshooting

| Symptom | Check |
|---|---|
| `Odoo login failed` | URL, database name, user and password; the user must be able to log in to Odoo |
| MCP client shows no tools | run `php bin/aiopskit odoo-mcp tools`; check the client launches the absolute path |
| Gateway returns 401 | `Authorization: Bearer …` must match `config/gateway.php` |
| Bridge returns 502 | every provider failed; run `bridge test` and read `storage/bridge.sqlite` |
| SQLite `database is locked` | reduce concurrency; WAL is enabled but SQLite still serialises writes |
| Web UI is blank | check `php -S` log; point the document root at `public/` |

## 8. File layout

```
aiopskit/
├── bin/aiopskit          CLI dispatcher
├── public/               web UI, gateway and bridge routers
├── src/Core.php          config, db, http, cli, view helpers
├── src/apps/             ten apps, one file each
├── views/page.php        server-rendered UI
├── config/               samples and live config
├── storage/              SQLite, audit logs
└── DOCUMENTATION.md
```
