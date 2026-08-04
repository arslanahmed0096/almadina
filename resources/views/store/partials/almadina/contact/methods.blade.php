<section class="alm-contact-methods" aria-labelledby="contact-methods-title">
  <div class="alm-container">
    <div class="alm-contact-section-heading is-centered">
      <span>Choose a channel</span>
      <h2 id="contact-methods-title">Contact our team</h2>
      <p>Sales questions, order updates, and after-sales support all start here.</p>
    </div>

    <div class="alm-contact-method-grid">
      <article class="alm-contact-method-card">
        <span class="alm-contact-method-icon"><x-store.icon name="phone" class="w-7 h-7" /></span>
        <h3>Call Us</h3>
        <p>Speak with a branch representative.</p>
        @if($contactPhone)
          <a href="tel:{{ preg_replace('/[^+0-9]/', '', $contactPhone) }}">{{ $contactPhone }}</a>
        @else
          <span class="alm-contact-unavailable">Phone details unavailable</span>
        @endif
      </article>

      <article class="alm-contact-method-card">
        <span class="alm-contact-method-icon"><x-store.icon name="whatsapp" class="w-7 h-7" /></span>
        <h3>WhatsApp</h3>
        <p>Send a product or order question.</p>
        @if($whatsappNumber)
          <a href="https://wa.me/{{ $whatsappNumber }}" target="_blank" rel="noopener noreferrer">Start a conversation</a>
        @else
          <span class="alm-contact-unavailable">WhatsApp unavailable</span>
        @endif
      </article>

      <article class="alm-contact-method-card">
        <span class="alm-contact-method-icon"><x-store.icon name="mail" class="w-7 h-7" /></span>
        <h3>Email Us</h3>
        <p>Share details and attachments by email.</p>
        @if($contactEmail)
          <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>
        @else
          <span class="alm-contact-unavailable">Email details unavailable</span>
        @endif
      </article>

      <article class="alm-contact-method-card">
        <span class="alm-contact-method-icon"><x-store.icon name="store" class="w-7 h-7" /></span>
        <h3>Visit a Showroom</h3>
        <p>See products and talk to our local team.</p>
        <a href="#branches">View {{ $storeBranches->count() }} store locations</a>
      </article>
    </div>
  </div>
</section>
