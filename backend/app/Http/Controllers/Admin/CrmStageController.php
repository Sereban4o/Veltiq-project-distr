<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Extensions\Crm\Http\Controllers\PipelineStageController;
use App\Extensions\Crm\Models\PipelineStage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CrmStageController extends Controller
{
    public function index()
    {
        $stages = PipelineStage::orderBy('sort_order')->withCount('deals')->get();

        return view('admin.crm-stages.index', compact('stages'));
    }

    public function store(Request $request)
    {
        $validated = $this->validateData($request);

        PipelineStage::create($validated);

        Cache::forget(PipelineStageController::CACHE_KEY);

        return redirect()->route('admin.crm-stages.index')->with('success', 'Этап добавлен');
    }

    public function update(Request $request, PipelineStage $stage)
    {
        $stage->update($this->validateData($request));

        Cache::forget(PipelineStageController::CACHE_KEY);

        return redirect()->route('admin.crm-stages.index')->with('success', 'Этап обновлён');
    }

    public function destroy(PipelineStage $stage)
    {
        if (PipelineStage::count() <= 1) {
            return back()->with('error', 'Нельзя удалить последний этап');
        }

        $stage->delete();

        Cache::forget(PipelineStageController::CACHE_KEY);

        return redirect()->route('admin.crm-stages.index')->with('success', 'Этап удалён');
    }

    private function validateData(Request $request): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sort_order' => 'nullable|integer|min:0|max:100000',
            'color' => 'nullable|string|max:20',
            'is_won' => 'sometimes|boolean',
            'is_lost' => 'sometimes|boolean',
        ]);

        return [
            'name' => $validated['name'],
            'sort_order' => $validated['sort_order'] ?? 0,
            'color' => $validated['color'] ?? '#3390ec',
            'is_won' => $request->boolean('is_won'),
            'is_lost' => $request->boolean('is_lost'),
        ];
    }
}
