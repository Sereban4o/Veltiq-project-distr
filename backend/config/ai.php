<?php

$url = (string) env('OLLAMA_URL', '');
$enabledEnv = env('AI_ENABLED');

/**
 * Env lists are comma/space separated ("pages,issues") — the admin UI stores
 * the same values as JSON in the settings table.
 *
 * @return list<string>
 */
$list = static function (?string $raw): array {
    if ($raw === null || trim($raw) === '') {
        return [];
    }

    $parts = preg_split('/[\s,]+/', $raw) ?: [];

    return array_values(array_unique(array_filter(
        array_map(static fn (mixed $value): string => trim((string) $value), $parts),
        static fn (string $value): bool => $value !== '',
    )));
};

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

    // Whether answers expose the source list in the dialog. Citations like [1]
    // stay in the text either way: this only hides the "Источники" block.
    'show_sources' => (bool) filter_var(env('AI_SHOW_SOURCES', true), FILTER_VALIDATE_BOOL),

    // Conversations inactive for longer than this are pruned.
    'retention_days' => (int) env('AI_RETENTION_DAYS', 180),

    /*
    |--------------------------------------------------------------------------
    | Sections the assistant may read
    |--------------------------------------------------------------------------
    |
    | An administrator picks the sections in the admin UI (stored as JSON in
    | the settings table, which wins over these defaults). An empty list means
    | "every section", so an unconfigured install keeps working as before.
    |
    | Values match AiRetriever::TYPES; chat is deliberately not searchable.
    |
    */

    'sections' => $list(env('AI_SECTIONS')),

    /*
    |--------------------------------------------------------------------------
    | Roles allowed to use the assistant
    |--------------------------------------------------------------------------
    |
    | Role names (App\Models\Role), e.g. "admin,manager". An empty list means
    | every authenticated user. Retrieval stays permission-aware either way.
    |
    */

    'roles' => $list(env('AI_ROLES')),

    /*
    |--------------------------------------------------------------------------
    | Web fallback (search engine or direct page reading)
    |--------------------------------------------------------------------------
    |
    |  fetch    — download the http(s) links the question itself mentions
    |             (allowlisted hosts only, no search engine involved);
    |  searxng  — ask a self-hosted SearXNG instance, question text only;
    |  both     — try the mentioned links first, then fall back to search.
    |
    | Off by default: with the flag cleared no outbound request is made at all.
    |
    */

    'web_enabled' => (bool) filter_var(env('AI_WEB_ENABLED', false), FILTER_VALIDATE_BOOL),

    // Which mechanism the fallback uses: fetch, searxng or both.
    'web_provider' => (string) env('AI_WEB_PROVIDER', 'fetch'),

    // Base URL of the SearXNG instance (e.g. http://searxng:8080). It lives on
    // the internal network, so private addresses are allowed here: the address
    // is set by an administrator, not by users.
    'web_url' => rtrim((string) env('AI_WEB_URL', ''), '/'),

    // Hosts that may be read (fetch mode), e.g. "docs.example.com,example.org".
    // A bare domain also allows its subdomains; IP literals must be public.
    'web_domains' => $list(env('AI_WEB_DOMAINS')),

    // How long (seconds) to wait for one page or one search response.
    'web_timeout' => (int) env('AI_WEB_TIMEOUT', 8),

    // How many external sources are used: links fetched / results taken.
    'web_results' => (int) env('AI_WEB_RESULTS', 3),

    // Hard cap on the extracted text of one fetched page (characters).
    'web_max_chars' => (int) env('AI_WEB_MAX_CHARS', 4000),
];
