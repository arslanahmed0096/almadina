@php
  $currency = $currency ?? ($s->currency_code ?? '$');
  $hasFilters = filled($q ?? null) || filled($cat ?? null) || filled($brand ?? null) || filled($collection ?? null) || filled($min ?? null) || filled($max ?? null);
@endphp

<section class="alm-shop-results-card">
  <div class="alm-shop-results-top">
    <div>
      <p>Showing {{ $products->firstItem() ?? 0 }}-{{ $products->lastItem() ?? 0 }} of {{ number_format($products->total()) }} products</p>
      @if($hasFilters)
        <div class="alm-shop-chips">
          @if(filled($q))
            <a href="{{ route('store.shop', request()->except('q', 'page')) }}">Search: {{ $q }} <x-store.icon name="x" class="w-3 h-3" /></a>
          @endif
          @if(filled($cat))
            @php $catName = optional($categories->firstWhere('id', $cat))->name ?? $cat; @endphp
            <a href="{{ route('store.shop', request()->except('category', 'page')) }}">{{ $catName }} <x-store.icon name="x" class="w-3 h-3" /></a>
          @endif
          @if(filled($brand))
            @php $brandName = optional($brands->firstWhere('id', (int) $brand))->name ?? $brand; @endphp
            <a href="{{ route('store.shop', request()->except('brand', 'page')) }}">{{ $brandName }} <x-store.icon name="x" class="w-3 h-3" /></a>
          @endif
          @if(filled($collection))
            @php
              $collectionMatch = $collections->first(fn ($item) => (string) $item->slug === (string) $collection || (string) $item->id === (string) $collection);
            @endphp
            <a href="{{ route('store.shop', request()->except('collection', 'page')) }}">{{ $collectionMatch->title ?? $collection }} <x-store.icon name="x" class="w-3 h-3" /></a>
          @endif
          @if(filled($min))
            <a href="{{ route('store.shop', request()->except('min', 'page')) }}">Min {{ number_format((float) $min, 0, '.', ',') }} <x-store.icon name="x" class="w-3 h-3" /></a>
          @endif
          @if(filled($max))
            <a href="{{ route('store.shop', request()->except('max', 'page')) }}">Max {{ number_format((float) $max, 0, '.', ',') }} <x-store.icon name="x" class="w-3 h-3" /></a>
          @endif
          <a class="is-clear" href="{{ route('store.shop') }}">Clear All</a>
        </div>
      @endif
    </div>

    <div class="alm-shop-toolbar">
      <button type="button" class="alm-shop-mobile-filter" @click="window.StoreUI.open('filtersDrawer')">
        <x-store.icon name="sliders" class="w-4 h-4" /> Filters
      </button>

      <form method="get" action="{{ route('store.shop') }}" class="alm-shop-sort-form">
        @foreach(request()->except(['sort', 'page']) as $k => $v)
          @if(is_array($v))
            @foreach($v as $vv)
              <input type="hidden" name="{{ $k }}[]" value="{{ $vv }}">
            @endforeach
          @else
            <input type="hidden" name="{{ $k }}" value="{{ $v }}">
          @endif
        @endforeach
        <label for="almShopSort" class="sr-only">Sort products</label>
        <div class="alm-shop-sort-select">
          <select id="almShopSort" name="sort" onchange="this.form.submit()">
            <option value="latest" @selected(($sort ?? 'latest') === 'latest')>Sort by: Latest</option>
            <option value="price_asc" @selected(($sort ?? 'latest') === 'price_asc')>Sort by: Price Low to High</option>
            <option value="price_desc" @selected(($sort ?? 'latest') === 'price_desc')>Sort by: Price High to Low</option>
          </select>
          <x-store.icon name="chevron-down" class="w-5 h-5" />
        </div>
      </form>

      <div class="alm-shop-view-toggle" aria-hidden="true">
        <span class="is-active"><x-store.icon name="grid" class="w-5 h-5" /></span>
        <span><x-store.icon name="list" class="w-5 h-5" /></span>
      </div>
    </div>
  </div>

  @if($products->count())
    <div class="alm-shop-product-grid">
      @foreach($products as $product)
        @include('store.partials.almadina.shop.product-card', ['product' => $product, 'currency' => $currency])
      @endforeach
    </div>

    @if($products->hasPages())
      @php
        $products->appends(request()->except('page'));
        $current = $products->currentPage();
        $last = $products->lastPage();
        $window = 1;
        $pages = collect([1, $last])
          ->merge(range(max(1, $current - $window), min($last, $current + $window)))
          ->unique()
          ->sort()
          ->values();
        $prev = null;
      @endphp

      <nav class="alm-shop-pagination" aria-label="Product pagination">
        @if($products->onFirstPage())
          <span class="is-disabled">Previous</span>
        @else
          <a href="{{ $products->previousPageUrl() }}">Previous</a>
        @endif

        @foreach($pages as $page)
          @if(!is_null($prev) && $page - $prev > 1)
            <span class="is-gap">...</span>
          @endif

          @if($page == $current)
            <span class="is-current">{{ $page }}</span>
          @else
            <a href="{{ $products->url($page) }}">{{ $page }}</a>
          @endif

          @php $prev = $page; @endphp
        @endforeach

        @if($products->hasMorePages())
          <a href="{{ $products->nextPageUrl() }}">Next</a>
        @else
          <span class="is-disabled">Next</span>
        @endif
      </nav>
    @endif
  @else
    <div class="alm-shop-empty-state">
      <x-store.icon name="package" class="w-14 h-14" />
      <h2>No products found</h2>
      <p>Try adjusting your filters or browse the full catalog to explore more products.</p>
      <a href="{{ route('store.shop') }}">Clear Filters</a>
    </div>
  @endif
</section>
