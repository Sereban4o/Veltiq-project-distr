<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Role;
use App\Services\Tasksbase\NotificationBroadcastService;
use Illuminate\Http\Request;

class NotificationBroadcastController extends Controller
{
    public function __construct(
        private NotificationBroadcastService $broadcasts,
    ) {
    }

    public function index(Request $request)
    {
        $search = $request->string('q')->toString();
        $type = $request->string('type')->toString();

        $notifications = Notification::query()
            ->with(['user:id,first_name,last_name,email'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($sub) use ($search) {
                    $sub->whereLike('type', "%{$search}%")
                        ->orWhereHas('user', function ($user) use ($search) {
                            $user->whereLike('email', "%{$search}%")
                                ->orWhereLike('first_name', "%{$search}%")
                                ->orWhereLike('last_name', "%{$search}%");
                        });
                });
            })
            ->when($type !== '', fn ($query) => $query->where('type', $type))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.notifications.broadcast', [
            'notifications' => $notifications,
            'types' => Notification::query()->distinct()->orderBy('type')->pluck('type'),
            'roles' => Role::query()->orderBy('name')->pluck('name'),
            'recipients' => $this->broadcasts->count(),
            'filters' => ['q' => $search, 'type' => $type],
        ]);
    }

    public function send(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:1000',
            'title' => 'nullable|string|max:120',
            'type' => 'nullable|string|max:50',
            'role' => 'nullable|string|max:50|exists:roles,name',
            'include_inactive' => 'sometimes|boolean',
        ]);

        $sent = $this->broadcasts->send(
            $validated['message'],
            $validated['type'] ?? 'system',
            $validated['title'] ?? null,
            $validated['role'] ?? null,
            (bool) ($validated['include_inactive'] ?? false),
        );

        return redirect()
            ->route('admin.notifications.broadcast')
            ->with('success', "Рассылка отправлена: {$sent} получателей");
    }
}
