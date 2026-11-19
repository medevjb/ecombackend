<?php

namespace App\Services\Catalog;

use App\Enums\CatalogStatus;
use App\Enums\ProductStatus;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ProductDiscovery
{
    public function __construct(private readonly CategoryTree $tree) {}

    /** @param array<string, mixed> $filters
     * @return Builder<Product>
     */
    public function query(array $filters = []): Builder
    {
        $tree = $this->tree->visible();
        $selected = isset($filters['category']) ? $this->tree->findVisible($tree, $filters['category']) : null;
        $categories = $this->categoryIds(isset($filters['category'])
            ? new Collection($selected === null ? [] : [$selected])
            : $tree);
        $options = $this->optionGroups($filters['attribute_value_ids'] ?? []);
        $stock = array_key_exists('in_stock', $filters) ? (bool) $filters['in_stock'] : null;

        $query = Product::query()->select('products.*')
            ->leftJoin('product_metrics', 'product_metrics.product_id', '=', 'products.id')
            ->selectRaw('COALESCE(product_metrics.approved_rating_count, 0) AS rating_count')
            ->selectRaw('COALESCE(product_metrics.approved_rating_total, 0) AS rating_total')
            ->where('products.status', ProductStatus::Active)
            ->whereHas('categories', fn (Builder $category) => $category->whereIn('categories.id', $categories))
            ->whereHas('variants', fn (Builder $variant) => $this->filterVariants($variant, $options, $stock === true))
            ->withExists(['variants as in_stock' => fn (Builder $variant) => $this->filterVariants($variant, $options, true)])
            ->with([
                'brand' => fn ($brand) => $brand->where('status', CatalogStatus::Active),
                'categories' => fn ($category) => $category->whereIn('categories.id', $categories),
                'images' => fn ($image) => $image->orderBy('sort_order')->orderBy('id'),
            ]);

        if ($stock === false) {
            $query->whereDoesntHave('variants', fn (Builder $variant) => $this->filterVariants($variant, $options, true));
        }
        if (isset($filters['search'])) {
            $search = '%'.strtr($filters['search'], ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $query->where(fn (Builder $text) => $text
                ->whereRaw("products.name LIKE ? ESCAPE '!'", [$search])
                ->orWhereRaw("products.short_description LIKE ? ESCAPE '!'", [$search]));
        }
        if (isset($filters['brand'])) {
            $query->whereHas('brand', fn (Builder $brand) => $brand
                ->where('slug', $filters['brand'])->where('status', CatalogStatus::Active));
        }
        if (isset($filters['min_price'])) {
            $query->whereRaw('COALESCE(products.sale_amount, products.regular_amount) >= ?', [$filters['min_price']]);
        }
        if (isset($filters['max_price'])) {
            $query->whereRaw('COALESCE(products.sale_amount, products.regular_amount) <= ?', [$filters['max_price']]);
        }
        if (isset($filters['attribute_value_ids']) && count($filters['attribute_value_ids']) !== array_sum(array_map('count', $options))) {
            $query->whereRaw('1 = 0');
        }

        return match ($filters['sort'] ?? 'newest') {
            'price_asc' => $query->orderByRaw('COALESCE(products.sale_amount, products.regular_amount) ASC')->orderBy('products.id'),
            'price_desc' => $query->orderByRaw('COALESCE(products.sale_amount, products.regular_amount) DESC')->orderByDesc('products.id'),
            'top_selling' => $query->orderByRaw('COALESCE(product_metrics.paid_quantity, 0) DESC')->orderByDesc('products.id'),
            default => $query->orderByDesc('products.published_at')->orderByDesc('products.id'),
        };
    }

    public function detail(string $slug): Product
    {
        $product = $this->query()->where('products.slug', $slug)->firstOrFail();

        return $product->load(['variants' => fn ($variants) => $variants->eligible()->orderByDesc('is_default')->orderBy('id')
            ->with('values')->withExists(['inventory as in_stock' => fn (Builder $inventory) => $this->available($inventory)])]);
    }

    /** @param Collection<int, Category> $nodes
     * @return list<int>
     */
    private function categoryIds(Collection $nodes): array
    {
        $ids = [];

        foreach ($nodes as $node) {
            $ids[] = $node->id;
            array_push($ids, ...$this->categoryIds($node->getRelation('children')));
        }

        return $ids;
    }

    /** @param list<string> $ids
     * @return array<int, list<int>>
     */
    private function optionGroups(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $groups = [];
        foreach (AttributeValue::query()->whereIn('public_id', $ids)->where('status', CatalogStatus::Active)
            ->whereHas('attribute', fn (Builder $attribute) => $attribute->where('status', CatalogStatus::Active))->get() as $option) {
            $groups[$option->attribute_id][] = $option->id;
        }

        return $groups;
    }

    /** @param Builder<ProductVariant> $variant
     * @param  array<int, list<int>>  $options
     */
    private function filterVariants(Builder $variant, array $options, bool $stock): void
    {
        $variant->eligible();
        foreach ($options as $values) {
            $variant->whereHas('values', fn (Builder $value) => $value->whereKey($values));
        }
        if ($stock) {
            $variant->whereHas('inventory', fn (Builder $inventory) => $this->available($inventory));
        }
    }

    /** @param Builder<Inventory> $inventory */
    private function available(Builder $inventory): void
    {
        $inventory->whereColumn('on_hand', '>', 'reserved');
    }
}
