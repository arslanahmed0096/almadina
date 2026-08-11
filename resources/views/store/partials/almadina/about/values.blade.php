<section class="alm-about-section" aria-labelledby="our-values-title">
  <div class="alm-container">
    <div class="alm-about-section-heading">
      <p class="alm-about-eyebrow">Our Values</p>
      <h2 id="our-values-title" class="sr-only">Our Values</h2>
    </div>

    <div class="alm-about-card-grid alm-about-card-grid-four">
      @foreach($values as $item)
        <article class="alm-about-value-card">
          <span class="alm-about-value-icon" aria-hidden="true">
            <x-store.icon :name="$item['icon']" class="w-7 h-7" />
          </span>
          <h3>{{ $item['title'] }}</h3>
          <p>{{ $item['text'] }}</p>
        </article>
      @endforeach
    </div>
  </div>
</section>
