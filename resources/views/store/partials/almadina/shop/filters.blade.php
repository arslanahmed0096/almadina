@php
  $isDrawer = $isDrawer ?? false;
  $hasFilters = filled($q ?? null) || filled($cat ?? null) || filled($brand ?? null) || filled($collection ?? null) || filled($min ?? null) || filled($max ?? null);
@endphp

<form method="get" action="{{ route('store.shop') }}" class="alm-shop-filters-card">
  @foreach(request()->except(['page']) as $k => $v)
    @if(!in_array($k, ['q', 'category', 'brand', 'collection', 'min', 'max', 'sort']))
      @if(is_array($v))
        @foreach($v as $vv)
          <input type="hidden" name="{{ $k }}[]" value="{{ $vv }}">
        @endforeach
      @else
        <input type="hidden" name="{{ $k }}" value="{{ $v }}">
      @endif
    @endif
  @endforeach

  <div class="alm-shop-filters-card__head">
    <h2>Filters</h2>
    @if($hasFilters)
      <a href="{{ route('store.shop') }}">Clear All</a>
    @else
      <span></span>
    @endif
  </div>

  <div class="alm-shop-filter-group">
    <label for="{{ $isDrawer ? 'shopSearchMobile' : 'shopSearchDesktop' }}">Search Products</label>
    <div class="alm-shop-search-field">
      <input
        id="{{ $isDrawer ? 'shopSearchMobile' : 'shopSearchDesktop' }}"
        type="search"
        name="q"
        value="{{ $q }}"
        placeholder="Search products..."
      >
      <x-store.icon name="search" class="w-5 h-5" />
    </div>
  </div>

  <div class="alm-shop-filter-group">
    <label for="{{ $isDrawer ? 'shopCategoryMobile' : 'shopCategoryDesktop' }}">Category</label>
    <div class="alm-shop-select">
      <select id="{{ $isDrawer ? 'shopCategoryMobile' : 'shopCategoryDesktop' }}" name="category">
        <option value="">All Categories</option>
        @foreach($categories as $category)
          <option value="{{ $category->id }}" @selected((string) $cat === (string) $category->id)>{{ $category->name }}</option>
        @endforeach
      </select>
      <x-store.icon name="chevron-down" class="w-5 h-5" />
    </div>
  </div>

  <div class="alm-shop-filter-group">
    <label for="{{ $isDrawer ? 'shopCollectionMobile' : 'shopCollectionDesktop' }}">Collection</label>
    <div class="alm-shop-select">
      <select id="{{ $isDrawer ? 'shopCollectionMobile' : 'shopCollectionDesktop' }}" name="collection">
        <option value="">All Collections</option>
        @foreach($collections as $item)
          @php
            $selected = (string) $collection === (string) $item->slug || (string) $collection === (string) $item->id;
          @endphp
          <option value="{{ $item->slug }}" @selected($selected)>{{ $item->title ?: $item->slug }}</option>
        @endforeach
      </select>
      <x-store.icon name="chevron-down" class="w-5 h-5" />
    </div>
  </div>

  <div class="alm-shop-filter-group">
    <label for="{{ $isDrawer ? 'shopBrandMobile' : 'shopBrandDesktop' }}">Brand</label>
    <div class="alm-shop-select">
      <select id="{{ $isDrawer ? 'shopBrandMobile' : 'shopBrandDesktop' }}" name="brand">
        <option value="">All Brands</option>
        @foreach($brands as $item)
          <option value="{{ $item->id }}" @selected((string) $brand === (string) $item->id)>{{ $item->name }}</option>
        @endforeach
      </select>
      <x-store.icon name="chevron-down" class="w-5 h-5" />
    </div>
  </div>

  <div class="alm-shop-price-grid">
    <div class="alm-shop-filter-group">
      <label for="{{ $isDrawer ? 'shopMinMobile' : 'shopMinDesktop' }}">Min Price</label>
      <input id="{{ $isDrawer ? 'shopMinMobile' : 'shopMinDesktop' }}" type="number" min="0" step="0.01" name="min" value="{{ $min }}" placeholder="Min Price (Rs.)">
    </div>
    <div class="alm-shop-filter-group">
      <label for="{{ $isDrawer ? 'shopMaxMobile' : 'shopMaxDesktop' }}">Max Price</label>
      <input id="{{ $isDrawer ? 'shopMaxMobile' : 'shopMaxDesktop' }}" type="number" min="0" step="0.01" name="max" value="{{ $max }}" placeholder="Max Price (Rs.)">
    </div>
  </div>

  <input type="hidden" name="sort" value="{{ $sort ?? 'latest' }}">

  <button type="submit" class="alm-shop-apply-btn">{{ $isDrawer ? 'Apply & Close' : 'Apply Filters' }}</button>
</form>
