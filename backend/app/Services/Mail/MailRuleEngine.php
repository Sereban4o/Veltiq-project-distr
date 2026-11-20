<?php

namespace App\Services\Mail;

use App\Models\MailFilterRule;
use App\Models\MailMessage;
use App\Services\Core\CoreClient;
use Throwable;

/**
 * Evaluates user mail filter rules against a message.
 *
 * Conditions shape:
 * {
 *   "match": "all" | "any",
 *   "groups": [
 *     { "match": "all" | "any",
 *       "conditions": [ { "field": "from", "op": "contains", "value": "boss@" } ] }
 *   ]
 * }
 */
class MailRuleEngine
{
    public function __construct(private readonly CoreClient $core)
    {
    }

    /**
     * @param  array<string, mixed>  $conditions
     */
    public function matches(MailMessage $message, array $conditions): bool
    {
        $groups = [];
        foreach ((array) ($conditions['groups'] ?? []) as $group) {
            if (!is_array($group)) {
                continue;
            }
            $list = array_values(array_filter((array) ($group['conditions'] ?? []), 'is_array'));
            if ($list === []) {
                continue;
            }
            $groups[] = [
                'match' => ($group['match'] ?? 'all') === 'any' ? 'any' : 'all',
                'conditions' => $list,
            ];
        }

        if ($groups === []) {
            return false;
        }

        $root = ($conditions['match'] ?? 'all') === 'any' ? 'any' : 'all';

        foreach ($groups as $group) {
            $groupMatched = $this->evaluateGroup($message, $group);
            if ($root === 'any' && $groupMatched) {
                return true;
            }
            if ($root === 'all' && !$groupMatched) {
                return false;
            }
        }

        return $root === 'all';
    }

    /**
     * First active matching rule (ordered) resolves to its target folder id.
     */
    public function resolveFolderId(int $userId, MailMessage $message): ?int
    {
        $rules = MailFilterRule::where('user_id', $userId)
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        if ($rules->isEmpty()) {
            return null;
        }

        $matchedId = $this->matchRuleId($rules, $message);

        if ($matchedId === null) {
            return null;
        }

        $rule = $rules->firstWhere('id', $matchedId);

        return $rule !== null ? (int) $rule->action_target_folder_id : null;
    }

    /**
     * Which rule matches: the core service decides when possible, the local
     * implementation is the fallback.
     */
    private function matchRuleId($rules, MailMessage $message): ?int
    {
        // Go's RE2 differs from PCRE, so rules using `regex` stay in PHP.
        if (! $this->usesRegex($rules)) {
            $payload = $rules->map(fn (MailFilterRule $rule) => [
                'id' => (int) $rule->id,
                'conditions' => (object) ($rule->conditions ?? []),
            ])->all();

            $remote = $this->core->mailRuleResolve($payload, $this->snapshot($message));

            if ($remote !== false) {
                return $remote;
            }
        }

        foreach ($rules as $rule) {
            if (is_array($rule->conditions) && $this->matches($message, $rule->conditions)) {
                return (int) $rule->id;
            }
        }

        return null;
    }

    private function usesRegex($rules): bool
    {
        foreach ($rules as $rule) {
            foreach ((array) ($rule->conditions['groups'] ?? []) as $group) {
                foreach ((array) ($group['conditions'] ?? []) as $condition) {
                    if (is_array($condition) && ($condition['op'] ?? null) === 'regex') {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(MailMessage $message): array
    {
        return [
            'from_name' => (string) ($message->from_name ?? ''),
            'from_email' => (string) ($message->from_email ?? ''),
            'to' => array_values(array_map(static fn ($recipient): array => [
                'name' => is_array($recipient) ? (string) ($recipient['name'] ?? '') : '',
                'email' => is_array($recipient) ? (string) ($recipient['email'] ?? '') : '',
            ], (array) ($message->to_recipients ?? []))),
            'subject' => (string) ($message->subject ?? ''),
            'body_text' => (string) ($message->body_text ?? ''),
            'body_html' => (string) ($message->body_html ?? ''),
            'preview' => (string) ($message->preview ?? ''),
            'has_attachments' => (bool) $message->has_attachments,
        ];
    }

    /**
     * @param  array{match: string, conditions: array<int, array<string, mixed>>}  $group
     */
    private function evaluateGroup(MailMessage $message, array $group): bool
    {
        $any = $group['match'] === 'any';

        foreach ($group['conditions'] as $condition) {
            $matched = $this->evaluateCondition($message, $condition);
            if ($any && $matched) {
                return true;
            }
            if (!$any && !$matched) {
                return false;
            }
        }

        return !$any;
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private function evaluateCondition(MailMessage $message, array $condition): bool
    {
        $field = (string) ($condition['field'] ?? '');
        $op = (string) ($condition['op'] ?? 'contains');
        $value = (string) ($condition['value'] ?? '');

        if ($field === 'has_attachment') {
            $has = (bool) $message->has_attachments;

            return match ($op) {
                'equals' => $has === filter_var($value, FILTER_VALIDATE_BOOLEAN),
                default => $has,
            };
        }

        if ($value === '') {
            return false;
        }

        $haystack = $this->haystack($message, $field);

        return match ($op) {
            'regex' => $this->safeRegex($value, $haystack),
            'equals' => $this->equalsField($message, $field, $value),
            default => $this->containsCi($haystack, $value),
        };
    }

    private function haystack(MailMessage $message, string $field): string
    {
        return match ($field) {
            'from' => trim(
                ($message->from_name ?? '') . ' <' . ($message->from_email ?? '') . '> ' . ($message->from_email ?? ''),
            ),
            'to' => implode(', ', array_map(static function ($recipient): string {
                if (!is_array($recipient)) {
                    return '';
                }
                $name = trim((string) ($recipient['name'] ?? ''));
                $email = (string) ($recipient['email'] ?? '');

                return $name !== '' ? "{$name} <{$email}>" : $email;
            }, (array) ($message->to_recipients ?? []))),
            'subject' => (string) ($message->subject ?? ''),
            'body' => (string) ($message->body_text
                ?? ($message->body_html ? strip_tags($message->body_html) : ($message->preview ?? ''))),
            default => '',
        };
    }

    private function equalsField(MailMessage $message, string $field, string $value): bool
    {
        $needle = $this->lower(trim($value));
        $candidates = [];

        if ($field === 'from') {
            $candidates[] = (string) ($message->from_email ?? '');
            $candidates[] = trim(($message->from_name ?? '') . ' <' . ($message->from_email ?? '') . '>');
        } elseif ($field === 'to') {
            foreach ((array) ($message->to_recipients ?? []) as $recipient) {
                if (is_array($recipient)) {
                    $candidates[] = (string) ($recipient['email'] ?? '');
                    $candidates[] = trim(($recipient['name'] ?? '') . ' <' . ($recipient['email'] ?? '') . '>');
                }
            }
        } else {
            $candidates[] = $this->haystack($message, $field);
        }

        foreach ($candidates as $candidate) {
            if (trim($candidate) !== '' && $this->lower(trim($candidate)) === $needle) {
                return true;
            }
        }

        return false;
    }

    private function containsCi(string $haystack, string $needle): bool
    {
        return $needle !== '' && mb_stripos($haystack, $needle) !== false;
    }

    private function safeRegex(string $pattern, string $haystack): bool
    {
        if ($pattern === '') {
            return false;
        }

        set_error_handler(static fn (): bool => true);

        try {
            return preg_match($pattern, $haystack) === 1;
        } catch (Throwable) {
            return false;
        } finally {
            restore_error_handler();
        }
    }

    private function lower(string $value): string
    {
        return mb_strtolower($value);
    }
}
