<?php if (!defined('ABSPATH')) { exit; } ?>
<section id="cmmt-poster-studio" class="cmmt-poster-shell cmmt-poster-v321" style="--cmsg-accent: <?php echo esc_attr($settings['accent_color']); ?>;">
  <div class="cmmt-poster-hero">
    <div>
      <span class="cmsg-kicker">Poster Studio v3.2.1</span>
      <h2 style="display:block !important; visibility:visible !important; opacity:1 !important; color:#111827 !important; -webkit-text-fill-color:#111827 !important; font-size:30px !important; font-weight:900 !important; line-height:1.2 !important; margin:0 0 8px 0 !important;">Generate cinematic poster concepts</h2>
      <p style="display:block !important; visibility:visible !important; opacity:1 !important; color:#374151 !important; -webkit-text-fill-color:#374151 !important; font-size:16px !important; font-weight:600 !important; line-height:1.55 !important; margin:0 0 18px 0 !important;">Provide the film details, principal cast, visual references, and one poster scene direction. Previews include cast placement before payment.</p>
    </div>
    <div class="cmmt-mini-note">Poster Studio is isolated from Subtitle and Trailer workflows.</div>
  </div>

  <ol class="cmmt-poster-steps" id="cmmt-poster-steps">
    <li class="is-active" style="display:inline-flex !important; align-items:center !important; gap:8px !important; visibility:visible !important; opacity:1 !important; color:#111827 !important; -webkit-text-fill-color:#111827 !important;">
      <span style="display:inline-flex !important; align-items:center !important; justify-content:center !important; width:28px !important; height:28px !important; min-width:28px !important; border-radius:999px !important; background:#111827 !important; color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; font-weight:900 !important;">1</span>
      <strong style="display:inline-block !important; visibility:visible !important; opacity:1 !important; color:#111827 !important; -webkit-text-fill-color:#111827 !important; font-weight:900 !important;">Movie Details</strong>
    </li>
    <li><span>2</span><strong>Cast & References</strong></li>
    <li><span>3</span><strong>Preview & Select</strong></li>
    <li><span>4</span><strong>Pay to Unlock</strong></li>
    <li><span>5</span><strong>Final Downloads</strong></li>
  </ol>

  <form id="cmmt-poster-form" class="cmmt-poster-form" enctype="multipart/form-data">
    <div class="cmsg-card cmsg-card--glass cmmt-poster-step-card">
      <span class="cmsg-kicker">Step 1: Movie Details</span>
      <div class="cmsg-grid">
        <label><span>Email</span><input type="email" name="request_email" required></label>
        <label><span>Movie title</span><input type="text" name="title" required></label>
        <label><span>Tagline</span><input type="text" name="tagline"></label>
        <label><span>Genre</span><input type="text" name="genre" placeholder="Thriller, Action, Romance"></label>
        <label><span>Mood</span><input type="text" name="mood" placeholder="Dark, Premium, Romantic"></label>
        <label><span>Style preset</span>
          <select name="style_preset">
            <option value="cinematic_premium">Cinematic Premium</option>
            <option value="festival_prestige">Festival Prestige</option>
            <option value="streaming_key_art">Streaming Key Art</option>
            <option value="bold_thriller">Bold Thriller</option>
          </select>
        </label>
        <label><span>Title font style</span>
          <select name="title_font_style">
            <option value="cinematic_bold">Cinematic Bold</option>
            <option value="luxury_serif">Luxury Serif</option>
            <option value="modern_sans">Modern Sans</option>
            <option value="horror_bold">Horror Bold</option>
            <option value="action_block">Action Block</option>
          </select>
        </label>
        <label><span>Tagline font style</span>
          <select name="tagline_font_style">
            <option value="clean_sans">Clean Sans</option>
            <option value="elegant_serif">Elegant Serif</option>
            <option value="condensed">Condensed</option>
          </select>
        </label>
        <label>
          <span>Title placement</span>
          <select name="title_position">
            <option value="bottom_cinematic">Bottom Cinematic</option>
            <option value="lower_third">Lower Third</option>
            <option value="top_minimal">Top Minimal</option>
            <option value="streaming_style">Streaming Style</option>
          </select>
        </label>
        <label class="cmmt-checkbox">
          <input type="checkbox" name="preserve_identity" value="1" checked>
          <span>Preserve uploaded faces/characters</span>
        </label>
      </div>
    </div>

    <div id="cmmt-cast-builder-panel" class="cmsg-card cmsg-card--glass cmmt-poster-step-card">
      <span class="cmsg-kicker">Step 2A: Principal Cast</span>
      <p>Upload actor reference images here. Choose whether each person is a Lead Character or Supporting Character so the poster composition can prioritize the cast correctly.</p>
      <div id="cmmt-principal-cast-list" class="cmmt-principal-cast-list" data-initial-count="3" data-max-count="10"></div>
      <button type="button" class="cmsg-btn" id="cmmt-add-cast-member">Add Another Cast Member</button>
      <template id="cmmt-cast-member-template">
        <div class="cmmt-cast-member-card" data-cast-index="__INDEX__">
          <h4 class="cmmt-cast-member-title">Cast Member __NUMBER__</h4>
          <div class="cmsg-grid">
            <label><span>Actor / Character Name</span>
              <input type="text" name="cast_members[__INDEX__][name]" class="cmmt-cast-name" placeholder="Character or actor name">
            </label>
            <label><span>Role</span>
              <select name="cast_members[__INDEX__][role]" class="cmmt-cast-role">
                <option value="lead">Lead Character</option>
                <option value="supporting">Supporting Character</option>
              </select>
            </label>
            <label class="cmsg-file"><span>Actor Reference Image</span>
              <input type="file" name="cast_members[__INDEX__][image]" class="cmmt-cast-image" accept="image/jpeg,image/png,image/webp">
              <small>JPG, JPEG, PNG, or WEBP only. HEIC, HEIF, SVG, and PDF are not supported.</small>
            </label>
            <label class="cmsg-file"><span>Character Direction / Instruction</span>
              <textarea name="cast_members[__INDEX__][instruction]" class="cmmt-cast-instruction" rows="3" placeholder="Father, solemn on the left side. Daughter, sad and disappointed on the right side."></textarea>
            </label>
          </div>
        </div>
      </template>
      <div class="cmmt-legacy-cast-fields" style="display:none;">
        <input type="file" name="cast_actor_1" id="cmmt-cast-actor-1" accept="image/jpeg,image/png,image/webp">
        <input type="file" name="cast_actor_2" id="cmmt-cast-actor-2" accept="image/jpeg,image/png,image/webp">
        <input type="file" name="cast_actor_3" id="cmmt-cast-actor-3" accept="image/jpeg,image/png,image/webp">
        <input type="hidden" name="cast_actor_1_instruction" id="cmmt-cast-actor-1-instruction">
        <input type="hidden" name="cast_actor_2_instruction" id="cmmt-cast-actor-2-instruction">
        <input type="hidden" name="cast_actor_3_instruction" id="cmmt-cast-actor-3-instruction">
      </div>
    </div>

    <div class="cmsg-card cmsg-card--glass cmmt-poster-step-card">
      <span class="cmsg-kicker">Step 2B: Props, Logos & Visual References</span>
      <div class="cmsg-grid">
        <label class="cmsg-file cmmt-wide">
          <span>Props, Logos & Visual References</span>
          <p class="cmmt-upload-hint"><strong>Do not upload actor photos here. Use Principal Cast for actors. Upload only props, logos, objects, vehicles, buildings, products, or visual references that should appear in the final poster.</strong></p>
          <input type="file" name="poster_assets[]" id="cmmt-poster-assets" multiple accept="image/jpeg,image/png,image/webp">
          <small>Accepted image formats: JPG, JPEG, PNG, and WEBP only.</small>
        </label>
      </div>
    </div>

    <div class="cmsg-card cmsg-card--glass cmmt-poster-step-card">
      <span class="cmsg-kicker">Step 2C: Poster Scene Direction</span>
      <label class="cmsg-file cmmt-wide">
        <span>Poster Scene Direction</span>
        <p>Describe the full poster scene, cast placement, mood, props, symbolism, and story moment.</p>
        <textarea name="poster_description" id="cmmt-poster-scene-direction" rows="5" placeholder="Actor 1 stands between Actor 2 and Actor 3. Actor 2 holds a rose. Actor 3 watches from the shadows. A broken heart appears above them. Lagos skyline in the background. Dark emotional tone."></textarea>
      </label>
    </div>

    <div class="cmsg-card cmsg-card--glass cmmt-poster-step-card">
      <span class="cmsg-kicker">Step 3: Generate Watermarked Previews</span>
      <div class="cmmt-poster-actions">
        <button type="button" class="cmsg-btn cmsg-btn--primary" id="cmmt-generate-previews">Generate Watermarked Previews</button>
        <div class="cmmt-price">Poster package price: <strong><?php echo esc_html(CMSG_Admin_Settings::money(CMSG_Posters::price())); ?></strong></div>
      </div>
      <div id="cmmt-poster-progress-wrap" class="cmmt-status-bar" style="display:none;">
        <div class="cmmt-progress-track"><div id="cmmt-poster-progress-bar" class="cmmt-progress-fill" style="width:0%;"></div></div>
        <div id="cmmt-poster-progress-label" class="cmmt-progress-label">Preparing poster request...</div>
      </div>
      <div id="cmmt-poster-preview-grid" class="cmmt-poster-preview-grid"></div>
    </div>

    <?php if ($settings['paypal_enabled'] === '1') : ?>
      <div class="cmsg-card cmsg-card--glass cmmt-poster-step-card" id="cmmt-poster-payment-card">
        <span class="cmsg-kicker" style="color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; display:block !important; visibility:visible !important; opacity:1 !important;">Step 4: Pay to Unlock</span>
        <div class="cmsg-paywall cmsg-paywall--poster">
          <div class="cmsg-paywall__copy">
            <h4 style="color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; display:block !important; visibility:visible !important; opacity:1 !important;">Complete payment with PayPal</h4>
            <p style="color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; display:block !important; visibility:visible !important; opacity:1 !important;">Select a watermarked preview first. After payment, portrait and landscape files will be exported from that selected preview.</p>
          </div>
          <div id="cmmt-poster-status" class="cmsg-status"></div>
          <div id="cmmt-paypal-poster" class="cmsg-paypal-buttons"></div>
        </div>
        <div class="cmmt-poster-actions">
          <button type="submit" class="cmsg-btn cmsg-btn--primary" id="cmmt-finalize-poster">Create Final Posters & Download</button>
        </div>
        <div id="cmmt-finalize-progress-wrap" class="cmmt-finalize-status" style="display:none;">
          <div class="cmmt-progress-track"><div id="cmmt-finalize-progress-bar" class="cmmt-progress-fill" style="width:0%;"></div></div>
          <div id="cmmt-finalize-progress-label" class="cmmt-progress-label">Waiting to create final posters...</div>
          <p class="cmmt-finalize-help">Your selected preview will be exported into Vertical Poster (900 x 1285) and Landscape/Banner Poster (896 x 504). Download buttons will appear below when complete, and an email will be sent to your inbox.</p>
        </div>
      </div>
    <?php endif; ?>
  </form>

  <div class="cmsg-card cmsg-card--glass cmmt-poster-step-card" id="cmmt-poster-download-card">
    <span class="cmsg-kicker" style="color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; display:block !important; visibility:visible !important; opacity:1 !important;">Step 5: Final Downloads</span>
    <p class="cmmt-download-instructions" style="color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; display:block !important; visibility:visible !important; opacity:1 !important;">
      After final creation, your poster files will appear here in downloadable formats.
    </p>
    <div id="cmmt-poster-downloads" class="cmmt-poster-downloads"></div>
  </div>

  <div class="cmsg-card cmsg-card--glass" id="cmmt-prompt-preview-card" style="display:none;">
    <span class="cmsg-kicker">AI prompt preview</span>
    <pre id="cmmt-prompt-preview" style="white-space:pre-wrap;"></pre>
  </div>

  <div id="cmmt-poster-modal" class="cmmt-poster-modal" style="display:none;">
    <div class="cmmt-poster-modal__backdrop"></div>
    <div class="cmmt-poster-modal__content">
      <button type="button" id="cmmt-poster-modal-close" class="cmmt-poster-modal__close">&times;</button>
      <img id="cmmt-poster-modal-img" src="" alt="Poster preview">
      <button type="button" id="cmmt-poster-modal-select" class="cmsg-btn cmsg-btn--primary">Select This Concept</button>
    </div>
  </div>
</section>
