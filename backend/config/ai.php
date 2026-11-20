<?php

$url = (string) env('OLLAMA_URL', '');
$enabledEnv = env('AI_ENABLED');

return [
    /*
    |--------------------------------------------------------------------------
    | Local AI assistant (Ollama)
    |--------------------------------------------------------------------------
    |
    | Powers the "ask the knowledge base" assistant. Retrieval runs through the
    | existing permission-aware search (Meilisearch + DB fallback); generation
    | is done by a local Ollama server so no data leaves the infrastructure.
    |
    */

    // Enabled explicitly, or implicitly once an Ollama URL is configured.
    'enabled' => $enabledEnv === null
        ? ($url !== '')
        : (bool) filter_var($enabledEnv, FILTER_VALIDATE_BOOL),

    // Base URL of the Ollama server (e.g. http://ollama:11434).
    'url' => rtrim($url, '/'),

    // Chat model used for answers (must be pulled on the Ollama server).
    'model' => (string) env('AI_MODEL', 'llama3.1'),

    // How long (seconds) to wait for a completion.
    'timeout' => (int) env('AI_TIMEOUT', 120),

    // How many retrieved chunks are fed to the model as context.
    'max_sources' => (int) env('AI_MAX_SOURCES', 6),

    // Hard cap on the context size (characters) to keep prompts small.
    'max_context_chars' => (int) env('AI_MAX_CONTEXT_CHARS', 6000),
];
