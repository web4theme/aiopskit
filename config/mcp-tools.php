<?php
// AnyPHP MCP Adapter: every entry becomes one MCP tool.
// Arguments are delivered as JSON on stdin and in AIOPSKIT_TOOL_ARGS.
return [
    [
        'name' => 'server_time',
        'description' => 'Return the current UTC time.',
        'command' => 'php',
        'args' => ['-r', 'echo gmdate("c");'],
        'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
        'timeout' => 5,
    ],
    [
        'name' => 'git_status',
        'description' => 'Show git status for a configured repository.',
        'command' => 'git',
        'args' => ['status', '--short'],
        'cwd' => dirname(__DIR__),
        'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
        'timeout' => 10,
    ],
];
