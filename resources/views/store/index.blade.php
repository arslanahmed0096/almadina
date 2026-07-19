@extends('layouts.store')

@section('content')
@php
  $currency = $s->currency_code ?? '$';
  $nlBtn = __('messages.Subscribe');
  $ref = fn (string $path) => asset('images/store/home-2/reference/images/' . ltrim($path, '/'));

  $coverCards = [
    ['category' => 'Headphone', 'title' => 'Apple AirPods Max - Premium Over-Ear Wireless Headphones', 'price' => '51.500', 'old' => '64.990', 'image' => $ref('product/product-83.jpg')],
    ['category' => 'Smart TVs', 'title' => 'Amazon Fire TV Omni (65-inch) - 4K UHD Smart TV with Hands-Free Alexa', 'price' => '68.499', 'old' => '85.999', 'image' => $ref('product/product-12.jpg')],
  ];

  $categoryTiles = [
    ['title' => 'Laptops & Accessories', 'image' => 'collection/cls-grid-1.jpg'],
    ['title' => 'Phones, Tablets & Accessories', 'image' => 'collection/cls-grid-2.jpg'],
    ['title' => 'Apple Products', 'image' => 'collection/cls-grid-3.jpg'],
    ['title' => 'Server & Workstation', 'image' => 'collection/cls-grid-4.jpg'],
    ['title' => 'Game Controller', 'image' => 'collection/cls-grid-5.jpg'],
    ['title' => 'Audio Equipments', 'image' => 'collection/cls-grid-6.jpg'],
    ['title' => 'Storage & Digital Devices', 'image' => 'collection/cls-grid-7.jpg'],
    ['title' => 'Audio Equipments', 'image' => 'collection/cls-grid-8.jpg'],
  ];

  $dealProducts = [
    ['category' => 'Game Consoles', 'title' => 'Sony PlayStation 5 (PS5) - Next-Gen Gaming Console with Ultra-Fast SSD & 4K Graphics', 'price' => '71.500', 'old' => '89.990', 'sale' => '28%', 'sold' => 21, 'available' => 58, 'image' => $ref('product/product-81.jpg'), 'hover' => $ref('product/product-21.jpg')],
    ['category' => 'Smart TVs', 'title' => 'TCL 32-inch 3-Series 720p Roku Smart TV - 32S335, 2021 Model', 'price' => '63.070', 'old' => '92.750', 'sale' => '32%', 'sold' => 41, 'available' => 59, 'image' => $ref('product/product-detail-14.jpg'), 'hover' => $ref('product/product-detail-16.jpg')],
    ['category' => 'Headphone', 'title' => 'Logitech M510 Wireless Computer Mouse for PC with USB Unifying Receiver', 'price' => '61.860', 'old' => '75.500', 'sale' => '18%', 'sold' => 22, 'available' => 78, 'image' => $ref('product/product-81.jpg'), 'hover' => $ref('product/product-11.jpg')],
    ['category' => 'Smartphone', 'title' => 'SAMSUNG Galaxy Z Flip Factory Unlocked Cell Phone', 'price' => '74.999', 'old' => '99.999', 'sale' => '25%', 'sold' => 70, 'available' => 30, 'image' => $ref('product/product-39.jpg'), 'hover' => $ref('product/product-56.jpg')],
    ['category' => 'Smartphone', 'title' => 'Samsung Electronics Samsung Galaxy S21 5G Factory Unlocked Android', 'price' => '69.700', 'old' => '85.000', 'sold' => 62, 'available' => 45, 'image' => $ref('product/product-40.jpg'), 'hover' => $ref('product/product-detail-6.jpg')],
  ];

  $featureProducts = [
    ['category' => 'Headphone', 'title' => 'Audio-Technica ATH-MSR7 - SonicPro Over-Ear Headphones with High-Resolution Audio', 'price' => '72.000', 'old' => '92.750', 'image' => $ref('product/product-41.jpg'), 'hover' => $ref('product/product-detail-9.jpg')],
    ['category' => 'Smart Glasses', 'title' => 'Solos Smart Eyewear - Official Sponsor of USA Cycling with Advanced Performance Features', 'price' => '36.500', 'old' => '45.900', 'image' => $ref('product/product-42.jpg'), 'hover' => $ref('product/product-42.jpg')],
    ['category' => 'Electronics', 'title' => 'SteelSeries Aerox 9 Wireless - Ultra-Lightweight Gaming Mouse with 12 Buttons', 'price' => '87.500', 'old' => '92.750', 'image' => $ref('product/product-43.jpg'), 'hover' => $ref('product/product-11.jpg')],
    ['category' => 'Electronics', 'title' => 'Turtle Beach Stream Mic - High-Quality Audio Performance for Streaming', 'price' => '42.700', 'old' => '53.990', 'image' => $ref('product/product-44.jpg'), 'hover' => $ref('product/product-44.jpg')],
    ['category' => 'Game Controllers', 'title' => 'IPEGA PG-9021 Wireless Bluetooth Gamepad - Controller for Android & iOS', 'price' => '45.500', 'old' => '56.800', 'image' => $ref('product/product-45.jpg'), 'hover' => $ref('product/product-46.jpg')],
  ];

  $trendingProducts = [
    ['category' => 'Gaming Mice', 'title' => 'Klim Blaze Rechargeable Wireless Gaming Mouse - RGB Lighting', 'price' => '15.400', 'old' => '19.800', 'image' => $ref('product/product-11.jpg'), 'hover' => $ref('product/product-38.jpg')],
    ['category' => 'Game Consoles', 'title' => 'IINE PS5 Controller Case Cover Silicone Protective Cover', 'price' => '35.200', 'old' => '28.000', 'image' => $ref('product/product-46.jpg'), 'hover' => $ref('product/product-66.jpg')],
    ['category' => 'Computer Accessories', 'title' => 'AutoFull C3 Pro Gaming Chair - Enhanced Cushion for Comfort', 'price' => '38.400', 'old' => '48.990', 'image' => $ref('product/product-13.jpg'), 'hover' => $ref('product/product-13.jpg')],
    ['category' => 'Electronics', 'title' => 'Beats Solo3 Wireless - On-Ear Headphones with Apple W1 Chip', 'price' => '26.900', 'old' => '33.800', 'image' => $ref('product/product-9.jpg'), 'hover' => $ref('product/product-2.jpg')],
    ['category' => 'Camera & Accessories', 'title' => 'Nikon D3500 W/ AF-P DX NIKKOR 18-55mm Camera', 'price' => '64.999', 'old' => '79.999', 'image' => $ref('product/product-47.jpg'), 'hover' => $ref('product/product-80.jpg')],
  ];

  $bestSellerProducts = [
    ['category' => 'Electronics', 'title' => 'B&O Beoplay A1 - Premium Portable Bluetooth Speaker with Superior Sound', 'price' => '43.500', 'old' => '54.600', 'image' => $ref('product/product-48.jpg'), 'hover' => $ref('product/product-83.jpg')],
    ['category' => 'Wireless Earbuds', 'title' => 'Sony WF-SP700N - Bluetooth Noise-Canceling Earbuds with Secure Fit', 'price' => '24.400', 'old' => '30.999', 'image' => $ref('product/product-30.jpg'), 'hover' => $ref('product/product-14.jpg')],
    ['category' => 'Smart TVs', 'title' => 'TCL 32-inch 3-Series 720p Roku Smart TV - 32S335', 'price' => '71.000', 'old' => '88.750', 'image' => $ref('product/product-12.jpg'), 'hover' => $ref('product/product-122.jpg')],
  ];

  $smartProducts = [
    ['category' => 'Electronics', 'title' => 'TOKERSE Firestick Remote Cover Case 3rd Gen', 'price' => '14.500', 'old' => '18.600', 'image' => $ref('product/product-50.jpg'), 'hover' => $ref('product/product-50.jpg')],
    ['category' => 'Smartphone', 'title' => 'HTC Desire 20+ - Mid-Range Chipset with Long-Lasting Battery', 'price' => '49.900', 'old' => '62.300', 'image' => $ref('product/product-53.jpg'), 'hover' => $ref('product/product-detail-2.jpg')],
    ['category' => 'Laptop & Computer', 'title' => 'ASUS ZenBook Flip 13 - Ultra-Slim Convertible Laptop', 'price' => '60.200', 'old' => '76.900', 'image' => $ref('product/product-51.jpg'), 'hover' => $ref('product/product-19.jpg')],
    ['category' => 'Wireless Earbuds', 'title' => 'Apple AirPods Pro (1st Gen) - Select Right, Left, or Both', 'price' => '33.000', 'old' => '41.600', 'image' => $ref('product/product-54.jpg'), 'hover' => $ref('product/product-30.jpg')],
    ['category' => 'Smartphone', 'title' => 'SAMSUNG Galaxy Z Flip Factory Unlocked Cell Phone', 'price' => '73.200', 'old' => '91.450', 'image' => $ref('product/product-39.jpg'), 'hover' => $ref('product/product-56.jpg')],
    ['category' => 'Headphone', 'title' => 'Beats Studio3 Wireless On-Ear Headphones', 'price' => '47.500', 'old' => '59.300', 'image' => $ref('product/product-75.jpg'), 'hover' => $ref('product/product-20.jpg')],
    ['category' => 'Laptop & Computer', 'title' => 'Acer Chromebook Spin 713 - 13.5-inch Convertible', 'price' => '65.800', 'old' => '82.600', 'image' => $ref('product/product-52.jpg'), 'hover' => $ref('product/product-19.jpg')],
    ['category' => 'Smartphone', 'title' => 'Apple iPhone 11 Pro Max, 256GB, Space Gray', 'price' => '29.100', 'old' => '36.450', 'image' => $ref('product/product-55.jpg'), 'hover' => $ref('product/product-25.jpg')],
    ['category' => 'Electronics', 'title' => 'TOKERSE Firestick Remote Cover Case 3rd Gen', 'price' => '14.500', 'old' => '18.600', 'image' => $ref('product/product-50.jpg'), 'hover' => $ref('product/product-50.jpg')],
    ['category' => 'Smartphone', 'title' => 'HTC Desire 20+ - Mid-Range Chipset with Long-Lasting Battery', 'price' => '49.900', 'old' => '62.300', 'image' => $ref('product/product-53.jpg'), 'hover' => $ref('product/product-detail-2.jpg')],
  ];

  $recentProducts = [
    ['category' => 'Headphone', 'title' => 'Urbanears Pampas - Wireless Over-Ear Headphones', 'price' => '48.990', 'image' => $ref('product/product-134.jpg'), 'hover' => $ref('product/product-detail-12.jpg')],
    ['category' => 'Headphone', 'title' => 'Upgrader Headphones - Altec Lansing by ECCO Design', 'price' => '27.500', 'image' => $ref('product/product-2.jpg'), 'hover' => $ref('product/product-detail-9.jpg')],
    ['category' => 'Smartwatch', 'title' => 'Apple Watch Series 6 (GPS) - 40mm Aluminum Case', 'price' => '63.999', 'image' => $ref('product/product-3.jpg'), 'hover' => $ref('product/product-10.jpg')],
    ['category' => 'Laptop & Computer', 'title' => 'Lenovo Yoga 910 - 2-in-1 Ultrabook with Touchscreen', 'price' => '39.990', 'image' => $ref('product/product-4.jpg'), 'hover' => $ref('product/product-19.jpg')],
    ['category' => 'Wireless Earphones', 'title' => 'JBL LIVE200BT - Wireless Neckband Earphones', 'price' => '14.999', 'image' => $ref('product/product-5.jpg'), 'hover' => $ref('product/product-153.jpg')],
    ['category' => 'Electronics', 'title' => 'SteelSeries Aerox 9 Wireless Gaming Mouse', 'price' => '87.500', 'image' => $ref('product/product-6.jpg'), 'hover' => $ref('product/product-43.jpg')],
  ];

  $catchTiles = [
    ['label' => 'headphones', 'sale' => '20%', 'image' => 'collection/cls-category-1.jpg', 'dark' => true],
    ['label' => 'cameras', 'sale' => '15%', 'image' => 'collection/cls-category-2.jpg', 'dark' => true],
    ['label' => 'phones', 'sale' => '28%', 'image' => 'collection/cls-category-3.jpg', 'dark' => true],
    ['label' => 'watches', 'sale' => '22%', 'image' => 'collection/cls-category-4.jpg', 'dark' => false],
  ];
@endphp

<div class="onsus-home home2-page">
  <section class="onsus-cover">
    <div class="container">
      <div class="onsus-cover-inner">
        <div class="onsus-cover-feature">
          <a href="{{ route('store.shop') }}" class="onsus-cover-product-image"><img src="{{ $ref('item/dou-headphone.png') }}" alt="Silver over-ear headphones"></a>
          <div class="onsus-cover-copy">
            <span class="onsus-cover-kicker">New arrival</span>
            <h1><span>Headset &amp;</span><strong>Headphone</strong></h1>
            <span class="onsus-cover-starting">Starting</span>
            <b class="onsus-cover-price">{{ $currency }}250</b>
          </div>
        </div>
        <div class="onsus-cover-cards">
          @foreach($coverCards as $card)
            <a href="{{ route('store.shop', ['q' => $card['title']]) }}" class="onsus-cover-card">
              <span class="onsus-cover-card-image"><img src="{{ $card['image'] }}" alt="{{ $card['title'] }}"></span>
              <span class="onsus-cover-card-info"><small>{{ $card['category'] }}</small><strong>{{ $card['title'] }}</strong><span class="onsus-cover-card-price">{{ $currency }}{{ $card['price'] }}</span><del>{{ $currency }}{{ $card['old'] }}</del></span>
            </a>
          @endforeach
        </div>
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
      @foreach($categoryTiles as $index => $tile)
        @php $category = $categories->get($index); @endphp
        <a href="{{ $category ? route('store.shop', ['category' => $category->id]) : route('store.shop', ['q' => $tile['title']]) }}">
          <img src="{{ $ref($tile['image']) }}" alt="{{ $tile['title'] }}"><span>{{ $tile['title'] }}</span>
        </a>
      @endforeach
    </section>

    <section class="home2-section home2-deals">
      <div class="home2-section-title"><div><span class="home2-title-icon"><x-store.icon name="lightning" class="w-4 h-4" /></span><h2>Deal Of The Day</h2></div><div class="home2-arrows"><button aria-label="Previous"><x-store.icon name="chevron-left" class="w-4 h-4" /></button><button aria-label="Next"><x-store.icon name="chevron-right" class="w-4 h-4" /></button></div></div>
      <div class="home2-products home2-products-five">
        @foreach($dealProducts as $item)
          @include('store.partials.home2-product-card', ['item' => $item, 'currency' => $currency, 'showProgress' => true])
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

    <section class="home2-section home2-featured">
      <div class="home2-section-title home2-tabs"><nav><button class="active">Feature</button><button>Toprate</button><button>On sale</button></nav></div>
      <div class="home2-products home2-products-five">
        @foreach($featureProducts as $item)
          @include('store.partials.home2-product-card', ['item' => $item, 'currency' => $currency])
        @endforeach
      </div>
    </section>

    <section class="home2-section">
      <div class="home2-section-title"><div><h2>Trending Products</h2></div><div class="home2-arrows"><button aria-label="Previous"><x-store.icon name="chevron-left" class="w-4 h-4" /></button><button aria-label="Next"><x-store.icon name="chevron-right" class="w-4 h-4" /></button></div></div>
      <div class="home2-products home2-products-five">
        @foreach($trendingProducts as $item)
          @include('store.partials.home2-product-card', ['item' => $item, 'currency' => $currency])
        @endforeach
      </div>
    </section>

    <a href="{{ route('store.shop') }}" class="home2-camera-banner" style="--promo-bg:url('{{ $ref('banner/banner-2.jpg') }}')">
      <div><span>Shop and <b>SAVE BIG</b></span><small>on hottest camera</small></div><span class="home2-camera-sale">Save<br><b>{{ $currency }}67.700</b></span><img class="camera-one" src="{{ $ref('item/camera-2.png') }}" alt="Security camera"><img class="camera-two" src="{{ $ref('item/camera-3.png') }}" alt="Digital camera">
    </a>

    <section class="home2-section home2-best-sellers">
      <div class="home2-section-title"><div><h2>Best Sellers</h2></div><div class="home2-arrows"><button aria-label="Previous"><x-store.icon name="chevron-left" class="w-4 h-4" /></button><button aria-label="Next"><x-store.icon name="chevron-right" class="w-4 h-4" /></button></div></div>
      <div class="home2-best-grid">
        <a href="{{ route('store.shop') }}" class="home2-tv-banner" style="--promo-bg:url('{{ $ref('banner/banner-7.jpg') }}')"><span>Samsung<br><b>8K TV</b><strong>70<sup>in</sup></strong><small>For a limited time, get a free 4K TV when you join</small></span><img src="{{ $ref('item/tivi-2.png') }}" alt="Samsung 8K television"></a>
        @foreach($bestSellerProducts as $item)
          @include('store.partials.home2-product-card', ['item' => $item, 'currency' => $currency])
        @endforeach
      </div>
    </section>

    <section class="home2-section home2-smart-section">
      <div class="home2-section-title"><div><h2>Smart Home Appliances</h2></div><div class="home2-arrows"><button aria-label="Previous"><x-store.icon name="chevron-left" class="w-4 h-4" /></button><button aria-label="Next"><x-store.icon name="chevron-right" class="w-4 h-4" /></button></div></div>
      <div class="home2-smart-grid">
        @foreach($smartProducts as $item)
          @include('store.partials.home2-product-card', ['item' => $item, 'currency' => $currency, 'compact' => true])
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
      <div class="home2-section-title"><div><h2>Recently Viewed</h2></div><div class="home2-arrows"><button aria-label="Previous"><x-store.icon name="chevron-left" class="w-4 h-4" /></button><button aria-label="Next"><x-store.icon name="chevron-right" class="w-4 h-4" /></button></div></div>
      <div class="home2-products home2-products-six">
        @foreach($recentProducts as $item)
          @include('store.partials.home2-product-card', ['item' => $item, 'currency' => $currency])
        @endforeach
      </div>
    </section>
  </div>

  <section class="onsus-newsletter home2-newsletter">
    <div class="container"><strong><x-store.icon name="mail" class="w-5 h-5" /> 10% Off Your First Order</strong><span>Be the first to know about offers, new products and discounted products</span><form id="newsletterForm">@csrf<input name="email" id="newsletterEmail" type="email" placeholder="Enter your email address" required><button id="newsletterBtn" type="submit">{{ $nlBtn }}</button></form><div id="newsletterMsg"></div></div>
  </section>

  <div class="home2-newsletter-popup" x-data="{ open: false }" x-init="setTimeout(() => open = true, 2000)" @account-login.window="open = false" @keydown.escape.window="open = false" x-show="open" x-cloak>
    <div class="home2-popup-backdrop" @click="open = false"></div>
    <div class="home2-popup-dialog" role="dialog" aria-modal="true" aria-labelledby="home2-popup-title" x-transition>
      <button type="button" class="home2-popup-close" @click="open = false" aria-label="Close"><x-store.icon name="x" class="w-5 h-5" /></button>
      <h2 id="home2-popup-title">Join our newsletter for {{ $currency }}10 off</h2>
      <p>Register now to get latest updates on promotions &amp; coupons.<br>Don't worry, we do not spam!</p>
      <form @submit.prevent="open = false"><input type="email" placeholder="Enter Your Email Address" required><button type="submit">Subscribe</button></form>
    </div>
  </div>
</div>

@include('store.partials.home-modals-scripts', ['currency' => $currency, 'nlBtn' => $nlBtn])
@endsection
