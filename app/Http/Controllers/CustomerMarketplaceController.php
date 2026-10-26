<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\StorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class CustomerMarketplaceController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $search = $this->searchTerm($request);
        $category = Product::normalizeCategoryFilter($request->query('category'));
        $platformName = trim((string) config('getfy.app_name', 'plataforma')) ?: 'plataforma';

        if (! Schema::hasColumn('products', 'show_in_marketplace')) {
            return Inertia::render('Cliente/Marketplace', [
                'products' => ['data' => [], 'links' => []],
                'filters' => ['q' => $search, 'category' => $category],
                'categories' => Product::categoriesForSelect(),
                'platformName' => $platformName,
            ]);
        }

        $ownedIds = $user instanceof User ? $this->ownedProductIds($user) : [];

        $query = Product::query()
            ->listedOnMarketplace()
            ->availableForPurchase()
            ->whereNotNull('checkout_slug')
            ->where('checkout_slug', '!=', '')
            ->with('tenantOwner:id,name,trade_name');

        if ($ownedIds !== []) {
            $query->whereNotIn('products.id', $ownedIds);
        }

        if ($search !== '') {
            $query->where('products.name', 'like', '%'.$search.'%');
        }

        $query->ofCategory($category)->orderBy('products.name');

        $products = $query->paginate(24)->withQueryString();
        $products->through(fn (Product $product) => $this->card($product));

        return Inertia::render('Cliente/Marketplace', [
            'products' => $products,
            'filters' => [
                'q' => $search,
                'category' => $category,
            ],
            'categories' => Product::categoriesForSelect(),
            'platformName' => $platformName,
        ]);
    }

    /**
     * @return list<int|string>
     */
    private function ownedProductIds(User $user): array
    {
        $fromOrders = Order::query()
            ->where('user_id', $user->id)
            ->where('status', 'completed')
            ->pluck('product_id');

        $fromItems = OrderItem::query()
            ->whereIn('order_id', Order::query()->where('user_id', $user->id)->where('status', 'completed')->select('id'))
            ->pluck('product_id');

        $granted = $user->products()->pluck('products.id');

        return $fromOrders
            ->merge($fromItems)
            ->merge($granted)
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function searchTerm(Request $request): string
    {
        $search = trim((string) $request->query('q', ''));
        $search = str_replace(['%', '_'], '', $search);

        return mb_substr($search, 0, 80);
    }

    /**
     * @return array<string, mixed>
     */
    private function card(Product $product): array
    {
        $imageUrl = null;
        if (is_string($product->image) && $product->image !== '') {
            $imageUrl = (new StorageService($product->tenant_id))->url($product->image);
        }

        $owner = $product->tenantOwner;
        $sellerName = trim((string) ($owner?->trade_name ?: $owner?->name));
        $currency = strtoupper((string) ($product->currency ?: 'BRL'));
        $slug = (string) $product->checkout_slug;

        return [
            'id' => $product->id,
            'name' => $product->name,
            'image_url' => $imageUrl !== '' ? $imageUrl : null,
            'price' => (float) $product->price,
            'currency' => $currency,
            'category_label' => Product::categoryLabels()[$product->category] ?? null,
            'seller_name' => $sellerName !== '' ? $sellerName : null,
            'checkout_url' => url('/c/'.$slug).'?origem=marketplace',
        ];
    }
}
