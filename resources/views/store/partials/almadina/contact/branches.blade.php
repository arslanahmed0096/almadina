<section class="alm-contact-branches" id="branches" aria-labelledby="branches-title">
  <div class="alm-container">
    <div class="alm-contact-section-heading">
      <h2 id="branches-title">Our Store Locations</h2>
      <p>Visit your nearest Al Madina branch for products, expert advice and after-sales support.</p>
    </div>

    <div class="alm-branch-grid">
      @forelse($storeBranches->take(5) as $branch)
        @php
          $branchAddress = collect([$branch->city, $branch->country, $branch->zip])->filter()->implode(', ');
          $branchDirectionsUrl = 'https://www.google.com/maps/search/?api=1&query='.urlencode($branchAddress);
          $branchPhone = preg_replace('/[^+0-9]/', '', (string) $branch->mobile);
          $branchNumber = str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT);
          $branchDisplayName = $loop->first ? 'Main Branch' : 'Branch '.$branchNumber;
        @endphp
        <article class="alm-branch-card">
          <div class="alm-branch-media">
            <img src="{{ asset('images/storefront/almadina-branch-'.$branchNumber.'.webp') }}" alt="{{ $branchDisplayName }} storefront" width="720" height="480" loading="lazy" decoding="async">
            @if($loop->first)<span>Main Branch</span>@endif
          </div>
          <div class="alm-branch-card-body">
            <h3 title="{{ $branch->name }}">{{ $branchDisplayName }}</h3>
            <p><x-store.icon name="map-pin" class="w-4 h-4" /> {{ $branchAddress ?: 'Complete branch address' }}</p>
            <p><x-store.icon name="phone" class="w-4 h-4" /> {{ $branch->mobile ?: 'Contact number' }}</p>
            <p><x-store.icon name="clock" class="w-4 h-4" /> Mon&ndash;Sat, 9:00 AM&ndash;9:00 PM</p>
            <a href="{{ $branchDirectionsUrl }}" class="alm-contact-btn is-outline-red" target="_blank" rel="noopener noreferrer">
              <x-store.icon name="map-pin" class="w-4 h-4" /> Get Directions
            </a>
            @if($branch->mobile)
              <a href="tel:{{ $branchPhone }}" class="alm-branch-call"><x-store.icon name="phone" class="w-4 h-4" /> Call Branch</a>
            @endif
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
