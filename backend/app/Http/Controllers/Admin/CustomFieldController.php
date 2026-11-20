<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomField;
use App\Services\CustomFields\CustomFieldService;
use Illuminate\Http\Request;

/**
 * Admin CRUD for custom field definitions.
 */
class CustomFieldController extends Controller
{
    public function __construct(private readonly CustomFieldService $fields)
    {
    }

    public function index()
    {
        $fields = CustomField::query()
            ->forEntity('issue')
            ->ordered()
            ->get();

        return view('admin.custom-fields.index', [
            'fields' => $fields,
            'types' => CustomField::TYPES,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->fields->sanitizeDefinition([
            'entity' => 'issue',
            'key' => $request->input('key'),
            'label' => $request->input('label'),
            'type' => $request->input('type'),
            'options' => $request->input('options'),
            'required' => $request->boolean('required'),
            'position' => $request->input('position', 0),
            'is_active' => true,
        ]);

        if ($data === null) {
            return back()->withInput()->with('error', 'Проверьте ключ и тип поля');
        }

        if (CustomField::where('entity', $data['entity'])->where('key', $data['key'])->exists()) {
            return back()->withInput()->with('error', 'Поле с таким ключом уже существует');
        }

        CustomField::create($data);

        return redirect()->route('admin.custom-fields.index')->with('success', 'Поле добавлено');
    }

    public function update(Request $request, CustomField $customField)
    {
        $data = $this->fields->sanitizeDefinition([
            'entity' => $customField->entity,
            'key' => $customField->key,
            'label' => $request->input('label', $customField->label),
            'type' => $request->input('type', $customField->type),
            'options' => $request->input('options', $customField->options),
            'required' => $request->boolean('required', $customField->required),
            'position' => $request->input('position', $customField->position),
            'is_active' => $request->boolean('is_active', $customField->is_active),
        ]);

        if ($data === null) {
            return back()->with('error', 'Проверьте тип поля');
        }

        $customField->update($data);

        return redirect()->route('admin.custom-fields.index')->with('success', 'Поле обновлено');
    }

    public function destroy(CustomField $customField)
    {
        $customField->delete();

        return redirect()->route('admin.custom-fields.index')->with('success', 'Поле удалено');
    }
}
