@extends('layouts.store')

@section('content')
@php
  $email = $s->contact_email ?? '';
  $phone = $s->contact_phone ?? '';
  $address = $s->contact_address ?? '';
  $currency = $s->currency_code ?? '$';
  $hidePrices = !\Illuminate\Support\Facades\Auth::guard('store')->check()
    && ($s->hide_prices_for_guests ?? false);
  $usesDemoAddress = !$address || stripos($address, 'Sample City') !== false;
  $mapUrl = $usesDemoAddress
    ? 'https://www.openstreetmap.org/export/embed.html?bbox=-75.59%2C39.13%2C-75.48%2C39.20&layer=mapnik&marker=39.16793%2C-75.53673'
    : 'https://www.google.com/maps?q=' . urlencode($address) . '&output=embed';
  $ref = fn (string $path) => asset('images/store/home-2/reference/images/' . ltrim($path, '/'));
  $recentProducts = [
    ['category' => 'Headphone', 'title' => 'Urbanears Pampas - Wireless Over-Ear Headphones', 'price' => '48.990', 'image' => $ref('product/product-134.jpg')],
    ['category' => 'Headphone', 'title' => 'Upgrader Headphones - Altec Lansing by ECCO Design', 'price' => '27.500', 'image' => $ref('product/product-2.jpg')],
    ['category' => 'Smartwatch', 'title' => 'Apple Watch Series 6 (GPS) - 40mm Aluminum Case', 'price' => '63.999', 'image' => $ref('product/product-3.jpg')],
    ['category' => 'Laptop & Computer', 'title' => 'Lenovo Yoga 910 - 2-in-1 Ultrabook with Touchscreen', 'price' => '39.990', 'image' => $ref('product/product-4.jpg')],
    ['category' => 'Wireless Earphones', 'title' => 'JBL LIVE200BT - Wireless Neckband Earphones', 'price' => '14.999', 'image' => $ref('product/product-5.jpg')],
  ];
@endphp

<div class="onsus-contact-page">
  <div class="contact-breadcrumb">
    <div class="container">
      <a href="{{ route('store.index') }}">Home</a>
      <x-store.icon name="chevron-right" class="w-3 h-3" />
      <span>Contact</span>
    </div>
  </div>

  <section class="contact-main">
    <div class="container">
      <div class="contact-map-shell">
        <iframe
          title="Store location"
          src="{{ $mapUrl }}"
          loading="lazy"
          referrerpolicy="no-referrer-when-downgrade"
          allowfullscreen></iframe>

        <div class="contact-quote-card">
          <div class="contact-card-heading">
            <h1>Get A Quote</h1>
            <p>Fill up the form and our Team will get back to you within 24 hours.</p>
          </div>

          <form id="contactForm" method="POST" action="{{ route('store.contact.send') }}" novalidate>
            @csrf

            <label>
              <span>Name</span>
              <input type="text" name="name" autocomplete="name" required>
            </label>

            <label>
              <span>Email</span>
              <input type="email" name="email" autocomplete="email" required>
            </label>

            <label>
              <span>Subject</span>
              <input type="text" name="subject" required>
            </label>

            <label>
              <span>Your message</span>
              <textarea name="message" rows="6" required></textarea>
            </label>

            <div class="contact-honeypot" aria-hidden="true">
              <label>Company<input type="text" name="company" tabindex="-1" autocomplete="off"></label>
            </div>

            <button id="contactSubmit" type="submit">Send message</button>
            <div id="contactAlert" class="contact-alert" role="alert" aria-live="polite" hidden></div>
          </form>
        </div>

        <div class="contact-info-panel">
          <h2>Contact Information</h2>
          <div class="contact-info-list">
            <div class="contact-info-item">
              <x-store.icon name="map-pin" class="w-5 h-5" />
              @if($address)
                <a href="https://www.google.com/maps?q={{ urlencode($address) }}" target="_blank" rel="noopener">{{ $address }}</a>
              @else
                <span>Store address is not provided</span>
              @endif
            </div>
            <div class="contact-info-item">
              <x-store.icon name="phone" class="w-5 h-5" />
              @if($phone)
                <a class="contact-info-emphasis" href="tel:{{ preg_replace('/\s+/', '', $phone) }}">{{ $phone }}</a>
              @else
                <span>Phone is not provided</span>
              @endif
            </div>
            <div class="contact-info-item">
              <x-store.icon name="send" class="w-5 h-5" />
              @if($email)
                <a href="mailto:{{ $email }}">{{ $email }}</a>
              @else
                <span>Email is not provided</span>
              @endif
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="contact-recent">
    <div class="container">
      <div class="contact-section-title">
        <h2>Recently Viewed</h2>
        <div>
          <button type="button" aria-label="Previous products"><x-store.icon name="chevron-left" class="w-4 h-4" /></button>
          <button type="button" aria-label="Next products"><x-store.icon name="chevron-right" class="w-4 h-4" /></button>
        </div>
      </div>
      <div class="contact-recent-grid">
        @foreach($recentProducts as $product)
          <a href="{{ route('store.shop', ['q' => $product['title']]) }}" class="contact-product-card">
            <span class="contact-product-image"><img src="{{ $product['image'] }}" alt="{{ $product['title'] }}"></span>
            <small>{{ $product['category'] }}</small>
            <strong>{{ $product['title'] }}</strong>
            @unless($hidePrices)
              <b>{{ $currency }}{{ $product['price'] }}</b>
            @endunless
          </a>
        @endforeach
      </div>
    </div>
  </section>

  <section class="onsus-newsletter contact-newsletter">
    <div class="container">
      <strong><x-store.icon name="mail" class="w-5 h-5" /> 10% Off Your First Order</strong>
      <span>Be the first to know about offers, new products and discounted products</span>
      <form id="contactNewsletterForm" action="{{ route('newsletter.subscribe') }}" method="POST">
        @csrf
        <input name="email" type="email" placeholder="Enter your email address" aria-label="Email address" required>
        <button type="submit">{{ __('messages.Subscribe') }}</button>
      </form>
      <div id="contactNewsletterMsg" aria-live="polite"></div>
    </div>
  </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var form = document.getElementById('contactForm');
  var button = document.getElementById('contactSubmit');
  var alertBox = document.getElementById('contactAlert');

  function showAlert(type, message) {
    alertBox.className = 'contact-alert is-' + type;
    alertBox.innerHTML = message;
    alertBox.hidden = false;
  }

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    alertBox.hidden = true;
    form.querySelectorAll('.is-invalid').forEach(function (field) {
      field.classList.remove('is-invalid');
    });

    var originalText = button.textContent;
    button.disabled = true;
    button.textContent = '{{ __("messages.Sending") }}';

    fetch(form.action, {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'X-CSRF-TOKEN': form.querySelector('input[name="_token"]').value
      },
      body: new FormData(form)
    })
    .then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (data) {
        return { ok: response.ok, status: response.status, data: data };
      });
    })
    .then(function (result) {
      if (result.ok) {
        form.reset();
        showAlert('success', result.data.message || '{{ __("messages.ContactSuccess") }}');
        return;
      }

      if (result.status === 422 && result.data.errors) {
        var messages = [];
        Object.keys(result.data.errors).forEach(function (fieldName) {
          var field = form.querySelector('[name="' + fieldName + '"]');
          if (field) field.classList.add('is-invalid');
          messages = messages.concat(result.data.errors[fieldName]);
        });
        showAlert('error', messages.join('<br>'));
        return;
      }

      showAlert('error', result.data.message || '{{ __("messages.SomethingWentWrong") }}');
    })
    .catch(function () {
      showAlert('error', '{{ __("messages.NetworkErrorTryAgain") }}');
    })
    .finally(function () {
      button.disabled = false;
      button.textContent = originalText;
    });
  });

  var newsletter = document.getElementById('contactNewsletterForm');
  var newsletterMessage = document.getElementById('contactNewsletterMsg');
  newsletter.addEventListener('submit', function (event) {
    event.preventDefault();
    var submit = newsletter.querySelector('button');
    submit.disabled = true;

    fetch(newsletter.action, {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'X-CSRF-TOKEN': newsletter.querySelector('input[name="_token"]').value
      },
      body: new FormData(newsletter)
    })
    .then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (data) {
        if (!response.ok) throw data;
        return data;
      });
    })
    .then(function (data) {
      newsletter.reset();
      newsletterMessage.textContent = data.message || 'You have successfully subscribed.';
    })
    .catch(function (error) {
      newsletterMessage.textContent = error.message || 'Unable to subscribe right now.';
    })
    .finally(function () {
      submit.disabled = false;
    });
  });
});
</script>
@endsection
