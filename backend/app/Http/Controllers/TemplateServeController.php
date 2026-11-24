<?php

namespace App\Http\Controllers;

use App\Models\Template;
use App\Models\User;
use App\Services\TemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Serves an activated custom template (docs/13-template-system.md).
 *
 * A template is plain HTML/CSS/JS: the backend wraps the page in a Blade layout
 * and injects `window.TEMPLATE_DATA`. The built-in React SPA is not served here;
 * `/template/{slug}` only answers for uploaded templates.
 */
class TemplateServeController extends Controller
{
    public function __construct(private readonly TemplateService $templates)
    {
    }

    /** Active template summary for the SPA (public: the SPA redirects before login). */
    public function active(): JsonResponse
    {
        $template = $this->templates->activeTemplate();

        return response()->json([
            'active' => $template !== null ? $this->summary($template) : null,
        ]);
    }

    /**
     * Rendered document of the active custom template.
     *
     * The SPA fetches this and swaps its own document for it, so an activated
     * template opens at the site root without changing the address (no
     * `/template/{slug}` in the URL bar). Works for HTML and PHP templates.
     */
    public function document(Request $request): Response
    {
        $template = $this->templates->activeTemplate();

        if ($template === null || $template->is_builtin) {
            abort(404);
        }

        $user = Auth::guard('sanctum')->user();

        if ($template->requires_auth && $user === null) {
            abort(401);
        }

        // The template serves every route while it is active: the requested path
        // selects the page (`profile` → `profile.php`/`profile.html`) and is
        // exposed to the template, so `/profile` stays inside the template.
        $path = '/' . trim((string) $request->query('path', ''), '/');
        $page = trim($path, '/');

        $templateData = $this->templates->getTemplateData($user instanceof User ? $user : null);
        $templateData['path'] = $path;

        try {
            $rendered = $this->templates->renderPage($template, $page === '' ? null : $page, $templateData);
        } catch (Throwable) {
            abort(404);
        }

        if (($rendered['php'] ?? false) === true) {
            return response($rendered['content'], 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        $assets = $this->templates->assetsFor($template);

        $html = view('template.serve', [
            'template' => $template,
            'content' => $rendered['content'],
            'templateData' => $templateData,
            'stylesheetUrl' => $assets['stylesheet'],
            'scriptUrl' => $assets['script'],
        ])->render();

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function serve(Request $request, string $slug, ?string $page = null): View|Response
    {
        $template = $this->findTemplate($slug);

        if ($template->requires_auth && $request->user() === null) {
            return redirect('/login');
        }

        $templateData = $this->templates->getTemplateData($request->user());
        $templateData['path'] = $this->requestedPath($page);

        try {
            $rendered = $this->templates->renderPage($template, $page, $templateData);
        } catch (Throwable) {
            abort(404);
        }

        // A PHP template renders the whole page itself (no Blade wrapper).
        if (($rendered['php'] ?? false) === true) {
            return response($rendered['content'], 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        $assets = $this->templates->assetsFor($template);

        return view('template.serve', [
            'template' => $template,
            'content' => $rendered['content'],
            'templateData' => $templateData,
            'stylesheetUrl' => $assets['stylesheet'],
            'scriptUrl' => $assets['script'],
        ]);
    }

    /** `window.TEMPLATE_DATA` as JSON (for fetch-based templates). */
    public function data(Request $request, string $slug): JsonResponse
    {
        $template = $this->findTemplate($slug);

        if ($template->requires_auth && $request->user() === null) {
            abort(401);
        }

        return response()->json([
            'template' => $this->summary($template),
            'data' => $this->templates->getTemplateData($request->user()),
        ]);
    }

    /** Path exposed to the template for a direct `/template/{slug}/{page}` hit. */
    private function requestedPath(?string $page): string
    {
        $page = trim((string) $page, '/');

        return $page === '' ? '/' : '/' . $page;
    }

    private function findTemplate(string $slug): Template
    {
        $template = Template::query()
            ->where('slug', $slug)
            ->where('is_builtin', false)
            ->first();

        if ($template === null) {
            abort(404);
        }

        return $template;
    }

    /** @return array<string, mixed> */
    private function summary(Template $template): array
    {
        return [
            'id' => $template->id,
            'slug' => $template->slug,
            'name' => $template->name,
            'version' => $template->version,
            'is_builtin' => $template->is_builtin,
            // Anonymous visitors may open a template that does not require login.
            'requires_auth' => (bool) $template->requires_auth,
            'url' => $template->is_builtin ? '/' : $template->url,
        ];
    }
}
