<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class UploadProductImageRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'image' => ['required', File::image()->max('6mb')->dimensions(Rule::dimensions()->maxWidth(6000)->maxHeight(6000)), 'mimes:jpg,jpeg,png,webp'],
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'primary' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
