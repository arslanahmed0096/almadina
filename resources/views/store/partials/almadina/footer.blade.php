@php
  $footerCategoryLinks = collect($categories)->take(6);
  $storeBranches = collect($storeBranches ?? []);
  $configuredAboutCopy = trim((string) ($s->footer_text ?? ''));
  $usesDemoFooterCopy = $configuredAboutCopy === '' || stripos($configuredAboutCopy, 'demo storefront') !== false;
  $aboutCopy = $usesDemoFooterCopy
    ? 'Al Madina Electronics and Home Appliances serves customers across Pakistan with genuine products, product guidance and dependable after-sales support.'
    : $configuredAboutCopy;
@endphp

<footer class="alm-footer">
  <div class="alm-container alm-footer-grid">
    <section aria-labelledby="alm-footer-shop">
      <h2 id="alm-footer-shop">Shop</h2>
      <a href="{{ route('store.shop') }}">All Products</a>
      @foreach($footerCategoryLinks as $category)
        <a href="{{ route('store.shop', ['category' => $category->id]) }}">{{ $category->name }}</a>
      @endforeach
      <a href="{{ route('store.shop', ['deals' => 1]) }}">Deals</a>
    </section>

    <section aria-labelledby="alm-footer-service">
      <h2 id="alm-footer-service">Customer Service</h2>
      <a href="{{ $client ? $ordersUrl : $loginUrl }}">Track Order</a>
      <a href="{{ route('store.contact') }}#returns">Returns &amp; Refunds</a>
      <a href="{{ route('store.contact') }}#shipping">Shipping Information</a>
      <a href="{{ route('store.contact') }}#faqs">FAQs</a>
      <a href="{{ route('store.contact') }}#warranty">Warranty Information</a>
      <a href="{{ route('store.contact') }}#terms">Terms &amp; Conditions</a>
      <a href="{{ route('store.contact') }}#privacy">Privacy Policy</a>
    </section>

    <section class="alm-footer-about" aria-labelledby="alm-footer-about">
      <h2 id="alm-footer-about">About Al Madina</h2>
      <p>{{ $aboutCopy }}</p>
      <a href="{{ route('store.about') }}" class="alm-footer-strong-link">Learn More About Us <x-store.icon name="arrow-right" class="w-4 h-4" /></a>
    </section>

    <section id="footer-branches" class="alm-footer-contact" aria-labelledby="alm-footer-contact">
      <h2 id="alm-footer-contact">Contact / Branches</h2>
      @if(!empty($s->contact_phone))<a href="tel:{{ preg_replace('/[^+0-9]/', '', $s->contact_phone) }}"><x-store.icon name="phone" class="w-4 h-4" /> {{ $s->contact_phone }}</a>@endif
      @if(!empty($s->contact_address))<p><x-store.icon name="map-pin" class="w-4 h-4" /> {{ $s->contact_address }}</p>@endif
      @forelse($storeBranches as $branch)
        <p><x-store.icon name="store" class="w-4 h-4" /> {{ $branch->name }}@if($branch->city), {{ $branch->city }}@endif</p>
      @empty
        <p>Branch details are available from our contact team.</p>
      @endforelse
      <a href="{{ route('store.contact') }}" class="alm-footer-strong-link">View Contact &amp; Branches</a>
    </section>

    <section class="alm-footer-connect" aria-labelledby="alm-footer-connect">
      <h2 id="alm-footer-connect">Stay Connected</h2>
      @if(!empty($social))
        <div class="alm-social-links">
          @foreach($social as $item)
            @php
              $platform = strtolower(trim((string) ($item['platform'] ?? '')));
              $url = $item['url'] ?? null;
              $icon = $platform === 'x' ? 'twitter-x' : $platform;
            @endphp
            @if($platform && $url)
              <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" aria-label="Follow Al Madina on {{ ucfirst($platform) }}"><x-store.icon :name="$icon" class="w-5 h-5" /></a>
            @endif
          @endforeach
        </div>
      @endif

      <div class="alm-newsletter">
        <h3>Newsletter</h3>
        <p>Get the latest deals and updates</p>
        <form id="newsletterForm" action="{{ route('newsletter.subscribe') }}" method="POST">
          @csrf
          <label for="newsletterEmail" class="sr-only">Email address</label>
          <input id="newsletterEmail" name="email" type="email" placeholder="Enter your email" autocomplete="email" required>
          <button id="newsletterBtn" type="submit">Subscribe</button>
        </form>
        <p id="newsletterMsg" class="alm-newsletter-message" role="status" aria-live="polite"></p>
      </div>
    </section>
  </div>

  <div class="alm-footer-bottom">
    <div class="alm-container">
      <span>© {{ date('Y') }} Al Madina Electronics and Home Appliances. All rights reserved.</span>
      <span>Proudly Serving Pakistan <i aria-label="Pakistan locale">PK</i></span>
    </div>
  </div>
</footer>
