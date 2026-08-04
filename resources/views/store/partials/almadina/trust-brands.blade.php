<section class="alm-trust-section" aria-labelledby="alm-trust-title">
  <h2 id="alm-trust-title">Why Shop With Us</h2>
  <div class="alm-trust-card">
    <div><x-store.icon name="shield-check" class="w-8 h-8" /><span><strong>Genuine Products</strong><small>100% authentic &amp; reliable</small></span></div>
    <div><x-store.icon name="award" class="w-8 h-8" /><span><strong>Official Warranty</strong><small>Brand warranty on all products</small></span></div>
    <div><x-store.icon name="truck" class="w-8 h-8" /><span><strong>Fast Delivery</strong><small>Quick delivery across Pakistan</small></span></div>
    <div><x-store.icon name="headset" class="w-8 h-8" /><span><strong>Customer Support</strong><small>Here to help, always</small></span></div>
  </div>
</section>

<section class="alm-brands-section" aria-labelledby="alm-brands-title">
  <h2 id="alm-brands-title">Our Trusted Brands</h2>
  <div class="alm-brand-row">
    @forelse($trustedBrands as $brand)
      @php
        $brandImage = trim((string) ($brand->image ?? ''));
        $hasBrandImage = $brandImage !== '' && !str_contains($brandImage, 'no-image');
      @endphp
      <div class="alm-brand" aria-label="{{ $brand->name }}">
        @if($hasBrandImage)
          <img src="{{ asset('images/brands/' . $brandImage) }}" alt="{{ $brand->name }}" width="150" height="52" loading="lazy" decoding="async">
        @else
          <span>{{ $brand->name }}</span>
        @endif
      </div>
    @empty
      @foreach(['Haier', 'Dawlance', 'Nasgas', 'PEL', 'TCL', 'Samsung', 'LG', 'Orient', 'Gree'] as $brandName)
        <div class="alm-brand"><span>{{ $brandName }}</span></div>
      @endforeach
    @endforelse
  </div>
</section>
