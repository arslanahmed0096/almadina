<section class="alm-about-section alm-about-why" aria-labelledby="why-shop-with-us-title">
  <div class="alm-container">
    <div class="alm-about-why-shell">
      <div class="alm-about-section-heading">
        <p class="alm-about-eyebrow">Why Shop With Us</p>
        <h2 id="why-shop-with-us-title" class="sr-only">Why Shop With Us</h2>
      </div>

      <div class="alm-about-why-grid">
        @foreach($reasons as $item)
          <article class="alm-about-why-item">
            <x-store.icon :name="$item['icon']" class="w-9 h-9" />
            <div>
              <h3>{{ $item['title'] }}</h3>
              <p>{{ $item['text'] }}</p>
            </div>
          </article>
        @endforeach
      </div>
    </div>
  </div>
</section>
