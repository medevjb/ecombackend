<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AuditLogIndexRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'event' => ['sometimes', 'required', 'string', 'max:100', 'regex:/\A[a-z0-9.-]+\z/'],
            'user_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'from' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'required', 'date_format:Y-m-d', Rule::when($this->has('from'), 'after_or_equal:from')],
            'sort_by' => ['sometimes', 'required', Rule::in(['created_at', 'id', 'event'])],
            'sort_direction' => ['sometimes', 'required', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'required', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
        ];
    }
}
