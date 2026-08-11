<section class="alm-about-section" aria-labelledby="what-drives-us-title">
  <div class="alm-container">
    <div class="alm-about-section-heading">
      <p class="alm-about-eyebrow">What Drives Us</p>
      <h2 id="what-drives-us-title" class="sr-only">What Drives Us</h2>
    </div>

    <div class="alm-about-card-grid alm-about-card-grid-three">
      @foreach($drivers as $item)
        <article class="alm-about-info-card">
          <span class="alm-about-icon-badge is-{{ $item['tone'] }}" aria-hidden="true">
            <x-store.icon :name="$item['icon']" class="w-7 h-7" />
          </span>
          <h3>{{ $item['title'] }}</h3>
          <p>{{ $item['text'] }}</p>
        </article>
      @endforeach
    </div>
  </div>
</section>
