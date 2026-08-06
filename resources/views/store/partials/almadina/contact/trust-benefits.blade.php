<section class="alm-contact-trust" aria-labelledby="contact-trust-title">
  <div class="alm-container">
    <h2 id="contact-trust-title">Why Shop With Us</h2>
    <div class="alm-contact-trust-grid">
      <div><x-store.icon name="shield-check" class="w-9 h-9" /><span><strong>Genuine Products</strong><small>100% genuine and trusted products</small></span></div>
      <div><x-store.icon name="award" class="w-9 h-9" /><span><strong>Official Warranty</strong><small>Official brand warranty for peace of mind</small></span></div>
      <div><x-store.icon name="truck" class="w-9 h-9" /><span><strong>Fast Delivery</strong><small>Quick and reliable delivery across Pakistan</small></span></div>
      <div><x-store.icon name="headset" class="w-9 h-9" /><span><strong>Customer Support</strong><small>Friendly support whenever you need us</small></span></div>
    </div>

    <div class="alm-contact-brands" aria-labelledby="contact-brands-title">
      <h2 id="contact-brands-title">Our Trusted Brands</h2>
      <div class="alm-contact-brand-row">
        @forelse($trustedBrands as $brand)
          @php
            $brandImage = trim((string) ($brand->image ?? ''));
            $hasBrandImage = $brandImage !== '' && !str_contains($brandImage, 'no-image');
          @endphp
          <div class="alm-contact-brand" aria-label="{{ $brand->name }}">
            @if($hasBrandImage)
              <img src="{{ asset('images/brands/'.$brandImage) }}" alt="{{ $brand->name }}" width="140" height="48" loading="lazy">
            @else
              <span>{{ $brand->name }}</span>
            @endif
          </div>
        @empty
          @foreach(['Haier', 'Dawlance', 'Nasgas', 'PEL', 'TCL', 'Samsung', 'LG', 'Orient', 'Gree'] as $brandName)
            <div class="alm-contact-brand"><span>{{ $brandName }}</span></div>
          @endforeach
        @endforelse
      </div>
    </div>
  </div>
</section>
