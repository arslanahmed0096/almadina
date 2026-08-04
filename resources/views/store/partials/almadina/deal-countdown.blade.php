@if(!empty($expiresAt))
  <div class="alm-countdown" data-deal-countdown data-expires-at="{{ $expiresAt }}" role="timer" aria-live="polite">
    <span class="alm-countdown-label"><x-store.icon name="clock" class="w-4 h-4" /> Ends in</span>
    <strong data-countdown-hours>00</strong><small>hrs</small>
    <strong data-countdown-minutes>00</strong><small>mins</small>
    <strong data-countdown-seconds>00</strong><small>secs</small>
  </div>
@else
  <span class="alm-countdown alm-countdown-static"><x-store.icon name="clock" class="w-4 h-4" /> Limited time</span>
@endif
