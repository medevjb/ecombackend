<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCategoryRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $prefix = $this->isMethod('POST') ? [] : ['sometimes'];
        $id = Category::query()->where('public_id', $this->route('category'))->value('id');
        $slug = Rule::unique('categories', 'slug')->ignore($id);

        return [
            'name' => [...$prefix, 'required', 'string', 'max:255'],
            'slug' => [...$prefix, 'required', 'string', 'max:255', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug],
            'parent_id' => ['sometimes', 'nullable', 'ulid'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'image_url' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
            'seo_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'seo_description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
