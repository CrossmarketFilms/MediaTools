<?php if (!defined('ABSPATH')) { exit; } ?>
<section class="cmmt-trailer-shell">
  <div class="cmmt-trailer-hero">
    <div>
      <span class="cmsg-kicker">Trailer Studio v3.0</span>
      <h2>Build a structured trailer brief</h2>
      <p>Turn your description, tone, required scenes, text cards, music direction, and CTA into a paid trailer edit package that the production team can execute consistently.</p>
    </div>
  </div>

  <form id="cmmt-trailer-form" class="cmmt-trailer-form" enctype="multipart/form-data">
    <div class="cmsg-grid">
      <label><span>Email</span><input type="email" name="request_email" required></label>
      <label><span>Project title</span><input type="text" name="title" required></label>
      <label><span>Trailer type</span>
        <select name="trailer_type">
          <option value="teaser">Teaser</option>
          <option value="official_trailer">Official Trailer</option>
          <option value="sales_trailer">Sales Trailer</option>
          <option value="social_cut">Social Promo Cut</option>
        </select>
      </label>
      <label><span>Target runtime</span>
        <select name="runtime_target">
          <option value="15_sec">15 seconds</option>
          <option value="30_sec">30 seconds</option>
          <option value="60_sec" selected>60 seconds</option>
          <option value="90_sec">90 seconds</option>
          <option value="120_sec">120 seconds</option>
        </select>
      </label>
      <label><span>Genre</span><input type="text" name="genre" placeholder="Thriller, drama, horror, romance..."></label>
      <label><span>Tone</span><input type="text" name="tone" placeholder="Dark, suspenseful, emotional, epic..."></label>
      <label><span>Target audience</span><input type="text" name="target_audience" placeholder="Netflix-style thriller fans, young adults..."></label>
      <label><span>Music style</span><input type="text" name="music_style" placeholder="Cinematic tension, Afrobeat, orchestral, pulse..."></label>
      <label><span>CTA / End card</span><input type="text" name="cta" placeholder="Coming Soon, Watch Now, Only on..."></label>
      <label class="cmsg-file"><span>Creative description</span><textarea name="description" rows="5" placeholder="Describe the trailer hook, story setup, pacing, conflict, reveal, and final feeling."></textarea></label>
      <label class="cmsg-file"><span>Required scenes / elements</span><textarea name="required_elements" rows="5" placeholder="One per line. Example: city skyline, crying woman, explosion, antagonist reveal, final title card."></textarea></label>
      <label class="cmsg-file"><span>Text cards / on-screen copy</span><textarea name="text_cards" rows="4" placeholder="One per line. Example: A secret buried for years / Now the truth returns / Coming Soon."></textarea></label>
      <label class="cmsg-file"><span>Asset links / editor notes</span><textarea name="asset_links" rows="4" placeholder="Paste Google Drive, GCS, Dropbox, YouTube, or internal asset notes. One per line."></textarea></label>
    </div>

    <div class="cmmt-trailer-brief-preview" id="cmmt-trailer-brief-preview">
      <strong>Trailer Brief Preview</strong>
      <p>Fill out the fields above to create a structured trailer brief. Required scenes and text cards will be converted into a timed beat map after payment.</p>
    </div>

    <div class="cmsg-order-summary">
      <div><span class="cmsg-order-label">Order Summary</span><strong>Structured trailer brief package</strong></div>
      <div class="cmsg-order-total" id="cmmt-trailer-estimate"><?php echo esc_html(CMSG_Admin_Settings::money((float) $settings['trailer_request_base_price'])); ?></div>
    </div>

    <?php if ($settings['paypal_enabled'] === '1') : ?>
      <div class="cmsg-paywall">
        <div class="cmsg-paywall__copy">
          <span class="cmsg-kicker">Payment</span>
          <h4>Complete payment with PayPal</h4>
          <p>Trailer Studio creates the paid structured trailer brief and beat-map package after PayPal capture is validated server-side.</p>
        </div>
        <div id="cmmt-paypal-trailer" class="cmsg-paypal-buttons"></div>
      </div>
    <?php endif; ?>

    <div class="cmsg-actions"><button type="submit" class="cmsg-btn cmsg-btn--primary">Finalize Paid Trailer Brief</button></div>
  </form>

  <div id="cmmt-trailer-status" class="cmsg-status"></div>
  <div id="cmmt-trailer-downloads" class="cmmt-trailer-downloads"></div>
</section>
