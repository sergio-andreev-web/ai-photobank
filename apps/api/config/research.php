<?php

return [
    'base_url' => env('WEB_RESEARCH_BASE_URL', 'http://host.docker.internal:8002'),
    'token' => env('WEB_RESEARCH_TOKEN', ''),
    'timeout' => 190,
    'providers' => ['codex_cli', 'claude_cli', 'openai_api', 'anthropic_api', 'ollama'],
];
