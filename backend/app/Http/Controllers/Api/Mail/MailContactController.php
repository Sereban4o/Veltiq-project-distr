<?php

namespace App\Http\Controllers\Api\Mail;

use App\Http\Controllers\Controller;
use App\Models\MailAccount;
use App\Models\MailContact;
use App\Models\MailMessage;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MailContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 25), 1), 100);

        $query = MailContact::query()->orderBy('name')->orderBy('email');

        $search = trim((string) ($request->input('search') ?? $request->input('q', '')));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('email', 'ilike', "%{$search}%")
                    ->orWhere('name', 'ilike', "%{$search}%");
            });
        }

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('mail_contacts', 'email')],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $contact = MailContact::create($validated + ['created_by' => Auth::id()]);

        return response()->json(['data' => $contact], 201);
    }

    /**
     * Suggest recipients from the shared address book and the user's own mail
     * history. Address-book matches win over historical ones.
     */
    public function suggest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:255'],
        ]);

        $needle = mb_strtolower(trim($validated['q']));
        $like = '%' . $needle . '%';

        $own = mb_strtolower((string) (MailAccount::where('user_id', Auth::id())->value('email')
            ?: Auth::user()->email
            ?: ''));

        $results = [];

        $contacts = MailContact::query()
            ->where(function ($q) use ($like) {
                $q->where('email', 'ilike', $like)->orWhere('name', 'ilike', $like);
            })
            ->orderBy('name')
            ->orderBy('email')
            ->limit(10)
            ->get();

        foreach ($contacts as $contact) {
            $email = mb_strtolower($contact->email);
            $results[$email] = [
                'email' => $email,
                'name' => $contact->name,
                'source' => 'contact',
                'count' => 0,
            ];
        }

        $messages = MailMessage::where('user_id', Auth::id())
            ->orderByDesc('id')
            ->limit(500)
            ->get(['id', 'from_email', 'from_name', 'to_recipients', 'cc_recipients']);

        foreach ($messages as $message) {
            $seen = [];

            $consider = function (?string $email, ?string $name) use (&$seen): void {
                $email = mb_strtolower(trim((string) $email));

                if ($email === '') {
                    return;
                }

                $name = trim((string) $name);
                if ($name !== '' || !array_key_exists($email, $seen)) {
                    $seen[$email] = $name !== '' ? $name : ($seen[$email] ?? null);
                }
            };

            $consider($message->from_email, $message->from_name);

            foreach ((array) ($message->to_recipients ?? []) as $recipient) {
                if (is_array($recipient)) {
                    $consider($recipient['email'] ?? null, $recipient['name'] ?? null);
                }
            }

            foreach ((array) ($message->cc_recipients ?? []) as $recipient) {
                if (is_array($recipient)) {
                    $consider($recipient['email'] ?? null, $recipient['name'] ?? null);
                }
            }

            foreach ($seen as $email => $name) {
                if ($email === $own) {
                    continue;
                }

                if (mb_stripos($email, $needle) === false && mb_stripos((string) $name, $needle) === false) {
                    continue;
                }

                if (!array_key_exists($email, $results)) {
                    $results[$email] = [
                        'email' => $email,
                        'name' => $name,
                        'source' => 'history',
                        'count' => 0,
                    ];
                }

                $results[$email]['count']++;
            }
        }

        $data = collect($results)
            ->sortBy([['count', 'desc'], ['email', 'asc']])
            ->values()
            ->take(10)
            ->all();

        return response()->json(['data' => $data]);
    }

    public function update(Request $request, MailContact $contact): JsonResponse
    {
        if ($request->has('email')) {
            $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        }

        $validated = $request->validate([
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('mail_contacts', 'email')->ignore($contact->id)],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $contact->update($validated);

        return response()->json(['data' => $contact->fresh()]);
    }

    public function destroy(MailContact $contact): JsonResponse
    {
        $user = Auth::user();

        if ((int) $contact->created_by !== (int) $user->id && !$user->isAdmin()) {
            return response()->json(['message' => 'Недостаточно прав для удаления контакта'], 403);
        }

        $contact->delete();

        return response()->json(['message' => 'Контакт удалён']);
    }

    /**
     * Import the sender and all To/Cc recipients of a message into the shared
     * address book (deduplicated, names merged into existing rows).
     */
    public function importFromMessage(MailMessage $message): JsonResponse
    {
        if ((int) $message->user_id !== (int) Auth::id()) {
            abort(404, 'Письмо не найдено');
        }

        $own = mb_strtolower((string) ($message->account?->email ?? Auth::user()->email ?? ''));
        $candidates = [];

        $add = function (?string $email, ?string $name) use (&$candidates, $own): void {
            $email = mb_strtolower(trim((string) $email));
            $name = trim((string) $name);

            if ($email === '' || $email === $own || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return;
            }

            if (!array_key_exists($email, $candidates)) {
                $candidates[$email] = $name !== '' ? $name : null;
            } elseif ($candidates[$email] === null && $name !== '') {
                $candidates[$email] = $name;
            }
        };

        $add($message->from_email, $message->from_name);

        foreach ((array) ($message->to_recipients ?? []) as $recipient) {
            if (is_array($recipient)) {
                $add($recipient['email'] ?? null, $recipient['name'] ?? null);
            }
        }

        foreach ((array) ($message->cc_recipients ?? []) as $recipient) {
            if (is_array($recipient)) {
                $add($recipient['email'] ?? null, $recipient['name'] ?? null);
            }
        }

        $added = 0;
        $updated = 0;
        $skipped = 0;

        DB::transaction(function () use ($candidates, &$added, &$updated, &$skipped) {
            $existing = MailContact::whereIn('email', array_keys($candidates))->get()->keyBy('email');

            foreach ($candidates as $email => $name) {
                $current = $existing->get($email);

                if ($current === null) {
                    try {
                        MailContact::create([
                            'email' => $email,
                            'name' => $name,
                            'created_by' => Auth::id(),
                        ]);
                        $added++;
                    } catch (QueryException) {
                        // Concurrent insert — merge into the row that won.
                        $current = MailContact::where('email', $email)->first();
                        if ($current !== null && ($current->name === null || $current->name === '') && $name !== null) {
                            $current->update(['name' => $name]);
                            $updated++;
                        } else {
                            $skipped++;
                        }
                    }

                    continue;
                }

                if (($current->name === null || $current->name === '') && $name !== null) {
                    $current->update(['name' => $name]);
                    $updated++;
                } else {
                    $skipped++;
                }
            }
        });

        return response()->json([
            'added' => $added,
            'updated' => $updated,
            'skipped' => $skipped,
            'total' => $added + $updated + $skipped,
        ]);
    }
}
