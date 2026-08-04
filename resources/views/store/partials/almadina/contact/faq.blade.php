<section class="alm-contact-faq" id="faqs" aria-labelledby="faq-title">
  <div class="alm-container alm-contact-faq-grid">
    <div class="alm-contact-faq-intro">
      <span>Helpful answers</span>
      <h2 id="faq-title">Frequently Asked Questions</h2>
      <p>Quick guidance for common shopping, delivery, and after-sales questions.</p>
      <a href="#contact-form" class="alm-contact-btn is-dark">Ask another question</a>
    </div>
    <div class="alm-faq-list">
      @foreach($faqs as $faq)
        <details @if($loop->first) open @endif>
          <summary>
            <span>{{ $faq['question'] }}</span>
            <i aria-hidden="true"></i>
          </summary>
          <div><p>{{ $faq['answer'] }}</p></div>
        </details>
      @endforeach
    </div>
  </div>
</section>
