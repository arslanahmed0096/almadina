@extends('layouts.store')

@section('content')
@php
  $currency = $s->currency_code ?? 'PKR';
  $nlBtn = __('messages.Subscribe');

  $featureTab = collect($homeProductTabs)->firstWhere('slug', 'feature');
  $saleTab = collect($homeProductTabs)->firstWhere('slug', 'on-sale');
  $featuredProducts = collect($featureTab['products'] ?? []);
  $topSellingProducts = collect(data_get($homeSectionCollections, 'best_sellers.products', []));
  $dealProducts = collect($dealBlock['products'] ?? []);
  $saleProducts = collect($saleTab['products'] ?? []);

  $collectionUrl = function (string $slug) use ($blocks) {
    $exists = collect($blocks)->contains(fn ($block) => ($block['type'] ?? null) === 'collection' && ($block['collection']->slug ?? null) === $slug);
    return $exists ? route('store.collection.show', ['slug' => $slug]) : route('store.shop');
  };
@endphp

<div class="alm-home">
  <div class="alm-container alm-home-intro">
    @include('store.partials.almadina.hero')
    @include('store.partials.almadina.quick-categories')
  </div>

  <div class="alm-container alm-product-sections">
    @include('store.partials.almadina.product-section', [
      'title' => 'Featured Products',
      'slug' => 'featured-products',
      'products' => $featuredProducts,
      'viewAllUrl' => $collectionUrl('feature'),
      'showArrows' => true,
    ])

    @include('store.partials.almadina.product-section', [
      'title' => 'Top Selling Products',
      'slug' => 'top-selling-products',
      'products' => $topSellingProducts,
      'viewAllUrl' => $collectionUrl('best-sellers'),
    ])

    @include('store.partials.almadina.product-section', [
      'title' => 'Deal of the Day',
      'slug' => 'deal-of-the-day',
      'products' => $dealProducts,
      'viewAllUrl' => $collectionUrl('deal-of-the-day'),
      'isDeal' => true,
      'dealExpiresAt' => $dealExpiresAt,
    ])

    @include('store.partials.almadina.product-section', [
      'title' => 'On Sale',
      'slug' => 'on-sale',
      'products' => $saleProducts,
      'viewAllUrl' => $collectionUrl('on-sale'),
    ])

    @include('store.partials.almadina.trust-brands')
  </div>
</div>

@include('store.partials.home-modals-scripts', ['currency' => $currency, 'nlBtn' => $nlBtn])
@endsection
