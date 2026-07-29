@php
  /** @var \App\Models\Product $p */
  $productSlug = $p->slug ?? (string) $p->id;
  $productHref = route('store.shop', ['q' => $p->code ?: $p->name]);
  $galleryUrls = collect($p->productGalleryFilenames())
    ->map(fn ($filename) => $filename ? asset('images/products/' . $filename) : null)
    ->filter()
    ->values();
  $imageUrl = $galleryUrls->first() ?: asset('images/products/no-image.png');
  $hoverImageUrl = $galleryUrls->get(1, $imageUrl);
  $description = \Illuminate\Support\Str::limit(strip_tags($p->note ?? ''), 600);
  $displayPrice = (float) ($p->display_price ?? $p->price ?? 0);
  $basePrice = (float) ($p->base_price ?? $p->price ?? 0);
  $salePercent = $basePrice > 0 && $displayPrice < $basePrice
    ? (int) round((($basePrice - $displayPrice) / $basePrice) * 100)
    : 0;
  $variants = collect($p->relationLoaded('variants') ? $p->variants : []);
  $variantPayload = $variants->map(function ($variant) use ($currency) {
    $price = (float) ($variant->display_price ?? $variant->price ?? 0);
    return [
      'id' => (int) $variant->id,
      'name' => (string) $variant->name,
      'price' => (float) ($variant->price ?? 0),
      'display_price' => $price,
      'display_price_formatted' => $currency . number_format($price, 2, '.', ','),
      'image' => !empty($variant->image) ? asset('images/products/' . $variant->image) : null,
      'stock' => (float) max(0, $variant->stock ?? 0),
    ];
  })->values();
  $simpleStock = $variants->isEmpty() ? (float) max(0, $p->stock ?? 0) : null;
  $available = $variants->isEmpty()
    ? $simpleStock
    : (float) $variantPayload->sum('stock');
  $sold = (float) max(0, $p->sold_quantity ?? 0);
  $progress = ($sold + $available) > 0
    ? max(0, min(100, round(($sold / ($sold + $available)) * 100)))
    : 0;
  $allowOverselling = (bool) ($s->allow_overselling ?? true);
  $isAvailable = $allowOverselling || $available > 0 || (bool) ($p->is_preorder ?? false);
  $hidePrices = !Auth::guard('store')->check() && ($s->hide_prices_for_guests ?? false);
  $showProgress = $showProgress ?? false;
  $compact = $compact ?? false;
@endphp

<article class="home2-product-card {{ $compact ? 'is-compact' : '' }}">
  <div class="home2-product-media">
    <a href="{{ $productHref }}" aria-label="{{ $p->name }}">
      <img class="home2-product-image" src="{{ $imageUrl }}" alt="{{ $p->name }}" loading="lazy">
      <img class="home2-product-image-hover" src="{{ $hoverImageUrl }}" alt="" aria-hidden="true" loading="lazy">
    </a>

    @if($salePercent > 0)
      <span class="home2-sale-badge"><small>Sale</small>{{ $salePercent }}%</span>
    @endif

    <div class="home2-product-actions" aria-label="Product actions">
      <button
        type="button"
        class="js-add-to-cart"
        title="{{ __('messages.AddToCart') }}"
        aria-label="{{ __('messages.AddToCart') }}"
        @if(!$isAvailable) disabled @endif
        data-out-of-stock="{{ $isAvailable ? '0' : '1' }}"
        data-id="{{ $p->id }}"
        data-product-id="{{ $p->id }}"
        data-slug="{{ $productSlug }}"
        data-name="{{ e($p->name) }}"
        data-price="{{ number_format($displayPrice, 2, '.', '') }}"
        data-image="{{ $imageUrl }}"
        data-product-image="{{ $imageUrl }}"
        data-currency="{{ $currency }}"
        data-qty="1"
        data-stock="{{ $simpleStock !== null ? $simpleStock : '' }}"
        data-variants='@json($variantPayload)'
        data-added-label="{{ __('messages.Added') }}"
      ><x-store.icon name="bag" class="w-4 h-4" /></button>
      <button
        type="button"
        class="js-wishlist"
        title="Add to wishlist"
        aria-label="Add to wishlist"
        aria-pressed="false"
        data-id="{{ $p->id }}"
        data-name="{{ e($p->name) }}"
        data-price="{{ number_format($displayPrice, 2, '.', '') }}"
        data-image="{{ $imageUrl }}"
        data-url="{{ $productHref }}"
      ><x-store.icon name="heart" class="w-4 h-4" /></button>
      <button
        type="button"
        class="js-quick-view"
        title="{{ __('messages.QuickView') }}"
        aria-label="{{ __('messages.QuickView') }}"
        data-id="{{ $p->id }}"
        data-slug="{{ $productSlug }}"
        data-name="{{ e($p->name) }}"
        data-price="{{ number_format($displayPrice, 2, '.', '') }}"
        data-image="{{ $imageUrl }}"
        data-gallery='@json($galleryUrls)'
        data-currency="{{ $currency }}"
        data-description="{{ e($description) }}"
        data-stock="{{ $simpleStock !== null ? $simpleStock : '' }}"
        data-variants='@json($variantPayload)'
      ><x-store.icon name="eye" class="w-4 h-4" /></button>
      <button
        type="button"
        class="js-compare"
        title="Compare"
        aria-label="Compare"
        aria-pressed="false"
        data-id="{{ $p->id }}"
        data-name="{{ e($p->name) }}"
        data-price="{{ number_format($displayPrice, 2, '.', '') }}"
        data-image="{{ $imageUrl }}"
        data-url="{{ $productHref }}"
      ><x-store.icon name="copy" class="w-4 h-4" /></button>
    </div>
  </div>

  <div class="home2-product-info">
    <small>{{ $p->category?->name ?: __('Product') }}</small>
    <a class="home2-product-title" href="{{ $productHref }}">{{ $p->name }}</a>

    @if(!$hidePrices)
      <div class="home2-product-prices">
        <strong>{{ $currency }}{{ number_format($displayPrice, 2, '.', ',') }}</strong>
        @if($basePrice > $displayPrice)
          <del>{{ $currency }}{{ number_format($basePrice, 2, '.', ',') }}</del>
        @endif
      </div>
    @endif

    @if($showProgress)
      <div class="home2-stock-progress"><i style="width: {{ $progress }}%"></i></div>
      <div class="home2-stock-labels">
        <span>Sold: <b>{{ number_format($sold, 0) }}</b></span>
        <span>Available: <b>{{ number_format($available, 0) }}</b></span>
      </div>
    @endif
  </div>
</article>
