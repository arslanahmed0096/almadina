@extends('layouts.store')

@section('content')
<div class="alm-contact-page">
  @include('store.partials.almadina.contact.breadcrumb')
  @include('store.partials.almadina.contact.hero')
  @include('store.partials.almadina.contact.methods')
  @include('store.partials.almadina.contact.form-map')
  @include('store.partials.almadina.contact.branches')
  @include('store.partials.almadina.contact.faq')
  @include('store.partials.almadina.contact.trust-benefits')
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var form = document.getElementById('almContactForm');
  var button = document.getElementById('almContactSubmit');
  var alertBox = document.getElementById('almContactAlert');

  function setAlert(type, message) {
    alertBox.className = 'alm-contact-alert is-' + type;
    alertBox.textContent = message;
    alertBox.hidden = false;
  }

  function clearErrors() {
    alertBox.hidden = true;
    form.querySelectorAll('.is-invalid').forEach(function (field) {
      field.classList.remove('is-invalid');
      field.removeAttribute('aria-invalid');
    });
    form.querySelectorAll('[data-field-error]').forEach(function (error) {
      error.textContent = '';
    });
  }

  if (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      clearErrors();

      var originalText = button.textContent;
      button.disabled = true;
      button.classList.add('is-loading');
      button.textContent = 'Sending…';

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
          setAlert('success', result.data.message || 'Your message has been sent successfully.');
          return;
        }

        if (result.status === 422 && result.data.errors) {
          var messages = [];
          var firstInvalid = null;
          Object.keys(result.data.errors).forEach(function (fieldName) {
            var field = form.querySelector('[name="' + fieldName + '"]');
            var error = form.querySelector('[data-field-error="' + fieldName + '"]');
            var message = result.data.errors[fieldName][0];
            if (field) {
              field.classList.add('is-invalid');
              field.setAttribute('aria-invalid', 'true');
              if (!firstInvalid) firstInvalid = field;
            }
            if (error) error.textContent = message;
            messages.push(message);
          });
          setAlert('error', messages.join(' '));
          if (firstInvalid) firstInvalid.focus();
          return;
        }

        setAlert('error', result.data.message || 'Something went wrong. Please try again.');
      })
      .catch(function () {
        setAlert('error', 'We could not send your message. Please check your connection and try again.');
      })
      .finally(function () {
        button.disabled = false;
        button.classList.remove('is-loading');
        button.textContent = originalText;
      });
    });
  }

  var mapFrame = document.getElementById('almBranchMap');
  var mapTitle = document.getElementById('almMapTitle');
  var mapAddress = document.getElementById('almMapAddress');
  var mapDirections = document.getElementById('almMapDirections');

  document.querySelectorAll('[data-map-url]').forEach(function (trigger) {
    trigger.addEventListener('click', function () {
      if (mapFrame) mapFrame.src = trigger.dataset.mapUrl;
      if (mapTitle) mapTitle.textContent = trigger.dataset.branchName;
      if (mapAddress) mapAddress.textContent = trigger.dataset.branchAddress;
      if (mapDirections) mapDirections.href = trigger.dataset.directionsUrl;
      document.querySelectorAll('.alm-branch-card.is-selected').forEach(function (card) {
        card.classList.remove('is-selected');
      });
      var card = trigger.closest('.alm-branch-card');
      if (card) card.classList.add('is-selected');
      document.getElementById('contact-map').scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
  });

  document.querySelectorAll('.alm-faq-list details').forEach(function (item) {
    item.addEventListener('toggle', function () {
      if (!item.open) return;
      document.querySelectorAll('.alm-faq-list details[open]').forEach(function (other) {
        if (other !== item) other.removeAttribute('open');
      });
    });
  });
});
</script>
@endsection
