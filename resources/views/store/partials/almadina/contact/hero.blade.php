<section class="alm-contact-hero" aria-labelledby="contact-page-title">
  <div class="alm-container alm-contact-hero-grid">
    <div class="alm-contact-hero-copy">
      <span class="alm-contact-eyebrow">Sales · Support · Store Visits</span>
      <h1 id="contact-page-title">We’re here to help.</h1>
      <p>Need product advice, order support, warranty help, or directions to a showroom? Reach the Al Madina team through the channel that works best for you.</p>
      <div class="alm-contact-hero-actions">
        @if($contactPhone)
          <a class="alm-contact-btn is-light" href="tel:{{ preg_replace('/[^+0-9]/', '', $contactPhone) }}">
            <x-store.icon name="phone" class="w-5 h-5" /> Call Now
          </a>
        @else
          <a class="alm-contact-btn is-light" href="#contact-form"><x-store.icon name="message" class="w-5 h-5" /> Send a Message</a>
        @endif
        <a class="alm-contact-btn is-outline-light" href="#branches">
          <x-store.icon name="map-pin" class="w-5 h-5" /> Find a Store
        </a>
      </div>
    </div>

    <div class="alm-contact-hero-visual" aria-hidden="true">
      <div class="alm-contact-visual-core"><x-store.icon name="headset" class="w-12 h-12" /></div>
      <span class="alm-contact-visual-item is-chat"><x-store.icon name="message" class="w-6 h-6" /> Product advice</span>
      <span class="alm-contact-visual-item is-store"><x-store.icon name="store" class="w-6 h-6" /> {{ $storeBranches->count() }} branches</span>
      <span class="alm-contact-visual-item is-warranty"><x-store.icon name="shield-check" class="w-6 h-6" /> Warranty help</span>
    </div>
  </div>
</section>
