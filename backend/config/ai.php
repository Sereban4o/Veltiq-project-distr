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

    // How many retrieved chunks are fed to the model as context. The budget is
    // shared by every section (docs, tasks, files, mail, calendar, CRM,
    // timesheet, employees), so 8+ keeps a single section from crowding out
    // the rest.
    'max_sources' => (int) env('AI_MAX_SOURCES', 8),

    // Hard cap on the context size (characters) to keep prompts small.
    'max_context_chars' => (int) env('AI_MAX_CONTEXT_CHARS', 6000),

    // How many previous messages are replayed as chat history (0 = stateless).
    'history_messages' => (int) env('AI_HISTORY_MESSAGES', 6),

    // Conversations inactive for longer than this are pruned.
    'retention_days' => (int) env('AI_RETENTION_DAYS', 180),

    /*
    |--------------------------------------------------------------------------
    | Web fallback (SearXNG)
    |--------------------------------------------------------------------------
    |
    | Used only when the internal search finds nothing. Only the user's
    | question is sent, and only after an administrator opts in: by default
    | nothing leaves the infrastructure.
    |
    */

    'web_enabled' => (bool) filter_var(env('AI_WEB_ENABLED', false), FILTER_VALIDATE_BOOL),

    // Base URL of a SearXNG instance (e.g. http://searxng:8080). It usually
    // lives on the internal network, so private addresses are allowed here:
    // the address is set by an administrator, not by users.
    'web_url' => rtrim((string) env('AI_WEB_URL', ''), '/'),

    // How long (seconds) to wait for the external engine.
    'web_timeout' => (int) env('AI_WEB_TIMEOUT', 8),

    // How many external results are fed to the model as context.
    'web_results' => (int) env('AI_WEB_RESULTS', 3),
];
