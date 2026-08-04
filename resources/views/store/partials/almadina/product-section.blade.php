@php
  $sectionProducts = collect($products ?? [])->values();
  $sectionId = $slug ?? \Illuminate\Support\Str::slug($title);
  $viewAllUrl = $viewAllUrl ?? route('store.shop');
  $showArrows = (bool) ($showArrows ?? false);
  $isDeal = (bool) ($isDeal ?? false);
@endphp

<section class="alm-product-section" aria-labelledby="alm-section-{{ $sectionId }}">
  <div class="alm-section-heading">
    <div class="alm-section-title-wrap">
      <h2 id="alm-section-{{ $sectionId }}">{{ $title }}</h2>
      @if($isDeal)
        @include('store.partials.almadina.deal-countdown', ['expiresAt' => $dealExpiresAt ?? null])
      @endif
    </div>
    <a href="{{ $viewAllUrl }}" class="alm-view-all">View All <x-store.icon name="arrow-right" class="w-4 h-4" /></a>
  </div>

  <div class="alm-product-track-wrap">
    @if($showArrows && $sectionProducts->count() > 6)
      <button type="button" class="alm-carousel-arrow is-prev" data-home-carousel-scroll="{{ $sectionId }}" data-direction="-1" aria-label="Previous {{ $title }} products"><x-store.icon name="chevron-left" class="w-5 h-5" /></button>
    @endif

    <div class="alm-product-track" data-home-carousel="{{ $sectionId }}">
      @forelse($sectionProducts as $product)
        @include('store.partials.almadina.product-card', ['product' => $product, 'currency' => $currency, 's' => $s, 'isDeal' => $isDeal])
      @empty
        <p class="alm-empty-products">Products will appear here when this collection is populated.</p>
      @endforelse
    </div>

    @if($showArrows && $sectionProducts->count() > 6)
      <button type="button" class="alm-carousel-arrow is-next" data-home-carousel-scroll="{{ $sectionId }}" data-direction="1" aria-label="Next {{ $title }} products"><x-store.icon name="chevron-right" class="w-5 h-5" /></button>
    @endif
  </div>
</section>
