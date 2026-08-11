<section class="alm-about-stats" aria-label="Store statistics">
  <div class="alm-container">
    <div class="alm-about-stats-card">
      @foreach($stats as $item)
        <article class="alm-about-stat">
          <span class="alm-about-stat-icon" aria-hidden="true">
            <x-store.icon :name="$item['icon']" class="w-9 h-9" />
          </span>
          <div>
            <strong>{{ $item['value'] }}</strong>
            <span>{{ $item['label'] }}</span>
          </div>
        </article>
      @endforeach
    </div>
  </div>
</section>
