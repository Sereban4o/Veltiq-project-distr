<?php

namespace App\Http\Controllers\Api\Knowledgebase;

use App\Http\Controllers\Controller;
use App\Models\File;
use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\HTMLToMarkdown\HtmlConverter;

class PageExportController extends Controller
{
    public function pdf(Request $request, string $pageId)
    {
        $page = Page::with(['space', 'author'])->findOrFail($pageId);

        if (! $page->canView(Auth::user())) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $this->ensureDejavuFonts();

        $body = $this->inlineImages($page->body ?? '');

        $html = view('kb.export-pdf', [
            'page' => $page,
            'body' => $body,
            'generatedAt' => now(),
        ])->render();

        $dompdf = app('dompdf.wrapper');
        $dompdf->loadHTML($html);
        $dompdf->setPaper('a4');
        $dompdf->render();

        $filename = Str::slug($page->title) ?: 'page';

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '.pdf"',
        ]);
    }

    public function markdown(Request $request, string $pageId)
    {
        $page = Page::with(['space', 'author'])->findOrFail($pageId);

        if (! $page->canView(Auth::user())) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $converter = new HtmlConverter(['strip_tags' => true]);
        $markdown = $converter->convert($this->inlineImages($page->body ?? ''));

        $frontMatter = [
            'title' => $page->title,
            'space' => $page->space?->name,
            'author' => $page->author?->name,
            'status' => $page->status,
            'labels' => $page->labels ?? [],
            'updated_at' => optional($page->updated_at)->toIso8601String(),
        ];

        $yaml = "---\n";
        foreach ($frontMatter as $key => $value) {
            if (is_array($value)) {
                $yaml .= $key . ': ' . json_encode(array_values($value), JSON_UNESCAPED_UNICODE) . "\n";
            } else {
                $yaml .= $key . ': ' . ($value ?? '') . "\n";
            }
        }
        $yaml .= "---\n\n";

        $content = $yaml . '# ' . $page->title . "\n\n" . $markdown;

        $filename = Str::slug($page->title) ?: 'page';

        return response($content, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '.md"',
        ]);
    }

    /**
     * Replace API image URLs with inline base64 data URIs so the renderer does
     * not need network/session access while converting.
     */
    private function inlineImages(string $html): string
    {
        if ($html === '' || ! str_contains($html, '<img')) {
            return $html;
        }

        return preg_replace_callback(
            '/src=(["\'])([^"\']*\/files\/(\d+)[^"\']*)\1/i',
            function (array $matches) {
                $fileId = (int) $matches[3];
                $file = File::find($fileId);

                if (! $file || ! $file->path) {
                    return $matches[0];
                }

                if (! Storage::disk('public')->exists($file->path)) {
                    return $matches[0];
                }

                $contents = Storage::disk('public')->get($file->path);
                $mime = $file->mime_type ?: 'application/octet-stream';
                $data = 'data:' . $mime . ';base64,' . base64_encode($contents);

                return 'src="' . $data . '"';
            },
            $html,
        );
    }

    /**
     * dompdf reads its font files from {@see config('dompdf.options.font_dir')}
     * (storage/fonts). Copy the DejaVu family shipped with dompdf there once so
     * Cyrillic text renders correctly.
     */
    private function ensureDejavuFonts(): void
    {
        $target = storage_path('fonts');

        if (! is_dir($target)) {
            mkdir($target, 0755, true);
        }

        $source = base_path('vendor/dompdf/dompdf/lib/fonts');

        foreach (['DejaVuSans.ttf', 'DejaVuSans-Bold.ttf', 'DejaVuSans-Oblique.ttf', 'DejaVuSans-BoldOblique.ttf'] as $font) {
            if (! file_exists($target . '/' . $font) && file_exists($source . '/' . $font)) {
                copy($source . '/' . $font, $target . '/' . $font);
            }
        }
    }
}
