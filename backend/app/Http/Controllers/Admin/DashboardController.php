<?php

namespace App\Http\Controllers\Admin;

use App\Extensions\Mail\Services\MailAccountService;
use App\Http\Controllers\Controller;
use App\Models\IssuePriority;
use App\Models\IssueStatus;
use App\Models\Role;
use App\Models\Space;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $activeTab = $request->get('tab', 'users');

        // Stats
        $stats = [
            'total_users' => User::count(),
            'active_users' => User::where('status', 'active')->count(),
            'suspended_users' => User::where('status', 'suspended')->count(),
            'total_issues' => DB::table('issues')->count(),
            'total_spaces' => Schema::hasTable('knowledgebase_spaces') ? DB::table('knowledgebase_spaces')->count() : 0,
        ];

        return view('admin.dashboard', compact('stats', 'activeTab'));
    }

    // ===== USERS =====
    public function users(Request $request)
    {
        $search = $request->get('search', '');
        $statusFilter = $request->get('status', '');
        $roleFilter = $request->get('role', '');
        $perPage = $request->get('per_page', 15);
        $page = $request->get('page', 1);

        $query = User::with('role')->orderBy('created_at', 'desc');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($statusFilter) {
            $query->where('status', $statusFilter);
        }

        if ($roleFilter) {
            $query->where('role_id', $roleFilter);
        }

        $users = $query->paginate($perPage)->withQueryString();
        $roles = Role::orderBy('name')->get();

        return view('admin.users.index', compact('users', 'roles'));
    }

    public function userCreate()
    {
        $roles = Role::orderBy('name')->get();

        return view('admin.users.create', compact('roles'));
    }

    public function userStore(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|unique:users,email',
            'password' => ['required', 'confirmed', Rules\Password::min(8)],
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'role_id' => 'required|exists:roles,id',
        ]);

        $validated['password'] = Hash::make($validated['password']);
        $validated['status'] = 'active';
        $validated['email_verified_at'] = now();

        User::create($validated);

        return redirect()->route('admin.users.index')->with('success', 'Пользователь успешно создан');
    }

    public function userEdit(User $user)
    {
        $roles = Role::orderBy('name')->get();
        $mailAccount = $user->mailAccount;

        return view('admin.users.edit', compact('user', 'roles', 'mailAccount'));
    }

    public function userUpdate(Request $request, User $user, MailAccountService $mailAccounts)
    {
        $validated = $request->validate([
            'email' => 'required|email|unique:users,email,' . $user->id,
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'role_id' => 'required|exists:roles,id',
            'status' => 'required|in:active,suspended,pending',
            'password' => ['nullable', 'confirmed', Rules\Password::min(8)],

            'mail_email' => 'nullable|email|max:255',
            'mail_display_name' => 'nullable|string|max:255',
            'mail_incoming_protocol' => 'nullable|in:imap,pop',
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
        ]);

        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $mailFields = [
            'mail_email', 'mail_display_name', 'mail_incoming_protocol',
            'imap_host', 'imap_port', 'imap_encryption', 'imap_username', 'imap_password',
            'pop_host', 'pop_port', 'pop_encryption', 'pop_username', 'pop_password',
            'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password',
        ];

        $mailData = [];
        foreach ($mailFields as $field) {
            if ($request->exists($field)) {
                $mailData[$field] = $request->input($field);
            }
        }

        // Mail fields are not part of the user record itself.
        $validated = array_diff_key($validated, array_flip($mailFields));

        $user->update($validated);

        $this->saveMailAccount($user, $mailData, $mailAccounts);

        return redirect()->route('admin.users.index')->with('success', 'Пользователь успешно обновлен');
    }

    /**
     * Persist the mailbox connection settings edited from the admin user form.
     *
     * @param  array<string, mixed>  $data
     */
    private function saveMailAccount(User $user, array $data, MailAccountService $mailAccounts): void
    {
        $existing = $user->mailAccount;
        $email = trim((string) ($data['mail_email'] ?? '')) ?: $existing?->email;

        if ($email === null || $email === '') {
            return;
        }

        $mailAccounts->save($user, [
            'email' => $email,
            'display_name' => $data['mail_display_name'] ?? $existing?->display_name,
            'incoming_protocol' => ($data['mail_incoming_protocol'] ?? null) ?: ($existing?->incoming_protocol ?? 'imap'),
            'imap_host' => $data['imap_host'] ?? null,
            'imap_port' => ($data['imap_port'] ?? null) ?: null,
            'imap_encryption' => $data['imap_encryption'] ?? null,
            'imap_username' => $data['imap_username'] ?? null,
            'imap_password' => $data['imap_password'] ?? null,
            'pop_host' => $data['pop_host'] ?? null,
            'pop_port' => ($data['pop_port'] ?? null) ?: null,
            'pop_encryption' => $data['pop_encryption'] ?? null,
            'pop_username' => $data['pop_username'] ?? null,
            'pop_password' => $data['pop_password'] ?? null,
            'smtp_host' => $data['smtp_host'] ?? null,
            'smtp_port' => ($data['smtp_port'] ?? null) ?: null,
            'smtp_encryption' => $data['smtp_encryption'] ?? null,
            'smtp_username' => $data['smtp_username'] ?? null,
            'smtp_password' => $data['smtp_password'] ?? null,
        ]);
    }

    public function userDelete(User $user, Request $request)
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'Нельзя удалить себя');
        }

        $replacementId = (int) $request->user()->id;

        DB::transaction(function () use ($user, $replacementId): void {
            $userId = (int) $user->id;

            // Chat ownership has no replacement value in the schema: hand it to
            // another member, otherwise to the administrator doing the deletion.
            if (Schema::hasTable('chats') && Schema::hasTable('chat_members')) {
                foreach (DB::table('chats')->where('created_by', $userId)->pluck('id') as $chatId) {
                    $memberId = DB::table('chat_members')
                        ->where('chat_id', $chatId)
                        ->where('user_id', '!=', $userId)
                        ->orderBy('id')
                        ->value('user_id');

                    DB::table('chats')->where('id', $chatId)->update([
                        'created_by' => $memberId !== null ? (int) $memberId : $replacementId,
                    ]);
                }
            }

            // These NOT NULL ownership/audit columns use NO ACTION foreign keys,
            // so keep the content and attribute it to the acting administrator.
            $ownedTables = [
                'projects' => 'created_by',
                'boards' => 'created_by',
                'spaces' => 'created_by',
                'issues' => 'created_by',
                'pages' => 'author_id',
                'page_versions' => 'author_id',
                'issue_links' => 'created_by',
                'issue_attachments' => 'uploaded_by',
                'page_attachments' => 'uploaded_by',
            ];

            foreach ($ownedTables as $table => $column) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->where($column, $userId)->update([$column => $replacementId]);
                }
            }

            // Messages cannot be re-attributed without falsifying authorship, so
            // the departing user's messages are removed with the account.
            if (Schema::hasTable('messages')) {
                DB::table('messages')->where('sender_id', $userId)->delete();
            }

            $user->delete();
        });

        return back()->with('success', 'Пользователь удален');
    }

    public function toggleUserStatus(User $user, Request $request)
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'Нельзя изменить статус себя');
        }

        $newStatus = $user->status === 'active' ? 'suspended' : 'active';
        $user->update(['status' => $newStatus]);

        return back()->with('success', "Статус пользователя изменен на '{$newStatus}'");
    }

    // ===== STATUSES =====
    public function statuses(Request $request)
    {
        $search = $request->get('search', '');
        $categoryFilter = $request->get('category', '');

        $query = IssueStatus::orderBy('sort_order')->orderBy('id');

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($categoryFilter) {
            $query->where('category', $categoryFilter);
        }

        $statuses = $query->paginate(15)->withQueryString();
        $categories = IssueStatus::pluck('category')->unique()->filter()->toArray();

        return view('admin.statuses.index', compact('statuses', 'categories'));
    }

    public function statusCreate()
    {
        return view('admin.statuses.create');
    }

    public function statusStore(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:issue_statuses,name',
            'category' => 'nullable|string|max:100',
            'color' => 'required|string|regex:/^#[0-9A-F]{6}$/i',
            'is_used' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $validated['is_used'] = $request->has('is_used');
        $validated['sort_order'] = $validated['sort_order'] ?? 0;
        IssueStatus::create($validated);

        return redirect()->route('admin.statuses.index')->with('success', 'Статус успешно создан');
    }

    public function statusEdit(IssueStatus $status)
    {
        return view('admin.statuses.edit', compact('status'));
    }

    public function statusUpdate(Request $request, IssueStatus $status)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:issue_statuses,name,' . $status->id,
            'category' => 'nullable|string|max:100',
            'color' => 'required|string|regex:/^#[0-9A-F]{6}$/i',
            'is_used' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $validated['is_used'] = $request->has('is_used');
        $validated['sort_order'] = $validated['sort_order'] ?? 0;
        $status->update($validated);

        return redirect()->route('admin.statuses.index')->with('success', 'Статус успешно обновлен');
    }

    public function statusDelete(IssueStatus $status)
    {
        $inUse = DB::table('issues')->where('status_id', $status->id)->exists();
        if ($inUse) {
            return back()->with('error', 'Нельзя удалить статус, который используется в задачах');
        }

        $status->delete();

        return back()->with('success', 'Статус удален');
    }

    // ===== PRIORITIES =====
    public function priorities(Request $request)
    {
        $search = $request->get('search', '');

        $query = IssuePriority::orderBy('order')->orderBy('name');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('key', 'like', "%{$search}%");
            });
        }

        $priorities = $query->paginate(15)->withQueryString();

        return view('admin.priorities.index', compact('priorities'));
    }

    public function priorityCreate()
    {
        return view('admin.priorities.create');
    }

    public function priorityStore(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:issue_priorities,name',
            'key' => 'required|string|max:10|unique:issue_priorities,key',
            'color' => 'required|string|regex:/^#[0-9A-F]{6}$/i',
            'order' => 'nullable|integer|min:0',
            'is_used' => 'boolean',
        ]);

        $validated['order'] = $request->input('order', 0);
        $validated['is_used'] = $request->has('is_used');
        IssuePriority::create($validated);

        return redirect()->route('admin.priorities.index')->with('success', 'Приоритет успешно создан');
    }

    public function priorityEdit(IssuePriority $priority)
    {
        return view('admin.priorities.edit', compact('priority'));
    }

    public function priorityUpdate(Request $request, IssuePriority $priority)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:issue_priorities,name,' . $priority->id,
            'key' => 'required|string|max:10|unique:issue_priorities,key,' . $priority->id,
            'color' => 'required|string|regex:/^#[0-9A-F]{6}$/i',
            'order' => 'nullable|integer|min:0',
            'is_used' => 'boolean',
        ]);

        $validated['order'] = $request->input('order', 0);
        $validated['is_used'] = $request->has('is_used');
        $priority->update($validated);

        return redirect()->route('admin.priorities.index')->with('success', 'Приоритет успешно обновлен');
    }

    public function priorityDelete(IssuePriority $priority)
    {
        $inUse = DB::table('issues')->where('priority_id', $priority->id)->exists();
        if ($inUse) {
            return back()->with('error', 'Нельзя удалить приоритет, который используется в задачах');
        }

        $priority->delete();

        return back()->with('success', 'Приоритет удален');
    }

    // ===== ROLES =====
    public function roles(Request $request)
    {
        $search = $request->get('search', '');

        $query = Role::withCount('users')->orderBy('name');

        if ($search) {
            $query->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
        }

        $roles = $query->paginate(15)->withQueryString();

        return view('admin.roles.index', compact('roles'));
    }

    public function roleCreate()
    {
        return view('admin.roles.create', []);
    }

    public function roleStore(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:roles,name',
            'description' => 'nullable|string|max:500',
        ]);

        Role::create($validated);

        return redirect()->route('admin.roles.index')->with('success', 'Роль успешно создана');
    }

    public function roleEdit(Role $role)
    {
        return view('admin.roles.edit', compact('role'));
    }

    public function roleUpdate(Request $request, Role $role)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:roles,name,' . $role->id,
            'description' => 'nullable|string|max:500',
        ]);

        // The admin role is referenced by name across the authorisation layer,
        // so it must keep its identifier.
        if ($role->name === 'admin' && $validated['name'] !== 'admin') {
            return back()->with('error', 'Нельзя переименовать роль администратора');
        }

        $role->update($validated);

        return redirect()->route('admin.roles.index')->with('success', 'Роль успешно обновлена');
    }

    public function roleDelete(Role $role, Request $request)
    {
        if ($role->name === 'admin') {
            return back()->with('error', 'Нельзя удалить роль администратора');
        }

        if ($role->users()->exists()) {
            return back()->with('error', 'Нельзя удалить роль, которая назначена пользователям');
        }

        $role->delete();

        return back()->with('success', 'Роль удалена');
    }

    // ===== SPACES =====
    public function spaces(Request $request)
    {
        $search = $request->get('search', '');

        $query = Space::withCount(['pages', 'members'])->with('creator')->orderBy('created_at', 'desc');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('key', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $spaces = $query->paginate(15)->withQueryString();

        return view('admin.spaces.index', compact('spaces'));
    }

    public function spaceCreate()
    {
        return view('admin.spaces.create');
    }

    public function spaceStore(Request $request)
    {
        $validated = $request->validate([
            'key' => 'required|string|max:50|unique:spaces,key',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:project,personal,knowledge',
            'visibility' => 'required|in:private,public,team',
            'avatar' => 'nullable|string',
        ]);

        $validated['created_by'] = auth()->id();
        Space::create($validated);

        return redirect()->route('admin.spaces.index')->with('success', 'Пространство успешно создано');
    }

    public function spaceEdit(Space $space)
    {
        return view('admin.spaces.edit', compact('space'));
    }

    public function spaceUpdate(Request $request, Space $space)
    {
        $validated = $request->validate([
            'key' => 'required|string|max:50|unique:spaces,key,' . $space->id,
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:project,personal,knowledge',
            'visibility' => 'required|in:private,public,team',
            'avatar' => 'nullable|string',
        ]);

        $space->update($validated);

        return redirect()->route('admin.spaces.index')->with('success', 'Пространство успешно обновлено');
    }

    public function spaceDelete(Space $space, Request $request)
    {
        $space->delete();

        return back()->with('success', 'Пространство удалено');
    }
}
