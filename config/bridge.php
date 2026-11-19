<?php
// LLM Bridge: OpenAI-compatible providers, tried in order.
// Never commit real keys. Use environment expansion in your own deployment.
return [
    'providers' => [
        [
            'name' => 'primary',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => getenv('OPENAI_API_KEY') ?: '',
            'models' => ['gpt-4o' => 'gpt-4o', 'gpt-4o-mini' => 'gpt-4o-mini'],
            'timeout' => 60,
            // set true for a local model server (Ollama, LM Studio, vLLM)
            'allow_private' => false,
        ],
        [
            'name' => 'backup',
            'base_url' => 'https://openrouter.ai/api/v1',
            'api_key' => getenv('OPENROUTER_API_KEY') ?: '',
            'models' => ['gpt-4o' => 'openai/gpt-4o'],
            'timeout' => 60,
        ],
    ],
];
