<section class="alm-about-section alm-about-journey" aria-labelledby="our-journey-title">
  <div class="alm-container">
    <div class="alm-about-section-heading">
      <p class="alm-about-eyebrow">Our Journey</p>
      <h2 id="our-journey-title" class="sr-only">Our Journey</h2>
    </div>

    <ol class="alm-about-timeline">
      @foreach($journey as $item)
        <li class="alm-about-timeline-item">
          <strong>{{ $item['year'] }}</strong>
          <h3>{{ $item['title'] }}</h3>
          <p>{{ $item['text'] }}</p>
        </li>
      @endforeach
    </ol>
  </div>
</section>
