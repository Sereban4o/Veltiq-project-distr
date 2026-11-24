<?php

namespace App\Http\Controllers;

use App\Models\Template;
use App\Services\TemplateService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Static files of an installed template (docs/13-template-system.md).
 *
 * Everything is resolved by the service with a realpath containment check: the
 * archive may not point outside its own directory, and executable types are
 * rejected when the template is unpacked.
 */
class TemplateAssetController extends Controller
{
    public function __construct(private readonly TemplateService $templates)
    {
    }

    public function serve(string $slug, ?string $path = null): BinaryFileResponse
    {
        $template = Template::query()
            ->where('slug', $slug)
            ->where('is_builtin', false)
            ->first();

        if ($template === null) {
            abort(404);
        }

        try {
            $asset = $this->templates->serveAsset($template, $path);
        } catch (Throwable) {
            abort(404);
        }

        return response()->file($asset['path'], [
            'Content-Type' => $asset['mime'],
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
