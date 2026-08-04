@extends('layouts.store')

@section('content')
@php
  $currency = $s->currency_code ?? '$';
  $nlBtn = __('messages.Subscribe');
  $ref = fn (string $path) => asset('images/store/home-2/reference/images/' . ltrim($path, '/'));
  $heroImage = !empty($s->hero_image_path) ? asset($s->hero_image_path) : null;
  $heroTitle = trim((string) ($s->hero_title ?? '')) ?: 'Discover your next favorite';
  $heroSubtitle = trim((string) ($s->hero_subtitle ?? '')) ?: 'Shop our latest products and best sellers';

  $dealProducts = collect($dealBlock['products'] ?? []);
  $dealTitle = $dealBlock['title'] ?? 'Deal Of The Day';
  $dealSlug = $dealBlock['collection']->slug ?? 'deal-of-the-day';
  $categoryIcon = static function ($name) {
      $name = strtolower((string) $name);

      return match (true) {
          str_contains($name, 'washing'), str_contains($name, 'washer') => 'washing-machine',
          str_contains($name, 'refrigerator'), str_contains($name, 'fridge'), str_contains($name, 'freezer') => 'refrigerator',
          str_contains($name, 'air cooler') => 'air-cooler',
          str_contains($name, 'air condition'), str_contains($name, 'split'), str_contains($name, 'air curtain') => 'air-conditioner',
          str_contains($name, 'television'), str_contains($name, ' tv') || str_starts_with($name, 'tv'), str_contains($name, 'led tv') => 'monitor',
          str_contains($name, 'fan') => 'fan',
          str_contains($name, 'pizza') => 'pizza',
          str_contains($name, 'roti'), str_contains($name, 'cooker'), str_contains($name, 'stove'), str_contains($name, 'oven') => 'cooking-pot',
          default => 'package',
      };
  };

  $trendingCollection = $homeSectionCollections['trending'];
  $bestSellersCollection = $homeSectionCollections['best_sellers'];
  $recentlyViewedCollection = $homeSectionCollections['recently_viewed'];
  $smartHomeCollection = $homeSectionCollections['smart_home'];

  $catchTiles = [
    ['label' => 'headphones', 'sale' => '20%', 'image' => 'collection/cls-category-1.jpg', 'dark' => true],
    ['label' => 'cameras', 'sale' => '15%', 'image' => 'collection/cls-category-2.jpg', 'dark' => true],
    ['label' => 'phones', 'sale' => '28%', 'image' => 'collection/cls-category-3.jpg', 'dark' => true],
    ['label' => 'watches', 'sale' => '22%', 'image' => 'collection/cls-category-4.jpg', 'dark' => false],
  ];
@endphp

<div class="onsus-home home2-page">
  <section
    class="onsus-cover {{ $heroImage ? 'has-managed-hero' : '' }}"
    @if($heroImage) style="--hero-bg:url('{{ $heroImage }}')" @endif
  >
    <div class="container">
      <div class="onsus-cover-inner">
        <div class="onsus-cover-feature onsus-cover-feature-managed">
          <div class="onsus-cover-managed-copy">
            <span class="onsus-cover-kicker">{{ $heroSubtitle }}</span>
            <h1>{{ $heroTitle }}</h1>
            <a href="{{ route('store.shop') }}" class="onsus-cover-shop">Shop now</a>
          </div>
        </div>
        @if(($heroProducts ?? collect())->isNotEmpty())
          <div class="onsus-cover-cards">
            @foreach($heroProducts->take(2) as $product)
              @php
                $heroProductHref = route('store.shop', ['q' => $product->name]);
                $heroProductSlug = $product->slug ?? (string) $product->id;
                $heroDescription = \Illuminate\Support\Str::limit(strip_tags($product->note ?? ''), 600);
                $heroGalleryUrls = collect($product->productGalleryFilenames())
                  ->map(fn ($filename) => $filename ? asset('images/products/' . $filename) : null)
                  ->filter()
                  ->values()
                  ->all();
                $heroVariants = collect($product->variants ?? []);
                $heroVariantPayload = $heroVariants->map(fn ($variant) => [
                  'id' => (int) $variant->id,
                  'name' => (string) $variant->name,
                  'price' => (float) $variant->price,
                  'display_price' => (float) ($variant->display_price ?? $variant->price),
                  'image' => !empty($variant->image) ? asset('images/products/' . $variant->image) : null,
                  'stock' => (int) max(0, $variant->stock ?? 0),
                ])->values();
                $heroSimpleStock = $heroVariants->isEmpty() ? (int) max(0, $product->stock ?? 0) : null;
                $heroAvailable = (bool) ($s->allow_overselling ?? true)
                  || ($heroVariants->isNotEmpty()
                    ? $heroVariantPayload->contains(fn ($variant) => ($variant['stock'] ?? 0) > 0)
                    : $heroSimpleStock > 0);
              @endphp
              <article class="onsus-cover-card">
                <a href="{{ $heroProductHref }}" class="onsus-cover-card-image">
                  <img src="{{ $product->hero_image_url }}" alt="{{ $product->name }}">
                </a>
                <span class="onsus-cover-card-info">
                  <small>{{ $product->category->name ?? __('Product') }}</small>
                  <a href="{{ $heroProductHref }}" class="onsus-cover-card-title">{{ $product->name }}</a>
                  <span class="onsus-cover-card-price">{{ $currency }}{{ number_format($product->display_price, 3) }}</span>
                  @if($product->original_price > $product->display_price)
                    <del>{{ $currency }}{{ number_format($product->original_price, 3) }}</del>
                  @endif
                  <span class="onsus-cover-card-actions" aria-label="Product actions">
                    <button
                      type="button"
                      class="onsus-cover-card-action js-add-to-cart"
                      title="{{ __('messages.AddToCart') }}"
                      aria-label="{{ __('messages.AddToCart') }}"
                      @if(!$heroAvailable) disabled @endif
                      data-out-of-stock="{{ $heroAvailable ? '0' : '1' }}"
                      data-id="{{ $product->id }}"
                      data-product-id="{{ $product->id }}"
                      data-slug="{{ $heroProductSlug }}"
                      data-name="{{ e($product->name) }}"
                      data-price="{{ number_format($product->display_price, 2, '.', '') }}"
                      data-image="{{ $product->hero_image_url }}"
                      data-product-image="{{ $product->hero_image_url }}"
                      data-currency="{{ $currency }}"
                      data-qty="1"
                      data-stock="{{ $heroSimpleStock !== null ? $heroSimpleStock : '' }}"
                      data-variants='@json($heroVariantPayload)'
                      data-added-label="{{ __('messages.Added') }}"
                    ><x-store.icon name="bag" class="w-4 h-4" /></button>
                    <button
                      type="button"
                      class="onsus-cover-card-action js-wishlist"
                      title="Add to wishlist"
                      aria-label="Add to wishlist"
                      aria-pressed="false"
                      data-id="{{ $product->id }}"
                      data-name="{{ e($product->name) }}"
                      data-price="{{ number_format($product->display_price, 2, '.', '') }}"
                      data-image="{{ $product->hero_image_url }}"
                      data-url="{{ $heroProductHref }}"
                    ><x-store.icon name="heart" class="w-4 h-4" /></button>
                    <button
                      type="button"
                      class="onsus-cover-card-action js-quick-view"
                      title="{{ __('messages.QuickView') }}"
                      aria-label="{{ __('messages.QuickView') }}"
                      data-id="{{ $product->id }}"
                      data-slug="{{ $heroProductSlug }}"
                      data-name="{{ e($product->name) }}"
                      data-price="{{ number_format($product->display_price, 2, '.', '') }}"
                      data-image="{{ $product->hero_image_url }}"
                      data-gallery='@json($heroGalleryUrls)'
                      data-currency="{{ $currency }}"
                      data-description="{{ e($heroDescription) }}"
                      data-stock="{{ $heroSimpleStock !== null ? $heroSimpleStock : '' }}"
                      data-variants='@json($heroVariantPayload)'
                    ><x-store.icon name="eye" class="w-4 h-4" /></button>
                    <button
                      type="button"
                      class="onsus-cover-card-action js-compare"
                      title="Compare"
                      aria-label="Compare"
                      aria-pressed="false"
                      data-id="{{ $product->id }}"
                      data-name="{{ e($product->name) }}"
                      data-price="{{ number_format($product->display_price, 2, '.', '') }}"
                      data-image="{{ $product->hero_image_url }}"
                      data-url="{{ $heroProductHref }}"
                    ><x-store.icon name="shuffle" class="w-4 h-4" /></button>
                  </span>
                </span>
              </article>
            @endforeach
          </div>
        @endif
      </div>
    </div>
  </section>

  <div class="container home2-main">
    <section class="home2-benefits" aria-label="Store benefits">
      <div><x-store.icon name="truck" class="w-7 h-7" /><span><strong>Free delivery</strong><small>Free Shipping for orders over $20</small></span></div>
      <div><x-store.icon name="phone" class="w-7 h-7" /><span><strong>Support 24/7</strong><small>24 hours a day, 7 days a week</small></span></div>
      <div><x-store.icon name="credit-card" class="w-7 h-7" /><span><strong>Payment</strong><small>Pay with Multiple Credit Cards</small></span></div>
      <div><x-store.icon name="shield-check" class="w-7 h-7" /><span><strong>Reliable</strong><small>Trusted by 2000+ major brands</small></span></div>
      <div><x-store.icon name="check-circle" class="w-7 h-7" /><span><strong>Guarantee</strong><small>Within 30 days for an exchange</small></span></div>
    </section>

    <section class="home2-category-grid" aria-label="Product categories">
      @foreach($homeCategories as $category)
        <a href="{{ route('store.shop', ['category' => $category->id]) }}">
          <span class="home2-category-icon">
            <x-store.icon :name="$categoryIcon($category->name)" :stroke="1.35" />
          </span>
          <h6>{{ $category->name }}</h6>
        </a>
      @endforeach
    </section>

    <section class="home2-section home2-deals">
      <div class="home2-section-title"><div><span class="home2-title-icon"><x-store.icon name="lightning" class="w-4 h-4" /></span><h2><a href="{{ route('store.collection.show', ['slug' => $dealSlug]) }}">{{ $dealTitle }}</a></h2></div><div class="home2-arrows"><button type="button" data-deal-scroll="-1" aria-label="Previous"><x-store.icon name="chevron-left" class="w-4 h-4" /></button><button type="button" data-deal-scroll="1" aria-label="Next"><x-store.icon name="chevron-right" class="w-4 h-4" /></button></div></div>
      <div class="home2-products home2-products-five home2-deal-track" data-deal-track>
        @foreach($dealProducts as $p)
          @include('store.partials.home2-real-product-card', ['p' => $p, 'currency' => $currency, 's' => $s, 'showProgress' => true])
        @endforeach
      </div>
    </section>

    <section class="home2-promo-pair">
      <a href="{{ route('store.shop') }}" class="home2-promo-card is-purple" style="--promo-bg:url('{{ $ref('banner/banner-4.jpg') }}')">
        <span class="home2-promo-copy"><small>NEW</small><strong>ThinkPad X1 Carbon Gen 9<br>4K HDR-Core i7 32GB</strong></span><span class="home2-promo-price">From<br><b>{{ $currency }}1,399</b></span><img src="{{ $ref('item/camera-1.png') }}" alt="Smart camera">
      </a>
      <a href="{{ route('store.shop') }}" class="home2-promo-card is-blue" style="--promo-bg:url('{{ $ref('banner/banner-3.jpg') }}')">
        <span class="home2-promo-copy"><small>NEW</small><strong>Lenovo ThinkBook<br>8GB/MX450 2GB</strong></span><span class="home2-promo-price">From<br><b>{{ $currency }}399</b></span><img src="{{ $ref('item/laptop.png') }}" alt="Lenovo laptop">
      </a>
    </section>

    <section class="home2-section home2-featured" data-home-products-tabs>
      <div class="home2-section-title home2-tabs">
        <nav role="tablist" aria-label="Homepage product collections">
          @foreach($homeProductTabs as $tab)
            <button
              type="button"
              role="tab"
              class="{{ $loop->first ? 'active' : '' }}"
              aria-selected="{{ $loop->first ? 'true' : 'false' }}"
              aria-controls="home-products-{{ $tab['slug'] }}"
              data-home-products-tab="{{ $tab['slug'] }}"
            >{{ $tab['label'] }}</button>
          @endforeach
        </nav>
      </div>

      @foreach($homeProductTabs as $tab)
        <div
          id="home-products-{{ $tab['slug'] }}"
          class="home2-products home2-products-six home2-tab-panel"
          role="tabpanel"
          data-home-products-panel="{{ $tab['slug'] }}"
          @if(!$loop->first) hidden @endif
        >
          @foreach($tab['products'] as $p)
            @include('store.partials.home2-real-product-card', ['p' => $p, 'currency' => $currency, 's' => $s])
          @endforeach
        </div>
      @endforeach
    </section>

    <section class="home2-section">
      <div class="home2-section-title">
        <div><h2><a href="{{ route('store.collection.show', ['slug' => $trendingCollection['slug']]) }}">Trending Products</a></h2></div>
        <div class="home2-arrows">
          <button type="button" data-home-carousel-scroll="trending-products" data-direction="-1" aria-label="Previous"><x-store.icon name="chevron-left" class="w-4 h-4" /></button>
          <button type="button" data-home-carousel-scroll="trending-products" data-direction="1" aria-label="Next"><x-store.icon name="chevron-right" class="w-4 h-4" /></button>
        </div>
      </div>
      <div class="home2-products home2-collection-track" data-home-carousel="trending-products">
        @foreach($trendingCollection['products'] as $p)
          @include('store.partials.home2-real-product-card', ['p' => $p, 'currency' => $currency, 's' => $s])
        @endforeach
      </div>
    </section>

    <a href="{{ route('store.shop') }}" class="home2-camera-banner" style="--promo-bg:url('{{ $ref('banner/banner-2.jpg') }}')">
      <div><span>Shop and <b>SAVE BIG</b></span><small>on hottest camera</small></div><span class="home2-camera-sale">Save<br><b>{{ $currency }}67.700</b></span><img class="camera-one" src="{{ $ref('item/camera-2.png') }}" alt="Security camera"><img class="camera-two" src="{{ $ref('item/camera-3.png') }}" alt="Digital camera">
    </a>

    <section class="home2-section home2-best-sellers">
      <div class="home2-section-title">
        <div><h2><a href="{{ route('store.collection.show', ['slug' => $bestSellersCollection['slug']]) }}">Best Sellers</a></h2></div>
        <div class="home2-arrows">
          <button type="button" data-home-carousel-scroll="best-sellers" data-direction="-1" aria-label="Previous"><x-store.icon name="chevron-left" class="w-4 h-4" /></button>
          <button type="button" data-home-carousel-scroll="best-sellers" data-direction="1" aria-label="Next"><x-store.icon name="chevron-right" class="w-4 h-4" /></button>
        </div>
      </div>
      <div class="home2-best-grid">
        <a href="{{ route('store.shop') }}" class="home2-tv-banner" style="--promo-bg:url('{{ $ref('banner/banner-7.jpg') }}')"><span>Samsung<br><b>8K TV</b><strong>70<sup>in</sup></strong><small>For a limited time, get a free 4K TV when you join</small></span><img src="{{ $ref('item/tivi-2.png') }}" alt="Samsung 8K television"></a>
        <div class="home2-best-products" data-home-carousel="best-sellers">
          @foreach($bestSellersCollection['products'] as $p)
            @include('store.partials.home2-real-product-card', ['p' => $p, 'currency' => $currency, 's' => $s])
          @endforeach
        </div>
      </div>
    </section>

    <section class="home2-section home2-smart-section">
      <div class="home2-section-title">
        <div><h2><a href="{{ route('store.collection.show', ['slug' => $smartHomeCollection['slug']]) }}">Smart Home Appliances</a></h2></div>
      </div>
      <div class="home2-smart-grid">
        @foreach($smartHomeCollection['products'] as $p)
          @include('store.partials.home2-real-product-card', ['p' => $p, 'currency' => $currency, 's' => $s, 'compact' => true])
        @endforeach
      </div>
    </section>

    <section class="home2-catch-grid" aria-label="Featured categories">
      @foreach($catchTiles as $tile)
        <a href="{{ route('store.shop', ['q' => $tile['label']]) }}" class="{{ $tile['dark'] ? 'has-light-copy' : 'has-dark-copy' }}">
          <img src="{{ $ref($tile['image']) }}" alt="Deals on {{ $tile['label'] }}"><span class="home2-catch-sale"><small>Sale</small>{{ $tile['sale'] }}</span><span class="home2-catch-copy">Catch big<strong>Deals</strong><small>on the {{ $tile['label'] }}</small><em><x-store.icon name="chevron-right" class="w-3 h-3" /> Shop now</em></span>
        </a>
      @endforeach
    </section>

    <section class="home2-section home2-recent">
      <div class="home2-section-title">
        <div><h2><a href="{{ route('store.collection.show', ['slug' => $recentlyViewedCollection['slug']]) }}">Recently Viewed</a></h2></div>
        <div class="home2-arrows">
          <button type="button" data-home-carousel-scroll="recently-viewed" data-direction="-1" aria-label="Previous"><x-store.icon name="chevron-left" class="w-4 h-4" /></button>
          <button type="button" data-home-carousel-scroll="recently-viewed" data-direction="1" aria-label="Next"><x-store.icon name="chevron-right" class="w-4 h-4" /></button>
        </div>
      </div>
      <div class="home2-products home2-collection-track home2-recent-track" data-home-carousel="recently-viewed">
        @foreach($recentlyViewedCollection['products'] as $p)
          @include('store.partials.home2-real-product-card', ['p' => $p, 'currency' => $currency, 's' => $s])
        @endforeach
      </div>
    </section>
  </div>

  <section class="onsus-newsletter home2-newsletter">
    <div class="container"><strong><x-store.icon name="mail" class="w-5 h-5" /> 10% Off Your First Order</strong><span>Be the first to know about offers, new products and discounted products</span><form id="newsletterForm">@csrf<input name="email" id="newsletterEmail" type="email" placeholder="Enter your email address" required><button id="newsletterBtn" type="submit">{{ $nlBtn }}</button></form><div id="newsletterMsg"></div></div>
  </section>

  {{--
    Newsletter popup temporarily disabled.
    <div class="home2-newsletter-popup" x-data="{ open: false }" x-init="setTimeout(() => open = true, 2000)" @account-login.window="open = false" @keydown.escape.window="open = false" x-show="open" x-cloak>
      <div class="home2-popup-backdrop" @click="open = false"></div>
      <div class="home2-popup-dialog" role="dialog" aria-modal="true" aria-labelledby="home2-popup-title" x-transition>
        <button type="button" class="home2-popup-close" @click="open = false" aria-label="Close"><x-store.icon name="x" class="w-5 h-5" /></button>
        <h2 id="home2-popup-title">Join our newsletter for {{ $currency }}10 off</h2>
        <p>Register now to get latest updates on promotions &amp; coupons.<br>Don't worry, we do not spam!</p>
        <form @submit.prevent="open = false"><input type="email" placeholder="Enter Your Email Address" required><button type="submit">Subscribe</button></form>
      </div>
    </div>
  --}}
</div>

@include('store.partials.home-modals-scripts', ['currency' => $currency, 'nlBtn' => $nlBtn])
@endsection
