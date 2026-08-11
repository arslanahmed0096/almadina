<section class="alm-about-hero" aria-labelledby="about-page-title">
  <div class="alm-container">
    <div class="alm-about-hero-card">
      <div class="alm-about-hero-copy">
        <p class="alm-about-eyebrow">Our Story</p>
        <h1 id="about-page-title">Trusted Appliances. Lasting Relationships.</h1>
        <p class="alm-about-lead">At Al Madina Electronics and Home Appliances, we help Pakistani families choose genuine products with confidence. With official brand warranty and dependable after-sales service, we make everyday living easier and better.</p>
        <a href="{{ route('store.shop') }}" class="alm-about-btn alm-about-btn-primary">
          <span>Explore Our Products</span>
          <x-store.icon name="arrow-right" class="w-5 h-5" />
        </a>
      </div>
      <div class="alm-about-hero-media">
        <img src="{{ $heroImage }}" alt="Al Madina appliances showroom with refrigerators, televisions and washing machines" width="1856" height="864" fetchpriority="high" decoding="async">
      </div>
    </div>
  </div>
</section>
