<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Widgets\WidgetService;
use Illuminate\Http\Request;

/**
 * Admin editor for embeddable iframe panels.
 */
class WidgetController extends Controller
{
    public function __construct(private readonly WidgetService $widgets)
    {
    }

    public function index()
    {
        $widgets = $this->widgets->all();

        $textarea = implode(
            "\n",
            array_map(
                fn (array $widget) => $widget['title'] . ' | ' . $widget['url'] . ' | ' . $widget['height'],
                $widgets,
            ),
        );

        return view('admin.widgets.index', compact('widgets', 'textarea'));
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'widgets' => 'nullable|string|max:20000',
        ]);

        $this->widgets->save($this->widgets->parse((string) ($validated['widgets'] ?? '')));

        return redirect()->route('admin.widgets.index')->with('success', 'Виджеты сохранены');
    }
}
