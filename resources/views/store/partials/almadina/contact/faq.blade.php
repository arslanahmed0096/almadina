<section class="alm-contact-faq" id="faqs" aria-labelledby="faq-title">
  <div class="alm-container alm-contact-faq-grid">
    <div class="alm-contact-faq-intro">
      <h2 id="faq-title">Frequently Asked Questions</h2>
      <p>Quick answers to the most common questions.</p>
      <a href="#faq-list" class="alm-contact-btn is-outline-red">View All FAQs</a>
    </div>
    <div class="alm-faq-list" id="faq-list">
      @foreach($faqs->take(3) as $faq)
        <details>
          <summary><span>{{ $faq['question'] }}</span><i aria-hidden="true"></i></summary>
          <div><p>{{ $faq['answer'] }}</p></div>
        </details>
      @endforeach
    </div>
  </div>
</section>
