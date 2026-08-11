<section class="alm-about-section alm-about-stores" aria-labelledby="visit-our-stores-title">
  <div class="alm-container">
    <div class="alm-about-stores-grid">
      <div class="alm-about-store-intro">
        <p class="alm-about-eyebrow">Visit Our Stores</p>
        <h2 id="visit-our-stores-title">Visit Our Stores</h2>
        <p>We are proud to serve you through our branches across Pakistan. Visit your nearest Al Madina store for the best appliances and expert guidance.</p>
        <a href="{{ route('store.contact') }}#branches" class="alm-about-btn alm-about-btn-outline">View All Locations</a>

        <figure class="alm-about-map-panel">
          <svg class="alm-about-map-svg" viewBox="0 0 420 300" xmlns="http://www.w3.org/2000/svg" aria-label="Map of Pakistan highlighting Al Madina branch locations" role="img">
            <path d="M214.271 17.999c22.116 8.549 43.098 25.991 66.88 27.732 15.85 1.16 30.466-4.952 44.082 4.561 15.887 11.097 26.888 29.117 31.697 47.829 6.205 24.139 5.257 50.245-1.994 74.097-6.336 20.84-16.719 40.777-32.435 56.04-15.738 15.284-36.874 26.602-58.848 33.065-20.748 6.103-43.249 7.24-63.85-.038-20.858-7.368-39.785-22.569-59.757-31.429-14.648-6.497-31.927-8.838-42.681-21.005-10.994-12.439-13.727-30.152-17.188-46.15-3.944-18.229-8.181-36.638-6.559-55.226 1.827-20.93 11.877-40.833 25.525-56.756 12.54-14.627 29.198-26.047 47.936-32.28 12.97-4.313 27.381-8.341 40.86-6.233 9.866 1.543 18.989 7.104 28.332 10.593Z" fill="#e9edf1"/>
            <path d="M182.27 102.98c0 10.981-14.527 21.962-14.527 21.962s-14.527-10.981-14.527-21.962c0-8.02 6.506-14.526 14.527-14.526 8.02 0 14.527 6.506 14.527 14.526Z" fill="#ED1C24"/>
            <circle cx="167.743" cy="102.98" r="5.081" fill="#fff"/>
            <path d="M235.98 74.77c0 10.981-14.527 21.961-14.527 21.961s-14.527-10.98-14.527-21.961c0-8.02 6.506-14.527 14.527-14.527 8.02 0 14.527 6.506 14.527 14.527Z" fill="#ED1C24"/>
            <circle cx="221.453" cy="74.77" r="5.081" fill="#fff"/>
            <path d="M271.846 147.502c0 10.981-14.527 21.962-14.527 21.962s-14.526-10.981-14.526-21.962c0-8.02 6.505-14.526 14.526-14.526 8.021 0 14.527 6.506 14.527 14.526Z" fill="#ED1C24"/>
            <circle cx="257.319" cy="147.502" r="5.081" fill="#fff"/>
            <path d="M206.421 192.356c0 10.981-14.527 21.962-14.527 21.962s-14.527-10.981-14.527-21.962c0-8.021 6.506-14.527 14.527-14.527 8.02 0 14.527 6.506 14.527 14.527Z" fill="#ED1C24"/>
            <circle cx="191.894" cy="192.356" r="5.081" fill="#fff"/>
          </svg>
        </figure>
      </div>

      <div class="alm-about-branch-grid">
        @forelse($storeBranches as $branch)
          @php
            $displayName = preg_replace('/^Al Madina Electronics\s*\((?:BRN-\d+)\)\s*/i', '', (string) $branch->name);
            $displayName = trim($displayName) !== '' ? trim($displayName) : $branch->name;
            $branchAddress = collect([$branch->city, $branch->country, $branch->zip])->filter()->implode(', ');
            $directionsUrl = $branchAddress !== '' ? 'https://www.google.com/maps/search/?api=1&query='.urlencode($branchAddress) : null;
          @endphp
          <article class="alm-about-branch-card">
            <span class="alm-about-branch-icon" aria-hidden="true"><x-store.icon name="map-pin" class="w-6 h-6" /></span>
            <div>
              <h3>{{ $displayName }}</h3>
              <p>{{ $branchAddress ?: 'Address currently being updated.' }}</p>
              @if($directionsUrl)
                <a href="{{ $directionsUrl }}" target="_blank" rel="noopener noreferrer">Get Directions</a>
              @endif
            </div>
          </article>
        @empty
          <article class="alm-about-branch-card is-empty">
            <span class="alm-about-branch-icon" aria-hidden="true"><x-store.icon name="store" class="w-6 h-6" /></span>
            <div>
              <h3>Branch information pending</h3>
              <p>Please contact our support team for the nearest Al Madina location.</p>
            </div>
          </article>
        @endforelse
      </div>
    </div>
  </div>
</section>
