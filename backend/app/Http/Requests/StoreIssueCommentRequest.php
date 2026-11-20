<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreIssueCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => 'required|string|max:20000',
            'parent_comment_id' => 'nullable|integer|exists:issue_comments,id',
            'attachment_file_ids' => 'sometimes|array',
            'attachment_file_ids.*' => 'integer|exists:files,id',
            'time_spent_hours' => 'nullable|numeric|min:0|max:24',
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'Текст комментария обязателен',
        ];
    }
}
