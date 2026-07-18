@extends('layouts.store')

@section('content')
@php
  $currency = $s->currency_code ?? '$';
  $nlBtn = __('messages.Subscribe');
  $collectionBlocks = collect($blocks ?? [])->where('type', 'collection')->values();
  $products = $collectionBlocks->flatMap(fn ($block) => collect($block['products'] ?? []))->unique('id')->values();
  $fallbackProductImage = asset('images/storefront/dummy-air-fryer.png');
  $heroImage = asset('images/storefront/onsus-hero.png');
  $promoImage = asset('images/storefront/electronics-promo.png');
  $sections = [
    ['title' => 'Deal Of The Day', 'class' => 'is-deal', 'offset' => 0],
    ['title' => 'Featured Products', 'class' => 'is-featured', 'offset' => 5],
    ['title' => 'Trending Products', 'class' => '', 'offset' => 2],
    ['title' => 'Best Sellers', 'class' => '', 'offset' => 4],
    ['title' => 'Recently Viewed', 'class' => '', 'offset' => 1],
  ];
  $categoryNames = ['Laptops & Accessories', 'Phones, Tablets & Accessories', 'Apple Products', 'Server & Workstation', 'Game Controller', 'Audio Equipments', 'Storage & Digital Devices', 'Smart Home Appliances'];
@endphp

<div class="onsus-home">
  <section class="onsus-hero" style="--onsus-hero:url('{{ $heroImage }}')">
    <div class="container onsus-hero-inner">
      <div class="onsus-hero-copy">
        <span>NEW ARRIVAL</span>
        <h1>HEADSET &amp;<br><strong>HEADPHONE</strong></h1>
        <small>Starting</small>
        <b>{{ $currency }}250</b>
        <a href="{{ route('store.shop') }}">Shop now <x-store.icon name="arrow-right" class="w-4 h-4" /></a>
      </div>
      <div class="onsus-hero-picks">
        <article class="onsus-hero-pick">
          <a href="{{ route('store.shop') }}" class="onsus-hero-pick-main"><img src="{{ $fallbackProductImage }}" alt=""><span><small>New product</small><strong>Premium Air Fryer with Digital Display</strong><b>{{ $currency }}51.500</b><del>{{ $currency }}64.990</del></span></a>
          <div class="onsus-hero-pick-actions" aria-label="Product actions">
            <button type="button" title="Add to cart"><x-store.icon name="bag" class="w-4 h-4" /></button>
            <button type="button" title="Add to wishlist"><x-store.icon name="heart" class="w-4 h-4" /></button>
            <a href="{{ route('store.shop') }}" title="Quick view"><x-store.icon name="eye" class="w-4 h-4" /></a>
            <button type="button" title="Compare"><x-store.icon name="copy" class="w-4 h-4" /></button>
          </div>
        </article>
        <article class="onsus-hero-pick">
          <a href="{{ route('store.shop') }}" class="onsus-hero-pick-main"><img src="{{ $heroImage }}" alt=""><span><small>Smart TV</small><strong>Ultra HD Smart Entertainment System</strong><b>{{ $currency }}68.499</b><del>{{ $currency }}85.990</del></span></a>
          <div class="onsus-hero-pick-actions" aria-label="Product actions">
            <button type="button" title="Add to cart"><x-store.icon name="bag" class="w-4 h-4" /></button>
            <button type="button" title="Add to wishlist"><x-store.icon name="heart" class="w-4 h-4" /></button>
            <a href="{{ route('store.shop') }}" title="Quick view"><x-store.icon name="eye" class="w-4 h-4" /></a>
            <button type="button" title="Compare"><x-store.icon name="copy" class="w-4 h-4" /></button>
          </div>
        </article>
      </div>
    </div>
  </section>

  <div class="container">
    <section class="onsus-benefits" aria-label="Store benefits">
      <div><x-store.icon name="truck" class="w-7 h-7" /><span><strong>Free delivery</strong><small>Free Shipping for orders over $200</small></span></div>
      <div><x-store.icon name="phone" class="w-7 h-7" /><span><strong>Support 24/7</strong><small>24 hours a day, 7 days a week</small></span></div>
      <div><x-store.icon name="credit-card" class="w-7 h-7" /><span><strong>Payment</strong><small>Pay with multiple credit cards</small></span></div>
      <div><x-store.icon name="shield-check" class="w-7 h-7" /><span><strong>Reliable</strong><small>Trusted by 2000+ major brands</small></span></div>
      <div><x-store.icon name="check-circle" class="w-7 h-7" /><span><strong>Guarantee</strong><small>Within 30 days for an exchange</small></span></div>
    </section>

    <section class="onsus-category-grid" aria-label="Product categories">
      @foreach($categoryNames as $index => $name)
        @php $category = $categories->get($index); @endphp
        <a href="{{ $category ? route('store.shop', ['category' => $category->id]) : route('store.shop') }}">
          <img src="{{ $index % 3 === 0 ? $heroImage : $fallbackProductImage }}" alt="">
          <span>{{ $category->name ?? $name }}</span>
        </a>
      @endforeach
    </section>

    @foreach($sections as $sectionIndex => $section)
      @if($sectionIndex === 3)
        <section class="onsus-editorial-products">
          <div class="onsus-editorial-banner" style="--promo:url('{{ $promoImage }}')"><span>Samsung<br><b>8K TV</b><strong>70”</strong><small>Made for vivid, cinematic viewing</small></span></div>
          <div class="onsus-editorial-grid">
            @foreach($products->slice(0, 3) as $product)
              @include('store.partials.product-card', ['p' => $product, 'currency' => $currency, 'fallbackImage' => $fallbackProductImage])
            @endforeach
          </div>
        </section>
      @endif

      <section class="onsus-product-section {{ $section['class'] }}">
        <div class="onsus-section-heading">
          <div>@if($sectionIndex === 0)<span class="onsus-flame"><x-store.icon name="lightning" class="w-4 h-4" /></span>@endif<h2>{{ $section['title'] }}</h2>@if($sectionIndex === 1)<nav><button class="active">Feature</button><button>Toprate</button><button>On sale</button></nav>@endif</div>
          <div class="onsus-section-arrows"><button aria-label="Previous"><x-store.icon name="chevron-left" class="w-4 h-4" /></button><button aria-label="Next"><x-store.icon name="chevron-right" class="w-4 h-4" /></button></div>
        </div>

        <div class="onsus-product-grid">
          @forelse($products->slice($section['offset'])->concat($products->take($section['offset']))->take(5) as $product)
            @include('store.partials.product-card', ['p' => $product, 'currency' => $currency, 'fallbackImage' => $fallbackProductImage, 'dealIndex' => $loop->index])
          @empty
            @for($i = 0; $i < 5; $i++)
              <article class="onsus-dummy-card">
                <a href="{{ route('store.shop') }}"><span class="onsus-sale">SALE<br>{{ 18 + $i * 3 }}%</span><img src="{{ $fallbackProductImage }}" alt="Digital air fryer"></a>
                <small>Home Appliances</small><h3>Premium Digital Air Fryer — Family Size</h3><div><b>{{ $currency }}{{ number_format(51.5 + $i * 6.4, 3) }}</b><del>{{ $currency }}{{ number_format(68.9 + $i * 8.1, 3) }}</del></div>
              </article>
            @endfor
          @endforelse
        </div>
      </section>

      @if($sectionIndex === 0 || $sectionIndex === 2)
        <a href="{{ route('store.shop') }}" class="onsus-wide-promo" style="--promo:url('{{ $promoImage }}')">
          <span>@if($sectionIndex === 0) ThinkPad X1 Carbon Gen 9<br><strong>4K HDR · Core i7 32GB</strong>@else Shop and <strong>SAVE BIG</strong><br><small>on selected cameras, appliances and accessories</small>@endif</span>
          <b>{{ $sectionIndex === 0 ? 'LIMITED $1,399' : 'DEALS FROM $67.700' }}</b>
        </a>
      @endif

      @if($sectionIndex === 1)
        <section class="onsus-catch-grid">
          @foreach(['HEADPHONES','CAMERAS','PHONES','WATCHES'] as $item)
            <a href="{{ route('store.shop') }}"><i>SALE<br>{{ 15 + $loop->index * 3 }}%</i><span>CATCH BIG<br><b>DEALS</b><small>ON THE {{ $item }}</small><em>Shop now</em></span><img src="{{ $fallbackProductImage }}" alt=""></a>
          @endforeach
        </section>
      @endif
    @endforeach

    <section class="onsus-smart-home">
      <div class="onsus-section-heading"><div><h2>Smart Home Appliances</h2></div><div class="onsus-section-arrows"><button><x-store.icon name="chevron-left" class="w-4 h-4" /></button><button><x-store.icon name="chevron-right" class="w-4 h-4" /></button></div></div>
      <div class="onsus-smart-grid">
        @for($i = 0; $i < 10; $i++)
          <a href="{{ route('store.shop') }}"><img src="{{ $fallbackProductImage }}" alt=""><span><small>Smart appliance</small><strong>Premium connected home product</strong><b>{{ $currency }}{{ number_format(14.5 + $i * 4.6, 3) }}</b></span></a>
        @endfor
      </div>
    </section>
  </div>

  <section class="onsus-newsletter">
    <div class="container"><strong><x-store.icon name="mail" class="w-5 h-5" /> 10% Off Your First Order</strong><span>Be the first to know about offers, new products and discounted products</span><form id="newsletterForm">@csrf<input name="email" id="newsletterEmail" type="email" placeholder="Enter your email address" required><button id="newsletterBtn" type="submit">{{ $nlBtn }}</button></form><div id="newsletterMsg"></div></div>
  </section>

  <div class="onsus-deal-modal" x-data="{ open: false }" x-init="setTimeout(() => open = true, 850)" @account-login.window="open = false" @keydown.escape.window="open = false" x-show="open" x-cloak>
    <div class="onsus-deal-backdrop" @click="open = false"></div>
    <div class="onsus-deal-dialog" role="dialog" aria-modal="true" aria-labelledby="deal-modal-title" x-transition>
      <button type="button" class="onsus-deal-close" @click="open = false" aria-label="Close"><x-store.icon name="x" class="w-5 h-5" /></button>
      <div class="onsus-deal-visual" style="--promo:url('{{ $promoImage }}')"></div>
      <div class="onsus-deal-copy"><span>WELCOME OFFER</span><h2 id="deal-modal-title">Get 20% off your first order</h2><p>Join our list for fresh arrivals, members-only prices and the best deals of the week.</p><form @submit.prevent="open = false"><input type="email" placeholder="Your email address"><button type="submit">Get my discount</button></form><label><input type="checkbox"> Don’t show this popup again</label></div>
    </div>
  </div>
</div>

@include('store.partials.home-modals-scripts', ['currency' => $currency, 'nlBtn' => $nlBtn])
@endsection
