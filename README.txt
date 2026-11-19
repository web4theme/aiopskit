AIOpsKit 1.0 — self-hosted AI operations in plain PHP
=====================================================

Ten tools, one code base, zero dependencies:
  odoo-mcp      Odoo MCP Server (read/write via MCP, audit log)
  anyphp-mcp    Turn any PHP command into an MCP tool
  gateway       Authenticated MCP gateway with per-tool ACL
  ledger        Token and cost ledger per project/model
  dashboard     Self-hosted spend dashboard (inline SVG)
  audit         Prompt/response log with PII redaction
  guard         Human approval queue for agent writes
  cache         Embedding cache for RAG
  bridge        OpenAI-compatible provider failover
  dataforge     CSV/JSON clean, dedupe, map

Requirements
------------
PHP 8.1+ with pdo_sqlite, curl, json, mbstring, dom.
No Composer. No npm. No database server.

Quick start
-----------
  unzip aiopskit-1.0.0.zip
  cd aiopskit
  mkdir -p storage && chmod 775 storage
  php bin/aiopskit help

  # web UI (no auth: keep it on localhost or behind HTTP auth)
  php -S 127.0.0.1:8080 -t public

  # Odoo MCP over stdio
  export ODOO_URL=https://odoo.example.com
  export ODOO_DB=prod
  export ODOO_USER=api
  export ODOO_PASSWORD=...
  php bin/aiopskit odoo-mcp check
  php bin/aiopskit odoo-mcp serve

Configuration
-------------
  cp config/config.sample.php config/config.php
  # edit config/mcp-tools.php, config/gateway.php, config/bridge.php as needed

Storage
-------
All data stays in storage/ (SQLite + JSONL). Back that folder up.
Deny web access to storage/ and config/ in your web server.

Security
--------
  - The web UI has no login. Use HTTP auth, a VPN or localhost only.
  - Odoo writes are off by default (ODOO_ALLOW_WRITE=0) and need ODOO_MODELS.
  - AnyPHP MCP executes configured commands; treat its config as code.
  - Gateway tokens are the only access control; use HTTPS.

License
-------
Free for one production site. Team license (unlimited sites, 12 months of
updates, priority support) from https://web4theme.com/aiops/

Docs: DOCUMENTATION.md
Tests: php tests/run.php (from the development tree)
