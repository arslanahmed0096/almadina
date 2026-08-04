@php
  $primaryMapQuery = collect([
    $primaryBranch->city ?? null,
    $primaryBranch->country ?? null,
    $primaryBranch->zip ?? null,
  ])->filter()->implode(', ');
  $primaryMapUrl = $primaryMapQuery
    ? 'https://www.google.com/maps?q='.urlencode($primaryMapQuery).'&output=embed'
    : null;
  $primaryDirectionsUrl = $primaryMapQuery
    ? 'https://www.google.com/maps/search/?api=1&query='.urlencode($primaryMapQuery)
    : '#branches';
@endphp

<section class="alm-contact-workspace" id="contact-form" aria-labelledby="contact-form-title">
  <div class="alm-container alm-contact-workspace-grid">
    <div class="alm-contact-form-panel">
      <div class="alm-contact-section-heading">
        <span>Send an enquiry</span>
        <h2 id="contact-form-title">Tell us how we can help</h2>
        <p>Complete the form and our team will respond using the details you provide.</p>
      </div>

      <form id="almContactForm" action="{{ route('store.contact.send') }}" method="POST" novalidate>
        @csrf
        <div class="alm-contact-field-grid">
          <label class="alm-contact-field">
            <span>Full name <b>*</b></span>
            <input type="text" name="name" value="{{ old('name') }}" autocomplete="name" maxlength="190" required>
            <small data-field-error="name"></small>
          </label>
          <label class="alm-contact-field">
            <span>Email address <b>*</b></span>
            <input type="email" name="email" value="{{ old('email') }}" autocomplete="email" maxlength="190" required>
            <small data-field-error="email"></small>
          </label>
          <label class="alm-contact-field">
            <span>Phone number</span>
            <input type="tel" name="phone" value="{{ old('phone') }}" autocomplete="tel" maxlength="50">
            <small data-field-error="phone"></small>
          </label>
          <label class="alm-contact-field">
            <span>Subject <b>*</b></span>
            <select name="subject" required>
              <option value="">Select a topic</option>
              @foreach(['Product Advice', 'Order Support', 'Warranty & Repairs', 'Business or Bulk Purchase', 'General Inquiry'] as $subject)
                <option value="{{ $subject }}" @selected(old('subject') === $subject)>{{ $subject }}</option>
              @endforeach
            </select>
            <small data-field-error="subject"></small>
          </label>
          <label class="alm-contact-field is-full">
            <span>Preferred branch <em>Optional</em></span>
            <select name="branch_id">
              <option value="">Any available branch</option>
              @foreach($storeBranches as $branch)
                <option value="{{ $branch->id }}" @selected((string) old('branch_id') === (string) $branch->id)>{{ $branch->name }} — {{ $branch->city }}</option>
              @endforeach
            </select>
            <small data-field-error="branch_id"></small>
          </label>
          <label class="alm-contact-field is-full">
            <span>Message <b>*</b></span>
            <textarea name="message" rows="6" required>{{ old('message') }}</textarea>
            <small data-field-error="message"></small>
          </label>
        </div>

        <div class="alm-contact-honeypot" aria-hidden="true">
          <label>Company<input type="text" name="company" tabindex="-1" autocomplete="off"></label>
        </div>

        <button id="almContactSubmit" class="alm-contact-submit" type="submit">
          <x-store.icon name="send" class="w-5 h-5" /> Send Message
        </button>
        <div id="almContactAlert" class="alm-contact-alert" role="status" aria-live="polite" hidden></div>
      </form>
    </div>

    <aside class="alm-contact-map-panel" id="contact-map" aria-labelledby="contact-map-title">
      <div class="alm-contact-map-heading">
        <span>Nearest showroom</span>
        <h2 id="contact-map-title">Find a Store</h2>
        <p>Select “View on map” on any branch card below to update this location.</p>
      </div>
      <div class="alm-contact-map-frame">
        @if($primaryMapUrl)
          <iframe id="almBranchMap" title="Map showing {{ $primaryBranch->name }}" src="{{ $primaryMapUrl }}" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>
        @else
          <div class="alm-contact-map-empty"><x-store.icon name="map" class="w-10 h-10" /><span>Location map unavailable</span></div>
        @endif
      </div>
      <div class="alm-contact-map-details">
        <span class="alm-contact-map-pin"><x-store.icon name="map-pin" class="w-6 h-6" /></span>
        <div>
          <strong id="almMapTitle">{{ $primaryBranch->name ?? 'Al Madina Electronics' }}</strong>
          <span id="almMapAddress">{{ $primaryMapQuery ?: 'Select a branch for location details.' }}</span>
        </div>
      </div>
      <a id="almMapDirections" class="alm-contact-btn is-dark" href="{{ $primaryDirectionsUrl }}" target="_blank" rel="noopener noreferrer">
        Get Directions <x-store.icon name="external" class="w-4 h-4" />
      </a>
    </aside>
  </div>
</section>
