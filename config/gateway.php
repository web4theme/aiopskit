<?php
// MCP Gateway: aggregate several MCP servers behind one authenticated endpoint.
// A server is either a stdio command or an HTTP MCP URL.
return [
    'servers' => [
        'anyphp' => [
            'command' => PHP_BINARY,
            'args' => [dirname(__DIR__) . '/bin/aiopskit', 'anyphp-mcp', 'serve'],
            'timeout' => 20,
        ],
        // 'remote' => ['url' => 'https://example.com/mcp', 'token' => '...'],
    ],
    'tokens' => [
        // Replace before exposing the gateway.
        'change-me-please' => ['allow' => ['anyphp.*']],
    ],
];
