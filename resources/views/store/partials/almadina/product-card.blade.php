@php
  /** @var \App\Models\Product $product */
  $productSlug = $product->slug ?? (string) $product->id;
  $productHref = route('store.shop', ['q' => $product->code ?: $product->name]);
  $galleryUrls = collect($product->productGalleryFilenames())
    ->map(fn ($filename) => $filename ? asset('images/products/' . $filename) : null)
    ->filter()
    ->values();
  $imageUrl = $galleryUrls->first() ?: asset('images/products/no-image.png');
  $description = \Illuminate\Support\Str::limit(strip_tags($product->note ?? ''), 600);
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
  $currencyPrefix = strtoupper(trim((string) $currency)) === 'PKR' ? 'PKR ' : $currency;
  $rating = $product->average_rating ?? $product->rating ?? null;
  $reviewCount = (int) ($product->reviews_count ?? $product->review_count ?? 0);
  $isDeal = (bool) ($isDeal ?? false);
@endphp

<article class="alm-product-card home2-product-card product-card">
  <div class="alm-product-media">
    <a href="{{ $productHref }}" aria-label="View {{ $product->name }} details">
      <img src="{{ $imageUrl }}" alt="{{ $product->name }}" width="320" height="240" loading="lazy" decoding="async">
    </a>

    @if($salePercent > 0)
      <span class="alm-discount-badge {{ $isDeal ? 'is-deal' : '' }}">{{ $salePercent }}% off</span>
    @endif

    <button
      type="button"
      class="alm-wishlist-button js-wishlist"
      title="Add to wishlist"
      aria-label="Add {{ $product->name }} to wishlist"
      aria-pressed="false"
      data-id="{{ $product->id }}"
      data-name="{{ e($product->name) }}"
      data-price="{{ number_format($displayPrice, 2, '.', '') }}"
      data-image="{{ $imageUrl }}"
      data-url="{{ $productHref }}"
    ><x-store.icon name="heart" class="w-5 h-5" /></button>
  </div>

  <div class="alm-product-body">
    <a class="alm-product-name product-title" href="{{ $productHref }}">{{ $product->name }}</a>

    <div class="alm-rating" aria-label="{{ $rating !== null ? number_format((float) $rating, 1) . ' out of 5 stars' : 'No product reviews yet' }}">
      <x-store.icon name="star-fill" class="w-4 h-4" />
      @if($rating !== null)
        <span>{{ number_format((float) $rating, 1) }}</span><small>({{ number_format($reviewCount) }})</small>
      @else
        <small>No reviews yet</small>
      @endif
    </div>

    @if(!$hidePrices)
      <div class="alm-product-price">
        <strong>{{ $currencyPrefix }}{{ number_format($displayPrice, 0, '.', ',') }}</strong>
        @if($basePrice > $displayPrice)
          <del>{{ $currencyPrefix }}{{ number_format($basePrice, 0, '.', ',') }}</del>
        @endif
      </div>
    @endif

    <button
      type="button"
      class="alm-add-cart js-add-to-cart"
      @if(!$isAvailable) disabled @endif
      data-out-of-stock="{{ $isAvailable ? '0' : '1' }}"
      data-id="{{ $product->id }}"
      data-product-id="{{ $product->id }}"
      data-slug="{{ $productSlug }}"
      data-name="{{ e($product->name) }}"
      data-price="{{ number_format($displayPrice, 2, '.', '') }}"
      data-image="{{ $imageUrl }}"
      data-product-image="{{ $imageUrl }}"
      data-currency="{{ $currency }}"
      data-qty="1"
      data-stock="{{ $simpleStock !== null ? $simpleStock : '' }}"
      data-variants='@json($variantPayload)'
      data-added-label="{{ __('messages.Added') }}"
      aria-label="{{ $isAvailable ? 'Add ' . $product->name . ' to cart' : $product->name . ' is out of stock' }}"
    >
      <x-store.icon name="cart" class="w-4 h-4" />
      <span>{{ $isAvailable ? __('messages.AddToCart') : 'Out of stock' }}</span>
    </button>
  </div>
</article>
