@extends('layouts.store')

@section('content')
@php
  $storeName = $s->store_name ?? __('messages.Store');
  $aboutAsset = fn (string $path) => asset('images/store/about/' . ltrim($path, '/'));
  $serviceItems = [
    ['icon' => 'truck', 'title' => 'Free delivery', 'text' => 'Free Shipping for orders over $20'],
    ['icon' => 'clock', 'title' => 'Support 24/7', 'text' => '24 hours a day, 7 days a week'],
    ['icon' => 'credit-card', 'title' => 'Payment', 'text' => 'Pay with Multiple Credit Cards'],
    ['icon' => 'shield-check', 'title' => 'Reliable', 'text' => 'Trusted by 2000+ major brands'],
    ['icon' => 'check-circle', 'title' => 'Guarantee', 'text' => 'Within 30 days for an exchange'],
  ];
  $brandLogos = [
    'brands/great-deal.svg',
    'brands/walmart.svg',
    'brands/rodem.svg',
    'brands/fabric.svg',
    'brands/sudo.svg',
    'brands/ctaecom.svg',
    'brands/lead-ecommerce.svg',
    'brands/global-brand.svg',
  ];
  $newsItems = [
    [
      'image' => 'news/blog-4.jpg',
      'title' => 'My Solution to Lost AirPods: The AirTags U1 Chip',
      'excerpt' => 'Discover useful ideas, product stories, and practical ways to get more from the technology you use every day.',
    ],
    [
      'image' => 'news/blog-5.jpg',
      'title' => "'Stranger Things' vs. 'Obi-Wan Kenobi': Which Will You Watch Friday?",
      'excerpt' => 'The latest entertainment, technology, and home trends selected by our editorial team.',
    ],
    [
      'image' => 'news/blog-6.jpg',
      'title' => "'Dragon of Death' Unearthed in Argentina Is One of the Largest",
      'excerpt' => 'New discoveries and stories shaping the worlds of science, design, and consumer technology.',
    ],
    [
      'image' => 'news/blog-1.jpg',
      'title' => 'Google Faces EU Break-Up Order Over Adtech Practices',
      'excerpt' => 'A closer look at the news and developments influencing the digital marketplace.',
    ],
  ];
@endphp

<div class="onsus-about-page">
  <div class="contact-breadcrumb">
    <div class="container">
      <a href="{{ route('store.index') }}">Home</a>
      <x-store.icon name="chevron-right" class="w-3 h-3" />
      <span>About</span>
    </div>
  </div>

  <section class="about-welcome">
    <div class="container">
      <div class="about-intro">
        <div>
          <h1>Welcome to {{ $storeName }}</h1>
          <p>Blend contemporary designs with timeless elegance</p>
        </div>
        <p>At {{ $storeName }}, we offer meticulously curated collections that seamlessly combine modern innovation with everyday reliability. We focus on quality, value, and adaptable products that make life easier.</p>
      </div>
      <div class="about-showcase" role="img" aria-label="Global distribution and logistics center"
           style="background-image:url('{{ $aboutAsset('parallax-3.jpg') }}')"></div>
    </div>
  </section>

  <section class="about-services" aria-label="Store benefits">
    <div class="container">
      @foreach($serviceItems as $item)
        <article>
          <span class="about-service-icon"><x-store.icon :name="$item['icon']" class="w-7 h-7" /></span>
          <span><strong>{{ $item['title'] }}</strong><small>{{ $item['text'] }}</small></span>
        </article>
      @endforeach
    </div>
  </section>

  <section class="about-reviews">
    <div class="container">
      <div class="about-section-heading">
        <h2>Customer Review</h2>
        <div>
          <button type="button" aria-label="Previous review"><x-store.icon name="chevron-left" class="w-4 h-4" /></button>
          <button type="button" aria-label="Next review"><x-store.icon name="chevron-right" class="w-4 h-4" /></button>
        </div>
      </div>
      <div class="about-review-grid">
        @for($index = 0; $index < 3; $index++)
          <article class="about-review-card">
            <img src="{{ $aboutAsset('review-1.jpg') }}" alt="Cameron Williamson">
            <div>
              <h3>Cameron Williamson</h3>
              <p class="about-review-meta"><span>Color: Black</span><b>Verified Purchase</b></p>
              <div class="about-stars" aria-label="5 out of 5 stars">★★★★★</div>
              <p class="about-review-copy">A smooth shopping experience from start to finish. The product quality was excellent, delivery was quick, and the team made every detail easy to understand.</p>
              <time datetime="2020-12-14T17:20">December 14, 2020 at 17:20</time>
            </div>
          </article>
        @endfor
      </div>
      <div class="about-review-dots" aria-hidden="true"><i></i><i></i></div>
    </div>
  </section>

  <section class="about-brands" aria-label="Partner brands">
    <div class="about-brand-track">
      @foreach($brandLogos as $logo)
        <span><img src="{{ $aboutAsset($logo) }}" alt="Partner brand"></span>
      @endforeach
    </div>
  </section>

  <section class="about-news">
    <div class="container">
      <div class="about-section-heading">
        <h2>News</h2>
        <div>
          <button type="button" aria-label="Previous article"><x-store.icon name="chevron-left" class="w-4 h-4" /></button>
          <button type="button" aria-label="Next article"><x-store.icon name="chevron-right" class="w-4 h-4" /></button>
        </div>
      </div>
      <div class="about-news-grid">
        @foreach($newsItems as $item)
          <article class="about-news-card">
            <a href="{{ route('store.shop') }}" class="about-news-image"><img src="{{ $aboutAsset($item['image']) }}" alt=""></a>
            <div class="about-news-meta"><span><x-store.icon name="tag" class="w-3.5 h-3.5" /> Houseware</span><time datetime="2022-04-28">28 Apr 2022</time></div>
            <h3><a href="{{ route('store.shop') }}">{{ $item['title'] }}</a></h3>
            <p>{{ $item['excerpt'] }}</p>
          </article>
        @endforeach
      </div>
    </div>
  </section>

  <section class="onsus-newsletter about-newsletter">
    <div class="container">
      <strong><x-store.icon name="mail" class="w-5 h-5" /> 10% Off Your First Order</strong>
      <span>Be the first to know about offers, new products and discounted products</span>
      <form id="aboutNewsletterForm" action="{{ route('newsletter.subscribe') }}" method="POST">
        @csrf
        <input name="email" type="email" placeholder="Enter your email address" aria-label="Email address" required>
        <button type="submit">{{ __('messages.Subscribe') }}</button>
      </form>
      <div id="aboutNewsletterMsg" aria-live="polite"></div>
    </div>
  </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var form = document.getElementById('aboutNewsletterForm');
  var message = document.getElementById('aboutNewsletterMsg');
  if (!form) return;

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    var button = form.querySelector('button');
    button.disabled = true;

    fetch(form.action, {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'X-CSRF-TOKEN': form.querySelector('input[name="_token"]').value
      },
      body: new FormData(form)
    })
    .then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (data) {
        if (!response.ok) throw data;
        return data;
      });
    })
    .then(function (data) {
      form.reset();
      message.textContent = data.message || 'You have successfully subscribed.';
    })
    .catch(function (error) {
      message.textContent = error.message || 'Unable to subscribe right now.';
    })
    .finally(function () {
      button.disabled = false;
    });
  });
});
</script>
@endsection
