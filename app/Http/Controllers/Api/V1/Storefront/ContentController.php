<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Enums\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CategoryResource;
use App\Http\Resources\Api\V1\ProductResource;
use App\Http\Resources\Api\V1\StorefrontContentResource;
use App\Http\Resources\Api\V1\TaxonomyResource;
use App\Models\Brand;
use App\Models\Category;
use App\Models\StorefrontContent;
use App\Services\Catalog\ProductDiscovery;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentController extends Controller
{
    use ApiResponse;

    public function home(Request $request, ProductDiscovery $products): JsonResponse
    {
        $banners = $this->published('banner');
        $sections = $this->published('section')->map(function (StorefrontContent $section) use ($request, $products): array {
            $result = (new StorefrontContentResource($section))->resolve($request);
            $result['entries'] = $this->entries($section, $products);

            return $result;
        });

        return $this->apiSuccess($request, [
            'announcements' => StorefrontContentResource::collection($banners->where('data.placement', 'announcement')->values()),
            'hero_slides' => StorefrontContentResource::collection($banners->where('data.placement', 'hero')->values()),
            'promotions' => StorefrontContentResource::collection($banners->where('data.placement', 'promotion')->values()),
            'sections' => $sections->all(),
            'testimonials' => StorefrontContentResource::collection($this->published('testimonial')),
            'settings' => (object) $this->siteSettings()->data,
        ], 'Homepage retrieved.');
    }

    public function navigation(Request $request): JsonResponse
    {
        $items = $this->published('navigation', 100);
        $groups = [];
        foreach (['header', 'footer', 'social'] as $placement) {
            $groups[$placement] = StorefrontContentResource::collection($items->where('data.placement', $placement)->values());
        }

        return $this->apiSuccess($request, $groups, 'Navigation retrieved.');
    }

    public function settings(Request $request): JsonResponse
    {
        return $this->apiSuccess($request, (object) $this->siteSettings()->data, 'Site settings retrieved.');
    }

    /** @return Collection<int, StorefrontContent> */
    private function published(string $type, int $limit = 24): Collection
    {
        return StorefrontContent::query()->where('type', $type)->published()->orderBy('sort_order')->orderBy('id')->limit($limit)->get();
    }

    private function siteSettings(): StorefrontContent
    {
        return StorefrontContent::query()->where('type', 'settings')->where('key', 'site')->firstOrFail();
    }

    private function entries(StorefrontContent $section, ProductDiscovery $products): mixed
    {
        $data = $section->data;
        $limit = (int) ($data['limit'] ?? 8);
        $items = collect(is_array($data['items'] ?? null) ? $data['items'] : []);
        $references = $items->pluck('reference_id')->filter()->values();
        $order = $references->flip();

        return match ($data['strategy']) {
            'new_arrivals', 'top_selling' => ProductResource::collection($products->query([
                'sort' => $data['strategy'] === 'top_selling' ? 'top_selling' : 'newest',
            ])->limit($limit)->get()),
            'curated' => ProductResource::collection($products->query()->whereIn('products.public_id', $references)
                ->get()->sortBy(fn ($product) => $order->get($product->public_id))->take($limit)->values()),
            'categories' => CategoryResource::collection(Category::query()->whereKey($products->visibleCategoryIds())
                ->when($references->isNotEmpty(), fn ($query) => $query->whereIn('public_id', $references))
                ->orderBy('sort_order')->orderBy('id')->limit($limit)->get()),
            'brands' => TaxonomyResource::collection(Brand::query()->where('status', CatalogStatus::Active)
                ->when($references->isNotEmpty(), fn ($query) => $query->whereIn('public_id', $references))
                ->orderBy('sort_order')->orderBy('id')->limit($limit)->get()),
            'tiles' => $items->take($limit)->values()->all(),
            default => [],
        };
    }
}
