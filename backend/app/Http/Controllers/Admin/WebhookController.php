<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Webhook;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Http\Request;
use Throwable;

/**
 * Admin CRUD for outbound webhooks (external integrations).
 */
class WebhookController extends Controller
{
    public function index()
    {
        $webhooks = Webhook::orderByDesc('id')->get();

        return view('admin.webhooks.index', [
            'webhooks' => $webhooks,
            'events' => WebhookDispatcher::EVENTS,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);

        Webhook::create([
            'name' => $validated['name'],
            'url' => $validated['url'],
            'secret' => $validated['secret'] ?? null,
            'events' => $validated['events'],
            'is_active' => $request->boolean('is_active', true),
            'created_by' => $request->user()?->getKey(),
        ]);

        return redirect()->route('admin.webhooks.index')->with('success', 'Вебхук создан');
    }

    public function update(Request $request, Webhook $webhook)
    {
        $validated = $this->validated($request);

        $webhook->update([
            'name' => $validated['name'],
            'url' => $validated['url'],
            'secret' => $validated['secret'] ?? $webhook->secret,
            'events' => $validated['events'],
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('admin.webhooks.index')->with('success', 'Вебхук обновлён');
    }

    public function destroy(Webhook $webhook)
    {
        $webhook->delete();

        return redirect()->route('admin.webhooks.index')->with('success', 'Вебхук удалён');
    }

    public function test(Webhook $webhook)
    {
        try {
            app(WebhookDispatcher::class)->deliver($webhook, 'webhook.test', ['ok' => true]);
        } catch (Throwable $e) {
            return back()->with('error', 'Не удалось доставить: ' . $e->getMessage());
        }

        return back()->with('success', 'Тестовая доставка успешна (HTTP ' . $webhook->fresh()->last_status . ')');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:100',
            'url' => ['required', 'string', 'max:2048', 'regex:#^https?://#i'],
            'secret' => 'nullable|string|max:255',
            'events' => 'required|array|min:1',
            'events.*' => 'in:' . implode(',', WebhookDispatcher::EVENTS),
            'is_active' => 'sometimes|boolean',
        ]);
    }
}
