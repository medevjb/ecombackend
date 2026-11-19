<?php

namespace App\Services\Catalog;

use Closure;
use Illuminate\Validation\Rule;

class StorefrontContentRules
{
    /** @return array<string, mixed> */
    public function rules(string $type): array
    {
        $url = ['nullable', 'string', 'max:2048', function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || preg_match('/[\x00-\x20\x7F\\\\]/', $value)
                || (! preg_match('~^/(?!/)~', $value)
                    && (! preg_match('~^https?://~i', $value) || filter_var($value, FILTER_VALIDATE_URL) === false))) {
                $fail('Use a relative path or a valid HTTP or HTTPS URL.');
            }
        }];
        $text = ['nullable', 'string', 'max:200'];
        $copy = ['nullable', 'string', 'max:2000'];
        $fields = match ($type) {
            'banner' => [
                'placement' => ['required', Rule::in(['announcement', 'hero', 'promotion'])],
                'title' => ['required', 'string', 'max:200'], 'body' => $copy, 'image_url' => $url,
                'image_alt' => $text, 'link_url' => $url, 'link_label' => $text,
                'stats' => ['sometimes', 'array', 'list', 'max:8'],
                'stats.*' => ['array:label,value'],
                'stats.*.label' => ['required', 'string', 'max:100'],
                'stats.*.value' => ['required', 'string', 'max:40'],
            ],
            'section' => [
                'title' => ['required', 'string', 'max:200'],
                'strategy' => ['required', Rule::in(['new_arrivals', 'top_selling', 'curated', 'categories', 'brands', 'tiles'])],
                'limit' => ['sometimes', 'integer', 'between:1,24'],
                'items' => ['sometimes', 'array', 'list', 'max:24'],
                'items.*' => ['array:reference_id,title,image_url,image_alt,link_url'],
                'items.*.reference_id' => ['nullable', 'ulid', 'distinct:ignore_case'],
                'items.*.title' => $text, 'items.*.image_url' => $url,
                'items.*.image_alt' => $text, 'items.*.link_url' => $url,
            ],
            'navigation' => [
                'placement' => ['required', Rule::in(['header', 'footer', 'social'])], 'column' => $text,
                'label' => ['required', 'string', 'max:120'], 'url' => ['required', ...array_slice($url, 1)],
            ],
            'testimonial' => [
                'author' => ['required', 'string', 'max:120'], 'quote' => ['required', 'string', 'max:2000'],
                'source' => ['required', Rule::in(['editorial', 'customer_submission'])], 'image_url' => $url, 'image_alt' => $text,
            ],
            'settings' => [
                'name' => $text, 'logo_url' => $url, 'logo_alt' => $text, 'image_fallback_url' => $url,
                'newsletter_title' => $text, 'newsletter_copy' => $copy,
                'seo_title' => $text, 'seo_description' => $copy, 'support_email' => ['nullable', 'email:rfc', 'max:255'],
                'support_url' => $url, 'copyright' => $text,
            ],
            default => throw new \InvalidArgumentException('Unknown storefront content type.'),
        };
        $keys = array_filter(array_keys($fields), fn (string $key): bool => ! str_contains($key, '.'));

        return ['data' => ['required', 'array:'.implode(',', $keys)]] + array_combine(
            array_map(fn (string $key): string => 'data.'.$key, array_keys($fields)), array_values($fields),
        );
    }
}
