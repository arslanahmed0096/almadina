@php
  /** @var \App\Models\Product $product */
  $productSlug = $product->slug ?? (string) $product->id;
  $productHref = route('store.shop', ['q' => $product->code ?: $product->name]);
  $galleryUrls = collect($product->productGalleryFilenames())
    ->map(fn ($filename) => $filename ? asset('images/products/' . $filename) : null)
    ->filter()
    ->values();
  $imageUrl = $galleryUrls->first();
  $displayPrice = (float) ($product->display_price ?? $product->price ?? 0);
  $basePrice = (float) ($product->base_price ?? $product->fix_price ?? $product->price ?? 0);
  $salePercent = $basePrice > 0 && $displayPrice >= 0 && $displayPrice < $basePrice
    ? min(100, max(1, (int) round((($basePrice - $displayPrice) / $basePrice) * 100)))
    : 0;
  $variants = collect($product->relationLoaded('variants') ? $product->variants : []);
  $variantPayload = $variants->map(function ($variant) use ($currency) {
    $price = (float) ($variant->display_price ?? $variant->price ?? 0);
    return [
      'id' => (int) $variant->id,
      'name' => (string) $variant->name,
      'price' => (float) ($variant->price ?? 0),
      'display_price' => $price,
      'display_price_formatted' => $currency . number_format($price, 0, '.', ','),
      'image' => !empty($variant->image) ? asset('images/products/' . $variant->image) : null,
      'stock' => (float) max(0, $variant->stock ?? 0),
    ];
  })->values();
  $simpleStock = $variants->isEmpty() ? (float) max(0, $product->stock ?? 0) : null;
  $available = $variants->isEmpty() ? $simpleStock : (float) $variantPayload->sum('stock');
  $isAvailable = (bool) ($s->allow_overselling ?? true) || $available > 0 || (bool) ($product->is_preorder ?? false);
  $hidePrices = !Auth::guard('store')->check() && ($s->hide_prices_for_guests ?? false);
  $currencyPrefix = strtoupper(trim((string) $currency)) === 'PKR' ? 'Rs. ' : $currency;
  $reviewCount = (int) ($product->reviews_count ?? $product->review_count ?? 0);
  $brandName = trim((string) optional($product->brand)->name);
  $categoryName = trim((string) optional($product->category)->name);
  $badgeLabel = $salePercent > 0 ? 'SAVE ' . $salePercent . '%' : ($product->created_at && $product->created_at->gt(now()->subDays(14)) ? 'NEW' : null);
@endphp

<article class="alm-shop-product-card">
  <div class="alm-shop-product-card__media">
    @if($badgeLabel)
      <span class="alm-shop-product-card__badge {{ $salePercent > 0 ? 'is-sale' : 'is-new' }}">{{ $badgeLabel }}</span>
    @endif

    <button
      type="button"
      class="alm-shop-product-card__wish js-wishlist"
      title="Add to wishlist"
      aria-label="Add {{ $product->name }} to wishlist"
      aria-pressed="false"
      data-id="{{ $product->id }}"
      data-name="{{ e($product->name) }}"
      data-price="{{ number_format($displayPrice, 2, '.', '') }}"
      data-image="{{ $imageUrl ?: '' }}"
      data-url="{{ $productHref }}"
    >
      <x-store.icon name="heart" class="w-5 h-5" />
    </button>

    <a href="{{ $productHref }}" aria-label="View {{ $product->name }}">
      @if($imageUrl)
        <img src="{{ $imageUrl }}" alt="{{ $product->name }}" width="260" height="220" loading="lazy" decoding="async">
      @else
        <span class="alm-shop-product-card__placeholder">
          <x-store.icon name="image" class="w-10 h-10" />
        </span>
      @endif
    </a>
  </div>

  <div class="alm-shop-product-card__body">
    <p class="alm-shop-product-card__meta">
      @if($brandName !== '')
        <span>{{ $brandName }}</span>
      @endif
      @if($categoryName !== '')
        <span>{{ $categoryName }}</span>
      @endif
    </p>

    <a class="alm-shop-product-card__title" href="{{ $productHref }}">{{ $product->name }}</a>

    <div class="alm-shop-product-card__rating" aria-label="{{ $reviewCount > 0 ? number_format($reviewCount) . ' reviews' : 'No product reviews yet' }}">
      @for($i = 0; $i < 5; $i++)
        <x-store.icon name="star-fill" class="w-3 h-3" />
      @endfor
      <span>({{ $reviewCount > 0 ? number_format($reviewCount) : '0' }})</span>
    </div>

    @unless($hidePrices)
      <div class="alm-shop-product-card__price">
        <strong>{{ $currencyPrefix }}{{ number_format($displayPrice, 0, '.', ',') }}</strong>
        @if($basePrice > $displayPrice)
          <del>{{ $currencyPrefix }}{{ number_format($basePrice, 0, '.', ',') }}</del>
        @endif
      </div>
    @endunless

    <p class="alm-shop-product-card__stock {{ $isAvailable ? 'is-available' : 'is-unavailable' }}">
      <span></span>{{ $isAvailable ? 'In Stock' : 'Out of Stock' }}
    </p>

    <button
      type="button"
      class="alm-shop-product-card__cart js-add-to-cart"
      @if(!$isAvailable) disabled @endif
      data-out-of-stock="{{ $isAvailable ? '0' : '1' }}"
      data-id="{{ $product->id }}"
      data-product-id="{{ $product->id }}"
      data-slug="{{ $productSlug }}"
      data-name="{{ e($product->name) }}"
      data-price="{{ number_format($displayPrice, 2, '.', '') }}"
      data-image="{{ $imageUrl ?: '' }}"
      data-product-image="{{ $imageUrl ?: '' }}"
      data-currency="{{ $currency }}"
      data-qty="1"
      data-stock="{{ $simpleStock !== null ? $simpleStock : '' }}"
      data-variants='@json($variantPayload)'
      data-added-label="{{ __('messages.Added') }}"
      aria-label="{{ $isAvailable ? 'Add ' . $product->name . ' to cart' : $product->name . ' is out of stock' }}"
    >
      <x-store.icon name="cart" class="w-4 h-4" />
      <span>{{ $isAvailable ? __('messages.AddToCart') : 'Out of Stock' }}</span>
    </button>
  </div>
</article>
