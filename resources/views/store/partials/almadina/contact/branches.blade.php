<section class="alm-contact-branches" id="branches" aria-labelledby="branches-title">
  <div class="alm-container">
    <div class="alm-contact-section-heading is-centered">
      <span>Store network</span>
      <h2 id="branches-title">Our Branches</h2>
      <p>Visit the location that is most convenient for you.</p>
    </div>

    <div class="alm-branch-grid">
      @forelse($storeBranches as $branch)
        @php
          $branchAddress = collect([$branch->city, $branch->country, $branch->zip])->filter()->implode(', ');
          $branchMapUrl = 'https://www.google.com/maps?q='.urlencode($branchAddress).'&output=embed';
          $branchDirectionsUrl = 'https://www.google.com/maps/search/?api=1&query='.urlencode($branchAddress);
          $branchPhone = preg_replace('/[^+0-9]/', '', (string) $branch->mobile);
        @endphp
        <article class="alm-branch-card {{ $loop->first ? 'is-selected' : '' }}">
          <div class="alm-branch-card-bar">
            <span>{{ $loop->first ? 'Featured location' : 'Al Madina showroom' }}</span>
            <b>{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</b>
          </div>
          <div class="alm-branch-card-body">
            <span class="alm-branch-icon"><x-store.icon name="store" class="w-7 h-7" /></span>
            <h3>{{ $branch->name }}</h3>
            <p><x-store.icon name="map-pin" class="w-4 h-4" /> {{ $branchAddress }}</p>
            @if($branch->mobile)
              <a href="tel:{{ $branchPhone }}"><x-store.icon name="phone" class="w-4 h-4" /> {{ $branch->mobile }}</a>
            @endif
            @if($branch->email)
              <a href="mailto:{{ $branch->email }}"><x-store.icon name="mail" class="w-4 h-4" /> {{ $branch->email }}</a>
            @endif
          </div>
          <div class="alm-branch-actions">
            @if($branch->mobile)
              <a href="tel:{{ $branchPhone }}" class="alm-contact-btn is-red">Call Branch</a>
            @endif
            <a href="{{ $branchDirectionsUrl }}" class="alm-contact-btn is-outline-dark" target="_blank" rel="noopener noreferrer">Directions</a>
            <button type="button"
                    class="alm-branch-map-trigger"
                    data-map-url="{{ $branchMapUrl }}"
                    data-directions-url="{{ $branchDirectionsUrl }}"
                    data-branch-name="{{ $branch->name }}"
                    data-branch-address="{{ $branchAddress }}">
              View on map
            </button>
          </div>
        </article>
      @empty
        <div class="alm-contact-empty-state">
          <x-store.icon name="store" class="w-10 h-10" />
          <h3>Branch details are being updated</h3>
          <p>Please contact our support team for the nearest available showroom.</p>
        </div>
      @endforelse
    </div>
  </div>
</section>
