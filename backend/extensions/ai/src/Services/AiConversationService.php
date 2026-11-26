<?php

namespace App\Extensions\Ai\Services;

use App\Extensions\Ai\Models\AiConversation;
use App\Extensions\Ai\Models\AiMessage;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owns every write to the assistant's conversation history: the service above
 * stays stateless and only generates answers.
 */
class AiConversationService
{
    /** Owner-scoped lookup: another user's conversation must look missing. */
    public function find(User $user, int $id): AiConversation
    {
        return AiConversation::query()->where('user_id', $user->id)->findOrFail($id);
    }

    public function start(User $user): AiConversation
    {
        return AiConversation::create([
            'user_id' => $user->id,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Previous messages replayed to the model as chat history (oldest first).
     * Failed generations carry no content and are skipped.
     *
     * @return list<array{role:string, content:string}>
     */
    public function history(AiConversation $conversation, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        return $conversation->messages()
            ->whereNotNull('content')
            ->where('content', '<>', '')
            ->orderByDesc('id')
            ->limit(min($limit, 50))
            ->get(['role', 'content'])
            ->reverse()
            ->map(fn (AiMessage $message) => [
                'role' => $message->role === AiMessage::ROLE_ASSISTANT ? 'assistant' : 'user',
                'content' => (string) $message->content,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array{answer:string, sources:list<array<string,mixed>>, model:string}  $result
     */
    public function record(AiConversation $conversation, string $question, array $result, ?int $durationMs = null): void
    {
        DB::transaction(function () use ($conversation, $question, $result, $durationMs): void {
            $conversation->messages()->create([
                'role' => AiMessage::ROLE_USER,
                'content' => $question,
            ]);

            $conversation->messages()->create([
                'role' => AiMessage::ROLE_ASSISTANT,
                'content' => $result['answer'],
                'sources' => $result['sources'],
                'model' => $result['model'],
                'duration_ms' => $durationMs,
            ]);

            $this->touch($conversation, $question);
        });
    }

    /** Keeps the question after a failed generation; it is never replayed. */
    public function recordFailure(AiConversation $conversation, string $question, string $error): void
    {
        DB::transaction(function () use ($conversation, $question, $error): void {
            $conversation->messages()->create([
                'role' => AiMessage::ROLE_USER,
                'content' => $question,
            ]);

            $conversation->messages()->create([
                'role' => AiMessage::ROLE_ASSISTANT,
                'content' => null,
                'error' => $error,
            ]);

            $this->touch($conversation, $question);
        });
    }

    public function delete(AiConversation $conversation): void
    {
        // Messages go away through the foreign key's cascade.
        $conversation->delete();
    }

    /** Retention prune; returns the number of conversations removed. */
    public function prune(DateTimeInterface $cutoff): int
    {
        return AiConversation::query()->where('last_activity_at', '<', $cutoff)->delete();
    }

    private function touch(AiConversation $conversation, string $question): void
    {
        $conversation->last_activity_at = now();

        if ($conversation->title === null || $conversation->title === '') {
            $conversation->title = Str::limit($question, 100);
        }

        $conversation->save();
    }
}
