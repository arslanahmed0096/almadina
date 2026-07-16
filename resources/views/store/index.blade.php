@extends('layouts.store')

@section('content')

@php
  use Illuminate\Support\Str;

  /** @var \App\Models\StoreSetting $s */
  $currency = $s->currency_code ?? '$';
  $nlBtn = __('messages.Subscribe');
  $byPos = collect($banners ?? [])->groupBy('position');
  $heroBlock = collect($blocks ?? [])->firstWhere('type', 'hero') ?? [];
  $collectionBlocks = collect($blocks ?? [])
      ->where('type', 'collection')
      ->filter(fn ($block) => collect($block['products'] ?? [])->isNotEmpty())
      ->values();

  $assetUrl = function ($path, $fallback = 'images/products/no-image.png') {
      if (empty($path)) return asset($fallback);
      if (Str::startsWith($path, ['http://', 'https://', '//'])) return $path;
      $clean = ltrim($path, '/');
      return file_exists(public_path($clean)) ? asset($clean) : asset($fallback);
  };

  $heroPath = $heroBlock['image'] ?? $s->hero_image_path ?? null;
  if (empty($heroPath) || (!Str::startsWith((string) $heroPath, ['http://', 'https://', '//']) && !file_exists(public_path(ltrim((string) $heroPath, '/'))))) {
      $heroPath = file_exists(public_path('store_files/hero_image.jpg'))
          ? 'store_files/hero_image.jpg'
          : 'images/store/hero_image.jpg';
  }
  $heroUrl = $assetUrl($heroPath, 'images/store/hero_image.jpg');
  $heroTitle = $heroBlock['title'] ?? $s->hero_title ?? 'The new standard for everyday shopping';
  $heroSubtitle = $heroBlock['subtitle'] ?? $s->hero_subtitle ?? 'Quality products, fair prices, and dependable delivery in one simple place.';

  $topPromos = collect()
      ->merge($byPos['top_left'] ?? collect())
      ->merge($byPos['top_right'] ?? collect())
      ->take(2)
      ->values();
  $campaignBanners = collect()
      ->merge($byPos['center_left'] ?? collect())
      ->merge($byPos['center_right'] ?? collect())
      ->merge($byPos['footer_left'] ?? collect())
      ->merge($byPos['footer_right'] ?? collect())
      ->values();
@endphp

<div class="retail-home">
  <section class="retail-hero-section">
    <div class="container">
      <div class="retail-hero-grid">
        <aside class="retail-category-panel" aria-label="{{ __('messages.Categories') }}">
          <a href="{{ route('store.shop') }}" class="retail-category-title">
            <x-store.icon name="grid" class="w-4 h-4" />
            <span>{{ __('messages.AllProducts') }}</span>
          </a>
          <div class="retail-category-list">
            @forelse($categories->take(9) as $category)
              <a href="{{ route('store.shop', ['category' => $category->id]) }}">
                <span class="retail-category-icon"><x-store.icon name="package" class="w-4 h-4" /></span>
                <span>{{ $category->name }}</span>
                <x-store.icon name="chevron-right" class="w-3 h-3" />
              </a>
            @empty
              <a href="{{ route('store.shop') }}">
                <span class="retail-category-icon"><x-store.icon name="bag" class="w-4 h-4" /></span>
                <span>{{ __('messages.Shop') }}</span>
                <x-store.icon name="chevron-right" class="w-3 h-3" />
              </a>
            @endforelse
          </div>
        </aside>

        <a href="{{ route('store.shop') }}" class="retail-main-hero" style="--retail-hero-image: url('{{ $heroUrl }}')">
          <span class="retail-hero-shade"></span>
          <span class="retail-hero-content">
            <span class="retail-eyebrow">New collection</span>
            <strong>{{ $heroTitle }}</strong>
            <small>{{ $heroSubtitle }}</small>
            <span class="retail-price-line">Shop from <b>{{ $currency }}25</b></span>
            <span class="retail-hero-cta">{{ __('messages.ShopNow') }} <x-store.icon name="arrow-right" class="w-4 h-4" /></span>
          </span>
        </a>

        <div class="retail-side-promos">
          @forelse($topPromos as $index => $banner)
            <a href="{{ $banner->link ?: route('store.shop') }}" class="retail-side-promo {{ $index % 2 ? 'is-violet' : '' }}">
              <img src="{{ $assetUrl($banner->image_url ?? $banner->image, 'images/store/hero_image.jpg') }}" alt="{{ $banner->title ?? 'Special offer' }}">
              <span class="retail-promo-shade"></span>
              <span class="retail-promo-copy">
                <small>Catch big</small>
                <strong>{{ $banner->title ?? 'Deals on top picks' }}</strong>
                <em>{{ __('messages.ShopNow') }} →</em>
              </span>
            </a>
          @empty
            <a href="{{ route('store.shop', ['deals' => 1]) }}" class="retail-side-promo">
              <img src="{{ $heroUrl }}" alt="Weekly deals">
              <span class="retail-promo-shade"></span>
              <span class="retail-promo-copy"><small>Up to 30% off</small><strong>Weekly deals</strong><em>{{ __('messages.ShopNow') }} →</em></span>
            </a>
            <a href="{{ route('store.shop') }}" class="retail-side-promo is-violet">
              <img src="{{ $heroUrl }}" alt="New arrivals">
              <span class="retail-promo-shade"></span>
              <span class="retail-promo-copy"><small>Just landed</small><strong>New arrivals</strong><em>{{ __('messages.ShopNow') }} →</em></span>
            </a>
          @endforelse
        </div>
      </div>

      <div class="retail-benefits">
        <div><x-store.icon name="truck" class="w-6 h-6" /><span><strong>Free delivery</strong><small>On qualifying orders</small></span></div>
        <div><x-store.icon name="phone" class="w-6 h-6" /><span><strong>Helpful support</strong><small>Here when you need us</small></span></div>
        <div><x-store.icon name="credit-card" class="w-6 h-6" /><span><strong>Flexible payment</strong><small>Safe and convenient</small></span></div>
        <div><x-store.icon name="shield-check" class="w-6 h-6" /><span><strong>Reliable quality</strong><small>Products you can trust</small></span></div>
      </div>
    </div>
  </section>

  @forelse($collectionBlocks as $blockIndex => $block)
    @php
      $collection = $block['collection'];
      $products = $block['products'] ?? collect();
      $sectionTitle = $block['title'] ?? ($collection->title ?? $collection->name ?? __('messages.Collection'));
    @endphp

    @if($products->count())
      <section class="retail-product-section {{ $blockIndex === 0 ? 'is-deal-section' : '' }}">
        <div class="container">
          <div class="retail-section-head">
            <div>
              @if($blockIndex === 0)
                <span class="retail-section-icon"><x-store.icon name="lightning" class="w-4 h-4" /></span>
              @endif
              <h2>{{ $blockIndex === 0 ? 'Deal of the day' : $sectionTitle }}</h2>
              @if($blockIndex === 0)<span class="retail-countdown">Ends soon · Don’t miss out</span>@endif
            </div>
            <a href="{{ !empty($collection->slug) ? route('store.shop', ['collection' => $collection->slug]) : route('store.shop') }}">
              {{ __('messages.ViewAll') }} <x-store.icon name="arrow-right" class="w-4 h-4" />
            </a>
          </div>

          <div class="retail-product-grid">
            @foreach($products as $product)
              @include('store.partials.product-card', ['p' => $product, 'currency' => $currency])
            @endforeach
          </div>
        </div>
      </section>
    @endif

    @if($blockIndex === 0)
      <section class="retail-campaign-section">
        <div class="container">
          @php $campaign = $campaignBanners->get(0); @endphp
          <a href="{{ $campaign?->link ?: route('store.shop') }}" class="retail-campaign" style="--campaign-image: url('{{ $campaign ? $assetUrl($campaign->image_url ?? $campaign->image, 'images/store/hero_image.jpg') : $heroUrl }}')">
            <span class="retail-campaign-glow"></span>
            <span class="retail-campaign-copy">
              <small>Limited-time spotlight</small>
              <strong>{{ $campaign?->title ?? 'A special edition made for your everyday' }}</strong>
              <span>Save more on this week’s featured collection</span>
              <b>{{ __('messages.ShopNow') }} →</b>
            </span>
          </a>
        </div>
      </section>
    @endif

    @if($blockIndex === 1)
      <section class="retail-mini-promos">
        <div class="container">
          <div class="retail-mini-promo-grid">
            @foreach(['Accessories', 'Popular picks', 'New products', 'Everyday essentials'] as $promoIndex => $promoTitle)
              <a href="{{ route('store.shop') }}" class="retail-mini-promo promo-{{ $promoIndex + 1 }}">
                <span><small>Save up to {{ 15 + ($promoIndex * 5) }}%</small><strong>{{ $promoTitle }}</strong><em>{{ __('messages.ShopNow') }} →</em></span>
              </a>
            @endforeach
          </div>
        </div>
      </section>
    @endif
  @empty
    <section class="retail-empty-feature">
      <div class="container">
        <div class="retail-empty-card">
          <span class="retail-eyebrow">Start exploring</span>
          <h2>Everything you need, all in one place</h2>
          <p>Browse the complete catalogue and discover the latest products available online.</p>
          <a href="{{ route('store.shop') }}" class="btn btn-primary">{{ __('messages.ShopNow') }}</a>
        </div>
      </div>
    </section>
  @endforelse

  @if($campaignBanners->count() > 1)
    <section class="retail-banner-pair">
      <div class="container">
        <div class="retail-banner-grid">
          @foreach($campaignBanners->slice(1, 2) as $banner)
            <a href="{{ $banner->link ?: route('store.shop') }}">
              <img src="{{ $assetUrl($banner->image_url ?? $banner->image, 'images/store/hero_image.jpg') }}" alt="{{ $banner->title ?? 'Promotion' }}">
            </a>
          @endforeach
        </div>
      </div>
    </section>
  @endif

  @php
    $nlTitle = $s->newsletter_title ?? __('messages.GetFreshDealsTitle');
    $nlSubtitle = $s->newsletter_subtitle ?? __('messages.GetFreshDealsSubtitle');
    $nlPlaceholder = $s->newsletter_placeholder ?? __('messages.NewsletterEmailPlaceholder');
  @endphp
  <section class="retail-newsletter">
      <div class="container">
        <div class="retail-newsletter-inner">
          <div>
            <span class="retail-newsletter-icon"><x-store.icon name="mail" class="w-5 h-5" /></span>
            <span><strong>{{ $nlTitle }}</strong><small>{{ $nlSubtitle }}</small></span>
          </div>
          <form id="newsletterForm">
            @csrf
            <input name="email" type="email" id="newsletterEmail" placeholder="{{ $nlPlaceholder }}" required>
            <button id="newsletterBtn" type="submit">{{ $nlBtn }}</button>
          </form>
          <div id="newsletterMsg" class="retail-newsletter-message"></div>
        </div>
      </div>
  </section>
</div>

@include('store.partials.home-modals-scripts', ['currency' => $currency, 'nlBtn' => $nlBtn])

@endsection
