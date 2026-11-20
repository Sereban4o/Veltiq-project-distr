<?php

namespace App\Services\Ai;

use App\Models\User;
use App\Services\Search\GlobalSearchService;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * Retrieval-augmented assistant over the knowledge base and tasks.
 *
 * Retrieval reuses {@see GlobalSearchService} (Meilisearch candidates re-checked
 * against per-user ACLs, with a database fallback), so the assistant can never
 * surface content the asking user is not allowed to see. Generation is delegated
 * to a local Ollama server.
 */
class AiAssistant
{
    /** Types searched by default when the caller does not specify any. */
    public const DEFAULT_TYPES = ['pages', 'issues'];

    /** Types the assistant is allowed to retrieve from. */
    public const ALLOWED_TYPES = ['pages', 'issues', 'files', 'mail'];

    public function __construct(private readonly GlobalSearchService $search)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('ai.enabled') && (string) config('ai.url') !== '';
    }

    public function model(): string
    {
        return (string) config('ai.model');
    }

    /**
     * Answer a question from the retrieval context.
     *
     * @param  list<string>  $types
     * @return array{answer:string, sources:list<array<string,mixed>>, model:string}
     */
    public function ask(User $user, string $question, array $types = []): array
    {
        $types = $this->normalizeTypes($types);
        $maxSources = max(1, (int) config('ai.max_sources', 6));

        $groups = $this->search->search($user, $question, $types, $maxSources, 1);
        $sources = $this->flatten($types, $groups);

        // The full question rarely matches a substring search (e.g. the DB
        // fallback when Meilisearch is off): retry with the significant words.
        if ($sources === []) {
            $keywords = $this->keywords($question);

            if ($keywords !== '' && $keywords !== $question) {
                $groups = $this->search->search($user, $keywords, $types, $maxSources, 1);
                $sources = $this->flatten($types, $groups);
            }
        }

        $sources = array_slice($sources, 0, $maxSources);

        return [
            'answer' => $this->complete($question, $sources),
            'sources' => $sources,
            'model' => $this->model(),
        ];
    }

    /**
     * @param  list<string>  $types
     * @param  array<string, array{total:int, hits:list<array>}>  $groups
     * @return list<array<string,mixed>>
     */
    private function flatten(array $types, array $groups): array
    {
        $sources = [];

        foreach ($types as $type) {
            foreach ($groups[$type]['hits'] ?? [] as $hit) {
                $sources[] = [
                    'type' => $type,
                    'id' => (int) $hit['id'],
                    'title' => (string) $hit['title'],
                    'url' => (string) ($hit['url'] ?? ''),
                    'snippet' => (string) ($hit['snippet'] ?? ''),
                ];
            }
        }

        return $sources;
    }

    /** Pick the most significant word from a natural-language question. */
    private function keywords(string $question): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($question)) ?: [];
        $words = array_values(array_filter(
            $words,
            fn ($word) => mb_strlen($word) >= 3,
        ));

        if ($words === []) {
            return '';
        }

        usort($words, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return $words[0];
    }

    /**
     * @param  list<array<string,mixed>>  $sources
     */
    public function complete(string $question, array $sources): string
    {
        return $this->chat($this->systemPrompt(), $this->userPrompt($question, $sources));
    }

    /** Summarize a knowledgebase page the user is allowed to read. */
    public function summarize(string $title, string $text): string
    {
        $limit = max(500, (int) config('ai.max_context_chars', 6000));
        $body = mb_substr($text, 0, $limit);
        $system = 'Ты — редактор корпоративной базы знаний. Сделай краткое резюме текста '
            . 'на русском: 3–6 пунктов, только ключевые факты и решения, без воды.';

        return $this->chat($system, "Заголовок: {$title}\n\nТекст:\n{$body}");
    }

    /** Generate a draft for a task or an email from a short prompt. */
    public function draft(string $kind, string $prompt): string
    {
        $system = match ($kind) {
            'task' => 'Ты помогаешь формулировать задачи. На основе запроса верни текст: '
                . 'первая строка — короткое название, затем описание с критериями готовности. Без лишних пояснений.',
            'mail' => 'Ты пишешь деловые письма на русском. На основе запроса верни только тело письма, '
                . 'вежливо и по делу, без темы и подписи.',
            default => throw new InvalidArgumentException('Неизвестный тип черновика: ' . $kind),
        };

        return $this->chat($system, $prompt);
    }

    private function chat(string $system, string $user): string
    {
        $response = Http::timeout((int) config('ai.timeout', 120))
            ->acceptJson()
            ->post($this->endpoint(), [
                'model' => $this->model(),
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'stream' => false,
                'options' => ['temperature' => 0.2],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Ollama HTTP ' . $response->status());
        }

        $answer = trim((string) ($response->json('message.content') ?? ''));

        if ($answer === '') {
            throw new RuntimeException('Пустой ответ модели');
        }

        return $answer;
    }

    /**
     * @param  list<array<string,mixed>>  $sources
     */
    private function userPrompt(string $question, array $sources): string
    {
        if ($sources === []) {
            return "Контекст пуст.\n\nВопрос: {$question}";
        }

        $context = $this->buildContext($sources);

        return "Контекст:\n{$context}\n\nВопрос: {$question}";
    }

    /**
     * @param  list<array<string,mixed>>  $sources
     */
    private function buildContext(array $sources): string
    {
        $limit = max(500, (int) config('ai.max_context_chars', 6000));
        $parts = [];
        $length = 0;

        foreach ($sources as $index => $source) {
            $chunk = sprintf('[%d] %s (тип: %s)\n%s', $index + 1, $source['title'], $source['type'], $source['snippet']);
            $length += mb_strlen($chunk);

            if ($length > $limit && $parts !== []) {
                break;
            }

            $parts[] = $chunk;
        }

        return implode("\n\n", $parts);
    }

    private function systemPrompt(): string
    {
        return 'Ты — ассистент корпоративной базы знаний и задач. '
            . 'Отвечай кратко и по делу, ТОЛЬКО на основе предоставленного контекста. '
            . 'Ссылайся на источники номерами в квадратных скобках, например [1]. '
            . 'Если в контексте нет ответа, честно скажи, что данных недостаточно. '
            . 'Отвечай на языке вопроса.';
    }

    private function endpoint(): string
    {
        return rtrim((string) config('ai.url'), '/') . '/api/chat';
    }

    /**
     * @param  list<string>  $types
     * @return list<string>
     */
    private function normalizeTypes(array $types): array
    {
        $types = array_values(array_intersect($types, self::ALLOWED_TYPES));

        return $types !== [] ? $types : self::DEFAULT_TYPES;
    }
}
