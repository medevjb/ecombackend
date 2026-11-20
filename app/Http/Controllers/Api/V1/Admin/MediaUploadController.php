<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UploadProductImageRequest;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class MediaUploadController extends Controller
{
    use ApiResponse;

    public function store(UploadProductImageRequest $request, string $scope): JsonResponse
    {
        Gate::authorize(match ($scope) {
            'category-create' => 'categories.create',
            'category-update' => 'categories.update',
            'brand-create' => 'brands.create',
            'brand-update' => 'brands.update',
            'content' => 'content.manage',
            default => abort(404),
        });

        $disk = config()->string('filesystems.product_media_disk');
        $path = $request->validated('image')->store('storefront/'.$scope, $disk);
        abort_if($path === false, 503, 'The image could not be stored.');

        return $this->apiSuccess($request, ['url' => Storage::disk($disk)->url($path)], 'Image uploaded.', 201);
    }
}
