<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Template;
use App\Services\TemplateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * Template administration (Blade): upload ZIP archives, activate/deactivate and
 * delete. Custom templates are plain HTML/CSS/JS served by the backend; the
 * built-in React SPA cannot be deleted or deactivated (docs/13-template-system.md).
 */
class TemplateController extends Controller
{
    public function __construct(private readonly TemplateService $templates)
    {
    }

    public function index(): View
    {
        return view('admin.templates.index', [
            'templates' => $this->templates->listTemplates(),
            'active' => $this->templates->activeTemplate(),
        ]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'archive' => ['required', 'file', 'max:51200'],
            'name' => ['nullable', 'string', 'max:128'],
            'author' => ['nullable', 'string', 'max:128'],
        ]);

        $file = $request->file('archive');
        if ($file === null) {
            return back()->withErrors(['archive' => 'Архив шаблона не загружен.']);
        }

        try {
            $template = $this->templates->uploadAndExtract(
                $file,
                $validated['name'] ?? null,
                $validated['author'] ?? null,
            );
        } catch (Throwable $e) {
            return back()->withErrors(['archive' => 'Шаблон отклонён: ' . $e->getMessage()]);
        }

        return redirect()->route('admin.templates.index')->with(
            'status',
            "Шаблон «{$template->name}» установлен. Нажмите «Активировать», чтобы включить его.",
        );
    }

    public function activate(int $id): RedirectResponse
    {
        $template = Template::query()->findOrFail($id);

        $this->templates->activate($template);

        return back()->with('status', "Активный шаблон: {$template->name}.");
    }

    public function deactivate(int $id): RedirectResponse
    {
        $template = Template::query()->findOrFail($id);

        try {
            $this->templates->deactivate($template);
        } catch (Throwable $e) {
            return back()->withErrors(['templates' => $e->getMessage()]);
        }

        return back()->with('status', 'Шаблон деактивирован.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $template = Template::query()->findOrFail($id);

        try {
            $this->templates->delete($template);
        } catch (Throwable $e) {
            return back()->withErrors(['templates' => $e->getMessage()]);
        }

        return back()->with('status', "Шаблон «{$template->name}» удалён.");
    }
}
