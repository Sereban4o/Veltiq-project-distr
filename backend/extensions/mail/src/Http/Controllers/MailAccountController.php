<?php

namespace App\Extensions\Mail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MailAccount;
use App\Models\MailAccountShare;
use App\Models\User;
use App\Extensions\Mail\Services\ImapClient;
use App\Extensions\Mail\Services\MailAccountService;
use App\Extensions\Mail\Services\MailSyncService;
use App\Extensions\Mail\Services\Pop3Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class MailAccountController extends Controller
{
    public function __construct(
        private MailAccountService $accounts,
    ) {
    }

    public function show(): JsonResponse
    {
        $account = $this->accounts->forUser(Auth::user());

        return response()->json(['data' => $account ? $this->present($account) : null]);
    }

    /**
     * Every mailbox the user may access: their own plus shared (team) inboxes.
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();
        $result = [];

        $own = $this->accounts->forUser($user);
        if ($own !== null) {
            $result[] = [
                'id' => $own->id,
                'email' => $own->email,
                'display_name' => $own->display_name,
                'is_owner' => true,
                'can_send' => true,
                'owner_name' => null,
                'members_count' => MailAccountShare::where('mail_account_id', $own->id)->count(),
            ];
        }

        $sharedIds = $this->accounts->accessibleAccountIds((int) $user->id);
        if ($sharedIds !== []) {
            $accounts = MailAccount::whereIn('id', $sharedIds)->get();
            $owners = User::whereIn('id', $accounts->pluck('user_id')->all())->get()->keyBy('id');

            foreach ($accounts as $account) {
                $share = MailAccountShare::where('mail_account_id', $account->id)
                    ->where('user_id', $user->id)
                    ->first();
                $owner = $owners[$account->user_id] ?? null;

                $result[] = [
                    'id' => $account->id,
                    'email' => $account->email,
                    'display_name' => $account->display_name,
                    'is_owner' => false,
                    'can_send' => (bool) ($share->can_send ?? false),
                    'owner_name' => $owner?->full_name,
                    'members_count' => MailAccountShare::where('mail_account_id', $account->id)->count(),
                ];
            }
        }

        return response()->json(['data' => $result]);
    }

    /** Members of the user's own (shared) mailbox. */
    public function members(): JsonResponse
    {
        $account = $this->accounts->forUser(Auth::user());
        if ($account === null) {
            return response()->json(['data' => []]);
        }

        $shares = MailAccountShare::where('mail_account_id', $account->id)->get();
        $users = User::whereIn('id', $shares->pluck('user_id')->all())->get()->keyBy('id');

        $members = $shares->map(function (MailAccountShare $share) use ($users) {
            $member = $users[$share->user_id] ?? null;

            return [
                'id' => (int) $share->user_id,
                'name' => $member?->full_name,
                'email' => $member?->email,
                'can_send' => (bool) $share->can_send,
            ];
        })->values();

        return response()->json(['data' => $members]);
    }

    public function addMember(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'can_send' => 'sometimes|boolean',
        ]);

        $account = $this->accounts->forUser(Auth::user());
        if ($account === null) {
            return response()->json(['message' => 'Сначала сохраните настройки ящика'], 422);
        }

        if ((int) $validated['user_id'] === (int) Auth::id()) {
            return response()->json(['message' => 'Владелец уже имеет доступ к ящику'], 422);
        }

        MailAccountShare::updateOrCreate(
            ['mail_account_id' => $account->id, 'user_id' => (int) $validated['user_id']],
            ['can_send' => (bool) ($validated['can_send'] ?? false)],
        );

        return response()->json(['message' => 'Доступ предоставлен']);
    }

    public function removeMember(User $user): JsonResponse
    {
        $account = $this->accounts->forUser(Auth::user());

        if ($account !== null) {
            MailAccountShare::where('mail_account_id', $account->id)
                ->where('user_id', $user->id)
                ->delete();
        }

        return response()->json(['message' => 'Доступ отозван']);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'display_name' => 'nullable|string|max:255',
            'incoming_protocol' => 'required|in:imap,pop',
            'imap_host' => 'nullable|string|max:255',
            'imap_port' => 'nullable|integer|min:1|max:65535',
            'imap_encryption' => 'nullable|in:ssl,tls,none',
            'imap_username' => 'nullable|string|max:255',
            'imap_password' => 'nullable|string|max:1000',
            'pop_host' => 'nullable|string|max:255',
            'pop_port' => 'nullable|integer|min:1|max:65535',
            'pop_encryption' => 'nullable|in:ssl,tls,none',
            'pop_username' => 'nullable|string|max:255',
            'pop_password' => 'nullable|string|max:1000',
            'smtp_host' => 'nullable|string|max:255',
            'smtp_port' => 'nullable|integer|min:1|max:65535',
            'smtp_encryption' => 'nullable|in:ssl,tls,none',
            'smtp_username' => 'nullable|string|max:255',
            'smtp_password' => 'nullable|string|max:1000',
            'is_active' => 'sometimes|boolean',
        ]);

        $account = $this->accounts->save(Auth::user(), $validated);

        return response()->json(['data' => $this->present($account)]);
    }

    public function test(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'protocol' => 'required|in:imap,pop',
        ]);

        $account = $this->accounts->forUser(Auth::user());
        if ($account === null) {
            return response()->json(['message' => 'Сначала сохраните настройки ящика'], 422);
        }

        try {
            if ($validated['protocol'] === MailAccount::PROTOCOL_POP) {
                $client = new Pop3Client(
                    (string) $account->pop_host,
                    (int) ($account->pop_port ?: 995),
                    (string) ($account->pop_encryption ?: 'ssl'),
                    (string) ($account->pop_username ?: $account->email),
                    (string) $account->pop_password,
                );
            } else {
                $client = new ImapClient(
                    (string) $account->imap_host,
                    (int) ($account->imap_port ?: 993),
                    (string) ($account->imap_encryption ?: 'ssl'),
                    (string) ($account->imap_username ?: $account->email),
                    (string) $account->imap_password,
                );
            }

            $client->connect();
            $client->close();

            return response()->json(['message' => 'Подключение успешно']);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function sync(MailSyncService $sync): JsonResponse
    {
        $account = $this->accounts->forUser(Auth::user());
        if ($account === null) {
            return response()->json(['message' => 'Ящик не настроен'], 422);
        }

        try {
            $stored = $sync->sync($account);

            return response()->json(['stored' => $stored, 'synced_at' => $account->last_synced_at]);
        } catch (Throwable $e) {
            $account->forceFill(['last_sync_error' => $e->getMessage()])->save();

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(MailAccount $account): array
    {
        return [
            'id' => $account->id,
            'email' => $account->email,
            'display_name' => $account->display_name,
            'incoming_protocol' => $account->incoming_protocol,
            'imap_host' => $account->imap_host,
            'imap_port' => $account->imap_port,
            'imap_encryption' => $account->imap_encryption,
            'imap_username' => $account->imap_username,
            'imap_password_set' => !empty($account->imap_password),
            'pop_host' => $account->pop_host,
            'pop_port' => $account->pop_port,
            'pop_encryption' => $account->pop_encryption,
            'pop_username' => $account->pop_username,
            'pop_password_set' => !empty($account->pop_password),
            'smtp_host' => $account->smtp_host,
            'smtp_port' => $account->smtp_port,
            'smtp_encryption' => $account->smtp_encryption,
            'smtp_username' => $account->smtp_username,
            'smtp_password_set' => !empty($account->smtp_password),
            'is_active' => $account->is_active,
            'last_synced_at' => $account->last_synced_at,
            'last_sync_error' => $account->last_sync_error,
        ];
    }
}
