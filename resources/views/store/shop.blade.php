@extends('layouts.store')

@section('content')
@php
  $currency = $s->currency_code ?? '$';
@endphp

<div class="alm-shop-page">
  @include('store.partials.almadina.shop.breadcrumb')

  <section class="alm-shop-hero">
    <div class="alm-container">
      <div class="alm-shop-hero__copy">
        <h1>Shop Electronics &amp; Home Appliances</h1>
        <p>Discover top-quality electronics and home appliances at the best prices in Pakistan.</p>
      </div>
    </div>
  </section>

  <section class="alm-shop-results-section">
    <div class="alm-container">
      <div class="alm-shop-shell">
        <aside class="alm-shop-sidebar">
          @include('store.partials.almadina.shop.filters', [
            'q' => $q,
            'cat' => $cat,
            'brand' => $brand,
            'collection' => $collection,
            'min' => $min,
            'max' => $max,
            'sort' => $sort,
            'categories' => $categories,
            'brands' => $brands,
            'collections' => $collections,
          ])
        </aside>

        <main class="alm-shop-main">
          @include('store.partials.almadina.shop.grid', [
            's' => $s,
            'products' => $products,
            'categories' => $categories,
            'brands' => $brands,
            'collections' => $collections,
            'q' => $q,
            'cat' => $cat,
            'brand' => $brand,
            'collection' => $collection,
            'min' => $min,
            'max' => $max,
            'sort' => $sort,
            'currency' => $currency,
          ])
        </main>
      </div>
    </div>
  </section>

  <section class="alm-shop-confidence">
    <div class="alm-container">
      <div class="alm-shop-confidence__grid" aria-label="Shopping confidence benefits">
        <div><x-store.icon name="shield-check" class="w-9 h-9" /><span><strong>Genuine Products</strong><small>100% original &amp; authentic</small></span></div>
        <div><x-store.icon name="award" class="w-9 h-9" /><span><strong>Official Warranty</strong><small>Brand authorized warranty</small></span></div>
        <div><x-store.icon name="truck" class="w-9 h-9" /><span><strong>Fast Delivery</strong><small>Across Pakistan</small></span></div>
        <div><x-store.icon name="headset" class="w-9 h-9" /><span><strong>Customer Support</strong><small>We&apos;re here to help</small></span></div>
      </div>
    </div>
  </section>
</div>

<div x-data="drawer()" x-cloak id="filtersDrawer">
  <div x-show="isOpen" class="drawer-backdrop" x-transition.opacity @click="close()"></div>

  <aside class="drawer-panel drawer-end"
         x-show="isOpen"
         x-transition:enter="transition-transform duration-300"
         x-transition:enter-start="translate-x-full"
         x-transition:enter-end="translate-x-0"
         x-transition:leave="transition-transform duration-200"
         x-transition:leave-start="translate-x-0"
         x-transition:leave-end="translate-x-full"
         role="dialog"
         aria-modal="true"
         aria-label="Shop filters">
    <div class="drawer-header">
      <h5 class="font-semibold m-0">Filters</h5>
      <button type="button" class="btn btn-ghost btn-icon btn-sm" @click="close()" aria-label="Close filters">
        <x-store.icon name="x" class="w-5 h-5" />
      </button>
    </div>
    <div class="drawer-body">
      @include('store.partials.almadina.shop.filters', [
        'q' => $q,
        'cat' => $cat,
        'brand' => $brand,
        'collection' => $collection,
        'min' => $min,
        'max' => $max,
        'sort' => $sort,
        'categories' => $categories,
        'brands' => $brands,
        'collections' => $collections,
        'isDrawer' => true,
      ])
    </div>
  </aside>
</div>

@include('store.partials.shop-modals-scripts', ['currency' => $currency])
@endsection
