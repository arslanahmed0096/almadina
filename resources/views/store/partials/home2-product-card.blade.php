@php
  $productHref = route('store.shop', ['q' => $item['title']]);
  $compact = $compact ?? false;
  $showProgress = $showProgress ?? false;
@endphp

<article class="home2-product-card {{ $compact ? 'is-compact' : '' }}">
  <div class="home2-product-media">
    <a href="{{ $productHref }}" aria-label="{{ $item['title'] }}">
      <img class="home2-product-image" src="{{ $item['image'] }}" alt="{{ $item['title'] }}">
      <img class="home2-product-image-hover" src="{{ $item['hover'] ?? $item['image'] }}" alt="" aria-hidden="true">
    </a>

    @if(!empty($item['sale']))
      <span class="home2-sale-badge"><small>Sale</small>{{ $item['sale'] }}</span>
    @endif

    <div class="home2-product-actions" aria-label="Product actions">
      <a href="{{ $productHref }}" title="Add to cart"><x-store.icon name="bag" class="w-4 h-4" /></a>
      <a href="{{ $productHref }}" title="Add to wishlist"><x-store.icon name="heart" class="w-4 h-4" /></a>
      <a href="{{ $productHref }}" title="Quick view"><x-store.icon name="eye" class="w-4 h-4" /></a>
      <a href="{{ $productHref }}" title="Compare"><x-store.icon name="copy" class="w-4 h-4" /></a>
    </div>
  </div>

  <div class="home2-product-info">
    <small>{{ $item['category'] }}</small>
    <a class="home2-product-title" href="{{ $productHref }}">{{ $item['title'] }}</a>
    <div class="home2-product-prices">
      <strong>{{ $currency }}{{ $item['price'] }}</strong>
      @if(!empty($item['old']))<del>{{ $currency }}{{ $item['old'] }}</del>@endif
    </div>

    @if($showProgress)
      @php
        $sold = $item['sold'] ?? 30;
        $available = $item['available'] ?? 70;
        $progress = max(5, min(100, round(($sold / max(1, $sold + $available)) * 100)));
      @endphp
      <div class="home2-stock-progress"><i style="width: {{ $progress }}%"></i></div>
      <div class="home2-stock-labels"><span>Sold: <b>{{ $sold }}</b></span><span>Available: <b>{{ $available }}</b></span></div>
    @endif
  </div>
</article>
