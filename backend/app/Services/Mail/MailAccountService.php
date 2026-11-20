<?php

namespace App\Services\Mail;

use App\Models\MailAccount;
use App\Models\MailAccountShare;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class MailAccountService
{
    public function forUser(User $user): ?MailAccount
    {
        return MailAccount::where('user_id', $user->id)->first();
    }

    /** Ids of every mailbox whose messages the user may read. */
    public function accessibleAccountIds(int $userId): array
    {
        return MailAccountShare::where('user_id', $userId)
            ->pluck('mail_account_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Resolve a mailbox the user can read (own or shared), if any. */
    public function accessibleAccount(User $user, int $accountId): ?MailAccount
    {
        $account = MailAccount::find($accountId);

        if ($account === null) {
            return null;
        }

        if ((int) $account->user_id === (int) $user->id) {
            return $account;
        }

        return $account->sharedWith((int) $user->id) ? $account : null;
    }

    /**
     * Resolve the mailbox used to send a message: the user's own account by
     * default, or a shared account they are allowed to reply from.
     */
    public function accountForSend(User $user, ?int $accountId): ?MailAccount
    {
        if ($accountId === null) {
            return $this->forUser($user);
        }

        $account = MailAccount::find($accountId);

        if ($account === null) {
            return null;
        }

        return $account->canSendAs((int) $user->id) ? $account : null;
    }

    /**
     * Create or update the mailbox settings of a user. Password fields are only
     * overwritten when a non-empty value is supplied, so the UI can leave them
     * blank to keep the existing secret.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(User $user, array $data): MailAccount
    {
        return DB::transaction(function () use ($user, $data) {
            $account = MailAccount::firstOrNew(['user_id' => $user->id]);

            $account->fill([
                'email' => $data['email'],
                'display_name' => $data['display_name'] ?? null,
                'incoming_protocol' => $data['incoming_protocol'] ?? MailAccount::PROTOCOL_IMAP,
                'imap_host' => $data['imap_host'] ?? null,
                'imap_port' => $data['imap_port'] ?? null,
                'imap_encryption' => $data['imap_encryption'] ?? 'ssl',
                'imap_username' => $data['imap_username'] ?? null,
                'pop_host' => $data['pop_host'] ?? null,
                'pop_port' => $data['pop_port'] ?? null,
                'pop_encryption' => $data['pop_encryption'] ?? 'ssl',
                'pop_username' => $data['pop_username'] ?? null,
                'smtp_host' => $data['smtp_host'] ?? null,
                'smtp_port' => $data['smtp_port'] ?? null,
                'smtp_encryption' => $data['smtp_encryption'] ?? 'tls',
                'smtp_username' => $data['smtp_username'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            foreach (['imap_password', 'pop_password', 'smtp_password'] as $field) {
                if (!empty($data[$field])) {
                    $account->{$field} = $data[$field];
                }
            }

            // Only one incoming protocol is used at a time; drop every field of
            // the protocol that was not selected.
            if ($account->incoming_protocol === MailAccount::PROTOCOL_POP) {
                $account->fill([
                    'imap_host' => null,
                    'imap_port' => null,
                    'imap_username' => null,
                    'imap_encryption' => null,
                ]);
                $account->imap_password = null;
            } else {
                $account->fill([
                    'pop_host' => null,
                    'pop_port' => null,
                    'pop_username' => null,
                    'pop_encryption' => null,
                ]);
                $account->pop_password = null;
            }

            $account->last_sync_error = null;
            $account->save();

            return $account;
        });
    }
}
