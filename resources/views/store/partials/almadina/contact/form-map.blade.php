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
      <h2 id="contact-form-title">Send Us a Message</h2>

      <form id="almContactForm" action="{{ route('store.contact.send') }}" method="POST">
        @csrf
        <div class="alm-contact-field-grid">
          <label class="alm-contact-field">
            <span>Full Name</span>
            <input type="text" name="name" value="{{ old('name') }}" placeholder="Enter your full name" autocomplete="name" maxlength="190" required>
            <small data-field-error="name"></small>
          </label>
          <label class="alm-contact-field">
            <span>Phone Number</span>
            <input type="tel" name="phone" value="{{ old('phone') }}" placeholder="Enter your phone number" autocomplete="tel" maxlength="50">
            <small data-field-error="phone"></small>
          </label>
          <label class="alm-contact-field">
            <span>Email Address</span>
            <input type="email" name="email" value="{{ old('email') }}" placeholder="Enter your email address" autocomplete="email" maxlength="190" required>
            <small data-field-error="email"></small>
          </label>
          <label class="alm-contact-field">
            <span>Subject</span>
            <select name="subject" required>
              <option value="">Select a subject</option>
              @foreach(['Product Advice', 'Order Support', 'Warranty & Repairs', 'Business or Bulk Purchase', 'General Inquiry'] as $subject)
                <option value="{{ $subject }}" @selected(old('subject') === $subject)>{{ $subject }}</option>
              @endforeach
            </select>
            <small data-field-error="subject"></small>
          </label>
          <label class="alm-contact-field">
            <span>Message</span>
            <textarea name="message" rows="5" placeholder="Type your message here..." required>{{ old('message') }}</textarea>
            <small data-field-error="message"></small>
          </label>
        </div>

        <label class="alm-contact-consent">
          <input type="checkbox" name="contact_consent" value="1" required>
          <span>I agree to be contacted about my enquiry</span>
        </label>

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
      <div class="alm-contact-map-frame">
        @if($primaryMapUrl)
          <iframe id="almBranchMap" title="Map showing {{ $primaryBranch->name }}" src="{{ $primaryMapUrl }}" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>
        @else
          <div class="alm-contact-map-empty"><x-store.icon name="map" class="w-10 h-10" /><span>Location map unavailable</span></div>
        @endif
      </div>
      <div class="alm-contact-map-content">
        <h2 id="contact-map-title">Find a Store Near You</h2>
        <p>We have multiple branches ready to serve you. Find the nearest store for products, support and expert advice.</p>
        <div class="alm-contact-map-details">
          <x-store.icon name="map-pin" class="w-5 h-5" />
          <span><strong id="almMapTitle">{{ $primaryBranch->name ?? 'Al Madina Electronics' }}</strong><small id="almMapAddress">{{ $primaryMapQuery ?: 'Choose a branch below for location details.' }}</small></span>
        </div>
        <a id="almMapDirections" class="alm-contact-btn is-outline-red" href="#branches">
          <x-store.icon name="map-pin" class="w-4 h-4" /> View All Locations
        </a>
      </div>
    </aside>
  </div>
</section>
