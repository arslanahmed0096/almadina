@php
  $categoryNav = collect([
    ['label' => 'Televisions', 'icon' => 'monitor', 'needles' => ['television', 'tv', 'led']],
    ['label' => 'Refrigerators', 'icon' => 'refrigerator', 'needles' => ['refrigerator', 'fridge', 'freezer']],
    ['label' => 'Washing Machines', 'icon' => 'washing-machine', 'needles' => ['washing', 'washer']],
    ['label' => 'Air Conditioners', 'icon' => 'air-conditioner', 'needles' => ['air condition', 'split ac']],
    ['label' => 'Kitchen Appliances', 'icon' => 'cooking-pot', 'needles' => ['kitchen', 'cooking']],
    ['label' => 'Small Appliances', 'icon' => 'package', 'needles' => ['small appliance', 'home appliance']],
  ])->map(function ($item) use ($categories) {
    $item['category'] = collect($categories)->first(function ($category) use ($item) {
      $name = strtolower((string) $category->name);
      return collect($item['needles'])->contains(fn ($needle) => str_contains($name, $needle));
    });
    return $item;
  });
@endphp

<header class="alm-site-header">
  <div class="alm-utility-bar">
    <div class="alm-container">
      <span class="alm-delivery"><x-store.icon name="map-pin" class="w-4 h-4" /> Delivering to: <strong>All Pakistan</strong></span>
      <nav aria-label="Utility navigation">
        <a href="{{ $client ? $ordersUrl : $loginUrl }}"><x-store.icon name="truck" class="w-4 h-4" /> Track Order</a>
        <a href="{{ route('store.contact') }}#branches"><x-store.icon name="store" class="w-4 h-4" /> Store Locator</a>
        <a href="{{ route('store.contact') }}"><x-store.icon name="headset" class="w-4 h-4" /> Help &amp; Support</a>
        <a href="{{ route('store.shop', ['deals' => 1]) }}"><x-store.icon name="briefcase" class="w-4 h-4" /> Business Deals</a>
      </nav>
    </div>
  </div>

  <div class="alm-main-header">
    <div class="alm-container alm-main-header-grid">
      <button type="button" class="alm-mobile-menu" @click="window.StoreUI.open('mobileCategorySidebar')" aria-label="Open category menu"><x-store.icon name="menu" class="w-6 h-6" /></button>

      <a href="{{ route('store.index') }}" class="alm-logo" aria-label="{{ $s->store_name ?? 'Al Madina Electronics' }} home">
        @if(!empty($s->logo_path))
          <img src="{{ $assetPath($s->logo_path) }}" alt="{{ $s->store_name ?? 'Al Madina Electronics' }}" width="220" height="64">
        @else
          <span><strong>AL MADINA</strong><small>ELECTRONICS &amp; HOME APPLIANCES</small></span>
        @endif
      </a>

      <div class="alm-search" x-data="searchBox('{{ route('store.search.suggestions') }}')" @click.outside="results = []">
        <form action="{{ route('store.shop') }}" method="GET" role="search">
          <label for="alm-home-search" class="sr-only">Search products</label>
          <input id="alm-home-search" type="search" name="q" placeholder="Search for products, brands and more…" x-model="q" @input.debounce.250ms="fetch" autocomplete="off">
          <button type="submit" aria-label="Search"><x-store.icon name="search" class="w-5 h-5" /></button>
          <div x-show="results.length" x-cloak class="alm-search-results">
            <template x-for="product in results" :key="product.id">
              <a :href="product.url"><img :src="product.image_url" alt="" width="48" height="48"><span><b x-text="product.name"></b><small x-text="product.display_price"></small></span></a>
            </template>
          </div>
        </form>
      </div>

      <div class="alm-header-actions">
        @if($client)
          <a href="{{ $accountUrl }}" class="alm-header-action"><x-store.icon name="user" class="w-6 h-6" /><span><strong>My Account</strong><small>{{ $displayName }}</small></span></a>
        @else
          <button type="button" class="alm-header-action" @click="$dispatch('account-login'); window.StoreUI.open('authModal')"><x-store.icon name="user" class="w-6 h-6" /><span><strong>My Account</strong><small>Sign In / Register</small></span></button>
        @endif
        <button type="button" class="alm-header-action alm-header-wishlist" aria-label="Wishlist"><span class="alm-action-icon"><x-store.icon name="heart" class="w-6 h-6" /><i class="wishlist-count">0</i></span><span><strong>Wishlist</strong><small class="wishlist-label">0 items</small></span></button>
        <button type="button" class="alm-header-action alm-header-cart" @click="window.StoreUI.open('miniCart')" aria-label="Open cart"><span class="alm-action-icon"><x-store.icon name="cart" class="w-6 h-6" /><i class="cart-count">0</i></span><span><strong>Cart</strong><small>View basket</small></span></button>
      </div>
    </div>
  </div>

  <nav class="alm-category-nav" aria-label="Product categories">
    <div class="alm-container">
      <button type="button" class="alm-all-categories" @click="window.StoreUI.open('mobileCategorySidebar')"><x-store.icon name="menu" class="w-5 h-5" /> All Categories</button>
      @foreach($categoryNav as $item)
        @php
          $category = $item['category'];
          $categoryUrl = $category ? route('store.shop', ['category' => $category->id]) : route('store.shop', ['q' => $item['label']]);
          $active = $category && (string) request('category') === (string) $category->id;
        @endphp
        <a href="{{ $categoryUrl }}" @class(['active' => $active])><x-store.icon :name="$item['icon']" class="w-5 h-5" /> {{ $item['label'] }}</a>
      @endforeach
      <a href="{{ route('store.shop', ['deals' => 1]) }}" class="alm-deals-link"><x-store.icon name="tag" class="w-5 h-5" /> Deals</a>
    </div>
  </nav>
</header>
