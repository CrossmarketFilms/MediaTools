<?php if (!defined('ABSPATH')) { exit; } ?>
<section id="cmmt-poster-studio" class="cmmt-poster-shell cmmt-poster-v283" style="--cmsg-accent: <?php echo esc_attr($settings['accent_color']); ?>;">
  <div class="cmmt-poster-hero">
    <div>
      <span class="cmsg-kicker">Poster Studio v3.0.1</span>
      <h2 style="display:block !important; visibility:visible !important; opacity:1 !important; color:#111827 !important; -webkit-text-fill-color:#111827 !important; font-size:30px !important; font-weight:900 !important; line-height:1.2 !important; margin:0 0 8px 0 !important;">Generate cinematic poster concepts</h2>
     <p style="display:block !important; visibility:visible !important; opacity:1 !important; color:#374151 !important; -webkit-text-fill-color:#374151 !important; font-size:16px !important; font-weight:600 !important; line-height:1.55 !important; margin:0 0 18px 0 !important;">Upload references, generate watermarked previews, select one concept, then unlock clean final poster files after PayPal.</p>
    </div>
    <div class="cmmt-mini-note">Poster Studio is isolated from Subtitle and Trailer workflows.</div>
  </div>

  <ol class="cmmt-poster-steps" id="cmmt-poster-steps">
<li class="is-active" style="display:inline-flex !important; align-items:center !important; gap:8px !important; visibility:visible !important; opacity:1 !important; color:#111827 !important; -webkit-text-fill-color:#111827 !important;">
  <span style="display:inline-flex !important; align-items:center !important; justify-content:center !important; width:28px !important; height:28px !important; min-width:28px !important; border-radius:999px !important; background:#111827 !important; color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; font-weight:900 !important;">1</span>
  <strong style="display:inline-block !important; visibility:visible !important; opacity:1 !important; color:#111827 !important; -webkit-text-fill-color:#111827 !important; font-weight:900 !important;">Creative Brief</strong>
</li>
    <li><span>2</span><strong>Upload References</strong></li>
    <li><span>3</span><strong>Preview & Select</strong></li>
    <li><span>4</span><strong>Pay to Unlock</strong></li>
    <li><span>5</span><strong>Final Downloads</strong></li>
  </ol>

  <form id="cmmt-poster-form" class="cmmt-poster-form" enctype="multipart/form-data">
    <div class="cmsg-card cmsg-card--glass cmmt-poster-step-card">
      <span class="cmsg-kicker">Step 1: Creative Brief</span>
      <div class="cmsg-grid">
        <label><span>Email</span><input type="email" name="request_email" required></label>
        <label><span>Movie title</span><input type="text" name="title" required></label>
        <label><span>Tagline</span><input type="text" name="tagline"></label>
        <label><span>Title font style</span>
          <select name="title_font_style">
            <option value="cinematic_bold">Cinematic Bold</option>
            <option value="luxury_serif">Luxury Serif</option>
            <option value="modern_sans">Modern Sans</option>
            <option value="horror_bold">Horror Bold</option>
            <option value="action_block">Action Block</option>
          </select>
        </label>
<label class="cmmt-checkbox">
  <input type="checkbox" name="preserve_identity" value="1" checked>
  <span>Preserve uploaded faces/characters</span>
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
        <label class="cmsg-file cmmt-wide"><span>Description</span><textarea name="poster_description" rows="5" placeholder="Describe characters, background, tone, lighting, visual symbols, title placement, and any important poster direction."></textarea></label>
      </div>
    </div>

    <div class="cmsg-card cmsg-card--glass cmmt-poster-step-card">
      <span class="cmsg-kicker">Step 2: Upload References</span>
      <div class="cmsg-grid">
        <label class="cmsg-file">
          <span>Poster Style Reference</span>
          <p>Upload an example poster to guide mood, lighting, composition, palette, and overall key-art direction.</p>
          <input type="file" name="style_reference" id="cmmt-style-reference" accept="image/jpeg,image/png,image/webp">
        </label>

    <div class="cmmt-upload-hint">
        <strong>Accepted image formats:</strong> JPG, JPEG, PNG, and WEBP only.<br>
        HEIC/HEIF (iPhone Live Photos), SVG, PDF, and other formats are not currently supported.<br>
        For best results, upload clear images under 10MB with visible faces, characters, logos, or visual styles.
    </div>

<label class="cmsg-file">
    <span>Step 2B: Props, Logos & Visual References</span>

    <p class="cmmt-upload-hint">
        Do not upload actor photos here. Use Principal Cast for actors.
        Upload only props, logos, objects, vehicles, buildings, products,
        or visual references that should appear in the final poster.
    </p>

    <input
        type="file"
        name="poster_assets[]"
        id="cmmt-poster-assets"
        multiple
        accept="image/jpeg,image/png,image/webp">

    <small>
        Only JPG, PNG, and WEBP files are supported.
    </small>
</label>

      </div>
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

<div id="cmmt-cast-builder-panel" class="cmsg-card cmsg-card--glass" style="display:block; margin-top:18px;">
<span class="cmsg-kicker">Step 2A: Principal Cast</span>
<h4>Upload Actor Images</h4>
<p>Upload only the main actors whose faces should be preserved and automatically mapped into the AI poster.</p>

  <div class="cmsg-grid">
    <label>Actor 1 image
      <input type="file" name="cast_actor_1" id="cmmt-cast-actor-1" accept="image/jpeg,image/png,image/webp">
    </label>

    <label>Actor 1 instruction
      <input type="text" name="cast_actor_1_instruction" id="cmmt-cast-actor-1-instruction" placeholder="Actor 1 holds a rose toward Actor 2">
    </label>

    <label>Actor 2 image
      <input type="file" name="cast_actor_2" id="cmmt-cast-actor-2" accept="image/jpeg,image/png,image/webp">
    </label>

    <label>Actor 2 instruction
      <input type="text" name="cast_actor_2_instruction" id="cmmt-cast-actor-2-instruction" placeholder="Actor 2 looks surprised">
    </label>

    <label>Actor 3 image
      <input type="file" name="cast_actor_3" id="cmmt-cast-actor-3" accept="image/jpeg,image/png,image/webp">
    </label>

    <label>Actor 3 instruction
      <input type="text" name="cast_actor_3_instruction" id="cmmt-cast-actor-3-instruction" placeholder="Actor 3 stands alone in the background">
    </label>
  </div>

  <label>Step 2C: Scene Direction
    <textarea name="cast_scene_instruction" id="cmmt-cast-scene-instruction" rows="4" placeholder="Example: Actor 1 gives Actor 2 a rose while Actor 3 stands alone in the background. Use uploaded props/logos where relevant."></textarea>
  </label>
</div>


    <?php if ($settings['paypal_enabled'] === '1') : ?>
      <div class="cmsg-card cmsg-card--glass cmmt-poster-step-card" id="cmmt-poster-payment-card">

<span class="cmsg-kicker" style="color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; display:block !important; visibility:visible !important; opacity:1 !important;">
    Step 4: Pay to Unlock
</span>

<div class="cmsg-paywall cmsg-paywall--poster">
    <div class="cmsg-paywall__copy">
        <h4 style="color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; display:block !important; visibility:visible !important; opacity:1 !important;">
            Complete payment with PayPal
        </h4>

        <p style="color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; display:block !important; visibility:visible !important; opacity:1 !important;">
            Select a watermarked preview first. After payment, a portrait and landcape image will be created. A progress message will show while your selected poster is being prepared.
        </p>
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
          <p class="cmmt-finalize-help">Your selected image will be rendered into Vertical Poster (900 × 1285) and a Landscape/Banner Poster (896 × 504). Download buttons will appear below when complete, and an email will be sent to your inbox.</p>
        </div>
      </div>
    <?php endif; ?>
  </form>

<div class="cmsg-card cmsg-card--glass cmmt-poster-step-card" id="cmmt-poster-download-card">

    <span class="cmsg-kicker" style="color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; display:block !important; visibility:visible !important; opacity:1 !important;">
        Step 5: Final Downloads
    </span>

    <p class="cmmt-download-instructions"
       style="color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; display:block !important; visibility:visible !important; opacity:1 !important;">
        After final creation, your poster files will appear here in three downloadable formats.
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
      <button type="button" id="cmmt-poster-modal-close" class="cmmt-poster-modal__close">×</button>
      <img id="cmmt-poster-modal-img" src="" alt="Poster preview">
      <button type="button" id="cmmt-poster-modal-select" class="cmsg-btn cmsg-btn--primary">Select This Concept</button>
    </div>
  </div>
</section>
