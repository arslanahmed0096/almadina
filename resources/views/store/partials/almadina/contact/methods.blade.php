<section class="alm-contact-methods" aria-label="Contact methods">
  <div class="alm-container">
    <div class="alm-contact-method-grid">
      <article class="alm-contact-method-card">
        <span class="alm-contact-method-icon"><x-store.icon name="phone" class="w-7 h-7" /></span>
        <div>
          <h3>Call Us</h3>
          <p>Speak with our support team</p>
          @if($contactPhone)
            <a href="tel:{{ preg_replace('/[^+0-9]/', '', $contactPhone) }}">{{ $contactPhone }}</a>
          @else
            <span class="alm-contact-unavailable">Contact number</span>
          @endif
        </div>
      </article>

      <article class="alm-contact-method-card is-whatsapp">
        <span class="alm-contact-method-icon"><x-store.icon name="whatsapp" class="w-7 h-7" /></span>
        <div>
          <h3>WhatsApp</h3>
          <p>Chat with us instantly</p>
          @if($whatsappNumber)
            <a href="https://wa.me/{{ $whatsappNumber }}" target="_blank" rel="noopener noreferrer">{{ $contactPhone ?: 'Start a conversation' }}</a>
          @else
            <span class="alm-contact-unavailable">Contact number</span>
          @endif
        </div>
      </article>

      <article class="alm-contact-method-card">
        <span class="alm-contact-method-icon"><x-store.icon name="mail" class="w-7 h-7" /></span>
        <div>
          <h3>Email Us</h3>
          <p>We&rsquo;ll reply as soon as possible</p>
          @if($contactEmail)
            <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>
          @else
            <span class="alm-contact-unavailable">Email address</span>
          @endif
        </div>
      </article>

      <article class="alm-contact-method-card is-hours">
        <span class="alm-contact-method-icon"><x-store.icon name="clock" class="w-7 h-7" /></span>
        <div>
          <h3>Working Hours</h3>
          <p>Mon&ndash;Sat,<br>9:00 AM&ndash;9:00 PM</p>
        </div>
      </article>
    </div>
  </div>
</section>
