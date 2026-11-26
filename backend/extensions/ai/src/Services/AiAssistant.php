<?php

namespace App\Extensions\Ai\Services;

use App\Extensions\Ai\Retrieval\AiRetriever;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * Retrieval-augmented assistant over every section of the product.
 *
 * Retrieval is delegated to {@see AiRetriever}, which fans out over the
 * knowledge base, tasks, files, mail, calendar, CRM, timesheet and the employee
 * directory, applying the same visibility rules as each section's own API.
 * Generation is delegated to a local Ollama server.
 */
class AiAssistant
{
    /** Types searched by default when the caller does not specify any. */
    public const DEFAULT_TYPES = AiRetriever::TYPES;

    /** Types the assistant is allowed to retrieve from (chat is excluded). */
    public const ALLOWED_TYPES = AiRetriever::TYPES;

    public function __construct(
        private readonly AiRetriever $retriever,
        private readonly WebSearch $web,
    ) {
    }

    public function enabled(): bool
    {
        return $this->enabledFlag() && $this->url() !== '';
    }

    public function model(): string
    {
        return (string) $this->setting('ai_model', config('ai.model'));
    }

    /** How many previous messages are replayed as chat history. */
    public function historyWindow(): int
    {
        $value = (int) $this->setting('ai_history_messages', config('ai.history_messages', 6));

        return max(0, min(50, $value));
    }

    /**
     * Answer a question from the retrieval context.
     *
     * @param  list<string>  $types
     * @param  list<array{role:string, content:string}>  $history
     * @return array{answer:string, sources:list<array<string,mixed>>, model:string}
     */
    public function ask(User $user, string $question, array $types = [], array $history = []): array
    {
        $types = $this->normalizeTypes($types);
        $maxSources = max(1, (int) $this->setting('ai_max_sources', config('ai.max_sources', 8)));

        $sources = $this->retriever->search($user, $question, $types, $maxSources);

        // Nothing was found inside the product: only then, and only with an
        // explicit administrator opt-in, ask the external engine — with the
        // question text alone.
        if ($sources === []) {
            $sources = $this->web->search($question, $maxSources);
        }

        return [
            'answer' => $this->complete($user, $question, $sources, $history),
            'sources' => $sources,
            'model' => $this->model(),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $sources
     * @param  list<array{role:string, content:string}>  $history
     */
    public function complete(User $user, string $question, array $sources, array $history = []): string
    {
        return $this->chatMessages($this->buildMessages($user, $question, $sources, $history));
    }

    /** Whether the answer is built from the external web fallback. */
    private function usedWeb(array $sources): bool
    {
        foreach ($sources as $source) {
            if (($source['type'] ?? '') === 'web') {
                return true;
            }
        }

        return false;
    }

    /** Summarize a knowledgebase page the user is allowed to read. */
    public function summarize(string $title, string $text): string
    {
        $limit = max(500, (int) $this->setting('ai_max_context_chars', config('ai.max_context_chars', 6000)));
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
        return $this->chatMessages([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ]);
    }

    /**
     * @param  list<array{role:string, content:string}>  $messages
     */
    private function chatMessages(array $messages): string
    {
        $response = Http::timeout((int) $this->setting('ai_timeout', config('ai.timeout', 120)))
            ->acceptJson()
            ->post($this->endpoint(), [
                'model' => $this->model(),
                'messages' => $messages,
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
     * System prompt (persona + colleagues), replayed history (budget-capped)
     * and the current context+question. Retrieval itself only ever sees the
     * current question.
     *
     * @param  list<array<string,mixed>>  $sources
     * @param  list<array{role:string, content:string}>  $history
     * @return list<array{role:string, content:string}>
     */
    private function buildMessages(User $user, string $question, array $sources, array $history): array
    {
        $maxContext = max(500, (int) $this->setting('ai_max_context_chars', config('ai.max_context_chars', 6000)));

        $system = $this->systemPrompt(
            $user,
            $this->retriever->directory($user, $question),
            min(2000, intdiv($maxContext, 3)),
            $this->usedWeb($sources),
        );

        $messages = [['role' => 'system', 'content' => $system]];

        if ($history !== []) {
            $perMessage = max(200, intdiv(intdiv($maxContext, 3), max(1, count($history))));

            foreach ($history as $entry) {
                $content = trim((string) ($entry['content'] ?? ''));

                if ($content === '') {
                    continue;
                }

                $messages[] = [
                    'role' => ($entry['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user',
                    'content' => mb_substr($content, 0, $perMessage),
                ];
            }
        }

        $messages[] = [
            'role' => 'user',
            'content' => $this->userPrompt($question, $sources, $maxContext - mb_strlen($system)),
        ];

        return $messages;
    }

    /**
     * @param  list<array<string,mixed>>  $sources
     */
    private function userPrompt(string $question, array $sources, int $budget): string
    {
        if ($sources === []) {
            return "Контекст пуст.\n\nВопрос: {$question}";
        }

        $context = $this->buildContext($sources, $budget);

        return "Контекст:\n{$context}\n\nВопрос: {$question}";
    }

    /**
     * Every retrieved source stays in the prompt (its snippet is shortened
     * instead of dropping the source) because the model refers to sources by
     * the same [n] numbers the API returns.
     *
     * @param  list<array<string,mixed>>  $sources
     */
    private function buildContext(array $sources, int $budget): string
    {
        $perSource = max(240, intdiv(max(500, $budget), max(1, count($sources))));
        $parts = [];

        foreach ($sources as $index => $source) {
            $snippet = trim((string) ($source['snippet'] ?? ''));

            if (mb_strlen($snippet) > $perSource) {
                $snippet = mb_substr($snippet, 0, $perSource) . '…';
            }

            $line = sprintf('[%d] %s (тип: %s)', $index + 1, (string) $source['title'], (string) $source['type']);

            // External results need their address: the model cites it in the answer.
            if (($source['type'] ?? '') === 'web' && (string) ($source['url'] ?? '') !== '') {
                $line .= "\n" . $source['url'];
            }

            $parts[] = $line . ($snippet !== '' ? "\n" . $snippet : '');
        }

        return implode("\n\n", $parts);
    }

    /**
     * Persona, addressing rule and the compact employee directory.
     *
     * @param  array{items: list<array{id:int, name:string, role:?string, email:string}>, truncated: bool}  $directory
     */
    private function systemPrompt(User $user, array $directory, int $budget, bool $webUsed = false): string
    {
        $name = $this->retriever->displayName($user);
        $role = $user->role?->name;

        $lines = [
            'Ты — ИИ-ассистент корпоративной системы: база знаний, задачи, файлы, почта, календарь, CRM и табель.',
            'Сейчас с тобой общается ' . $name . ($role ? ", роль: {$role}" : '') . '.',
            "Обращайся к пользователю по имени: «{$name}».",
            'Правила:',
            '- Отвечай кратко и по делу, ТОЛЬКО на основе предоставленного контекста и справочника сотрудников.',
            '- Ссылайся на источники номерами в квадратных скобках, например [1].',
            '- Не выдумывай имена, суммы, даты, статусы и ссылки. Если данных недостаточно — честно скажи об этом.',
            '- Личные сообщения чата тебе недоступны; не предполагай их содержимое.',
            '- Отвечай на языке вопроса.',
        ];

        if ($webUsed) {
            $lines[] = 'Внутренних данных по вопросу не нашлось: контекст ниже получен из интернета (тип: web).';
            $lines[] = 'Скажи пользователю, что ответ основан на внешних источниках, ссылайся на них номерами [n] '
                . 'и не добавляй факты или ссылки, которых нет в сниппетах.';
        }

        if ($directory['items'] !== []) {
            $lines[] = 'Сотрудники' . ($directory['truncated'] ? ' (справочник показан частично)' : '') . ':';

            foreach ($directory['items'] as $employee) {
                $line = '— ' . $employee['name'];

                if (($employee['role'] ?? null) !== null && $employee['role'] !== '') {
                    $line .= ", {$employee['role']}";
                }

                if (($employee['email'] ?? '') !== '') {
                    $line .= ", {$employee['email']}";
                }

                if (mb_strlen(implode("\n", $lines) . "\n" . $line) > $budget) {
                    break;
                }

                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    private function endpoint(): string
    {
        return $this->url() . '/api/chat';
    }

    private function url(): string
    {
        return rtrim((string) $this->setting('ai_url', config('ai.url')), '/');
    }

    private function enabledFlag(): bool
    {
        $raw = Setting::get('ai_enabled');

        if ($raw === null || $raw === '') {
            return (bool) config('ai.enabled');
        }

        return in_array($raw, ['1', 'true', 'on', 'yes'], true);
    }

    private function setting(string $key, mixed $default): mixed
    {
        $value = Setting::get($key);

        return ($value === null || $value === '') ? $default : $value;
    }

    /**
     * @param  list<string>  $types
     * @return list<string>
     */
    private function normalizeTypes(array $types): array
    {
        $types = array_values(array_unique(array_intersect($types, self::ALLOWED_TYPES)));

        return $types !== [] ? $types : self::DEFAULT_TYPES;
    }
}
