<?php if (!defined('ABSPATH')) { exit; } ?>

<section class="cmsg-shell cmsg-shell--premium" style="--cmsg-accent: <?php echo esc_attr($settings['accent_color']); ?>;">

    <div class="cmsg-hero cmsg-hero--premium">
        <div class="cmsg-hero__copy">
            <span class="cmsg-kicker">Crossmarket Creative Studio</span>
            <h1 class="cmsg-title">Subtitles, Posters & Trailer Requests</h1>
            <p class="cmsg-subtitle">Upload files, process subtitles, request creative services, and manage your media workflow securely.</p>
        </div>
    </div>

    <section class="cmsg-source-section">
        <div class="cmsg-section-heading">
            <span class="cmsg-kicker">Step 1</span>
<h2 style="display:block !important; visibility:visible !important; opacity:1 !important; color:#111827 !important; -webkit-text-fill-color:#111827 !important; font-size:30px !important; font-weight:900 !important; line-height:1.2 !important; margin:0 0 8px 0 !important;">Choose Your Workflow</h2>
            <p>Select the option that best matches your file size or project need.</p>
        </div>

        <div class="cmsg-source-grid">
            <button type="button" class="cmsg-source-card is-active" data-cmsg-tab="small">
                <span class="cmsg-source-icon">⬆</span>
                <strong>Upload Small File</strong>
                <span>Best for browser uploads up to <?php echo esc_html($settings['small_upload_limit_gb']); ?>GB.</span>
            </button>

            <button type="button" class="cmsg-source-card" data-cmsg-tab="large_upload">
                <span class="cmsg-source-icon">☁</span>
                <strong>Upload Large File Securely</strong>
                <span>Direct secure upload to Google Cloud. No SFTP required.</span>
            </button>

            <button type="button" class="cmsg-source-card" data-cmsg-tab="drive">
                <span class="cmsg-source-icon">Drive</span>
                <strong>Import from Google Drive</strong>
                <span>Paste a Google Drive share link.</span>
            </button>

           <?php /* <button type="button" class="cmsg-source-card" data-cmsg-tab="poster">
                <span class="cmsg-source-icon">🎬</span>
                <strong>Poster Design</strong>
                <span>Request AI-assisted poster concepts.</span>
            </button> */ ?>
        </div>
    </section>

    <div class="cmsg-flow-layout">
        <div class="cmsg-flow-main">

            <!-- OPTION 1: SMALL FILE -->
            <div class="cmsg-tab-panel is-active" data-cmsg-panel="small">
                <div class="cmsg-card cmsg-card--glass">
                    <div class="cmsg-card-head">
                        <div>
                            <span class="cmsg-kicker">Option 1</span>
                            <h3>Upload Small File</h3>
                        </div>
                        <span class="cmsg-inline-status">Up to <?php echo esc_html($settings['small_upload_limit_gb']); ?>GB</span>
                    </div>

                    <form id="cmsg-upload-form" enctype="multipart/form-data">
                        <div class="cmsg-grid">
                            <label><span>Email</span><input type="email" name="requester_email" required></label>
                            <label><span>Runtime minutes</span><input type="number" min="1" step="1" name="runtime_minutes" id="cmsg-runtime-minutes" required></label>

                            <label><span>Output Type</span>
                                <select name="caption_mode" class="cmmt-caption-mode">
                                    <option value="subtitle">Subtitle Only — dialogue transcription</option>
                                    <option value="closed_caption">Closed Captioning — dialogue + sound cues</option>
                                </select>
                            </label>

                            <label><span>Language</span>
                                <select name="language_code">
                                    <option value="auto">Auto-detect</option>
                                    <option value="en">English</option>
                                    <option value="fr">French</option>
                                    <option value="es">Spanish</option>
                                    <option value="pt">Portuguese</option>
                                    <option value="sw">Swahili</option>
                                </select>
                            </label>
<label>
    <span>Spoken language in video</span>
    <select name="source_language">
        <option value="auto">Auto Detect</option>
        <option value="en">English</option>
        <option value="ig">Igbo</option>
        <option value="yo">Yoruba</option>
        <option value="ha">Hausa</option>
        <option value="sw">Swahili</option>
        <option value="fr">French</option>
        <option value="es">Spanish</option>
        <option value="pt">Portuguese</option>
        <option value="zh">Mandarin Chinese</option>
    </select>
</label>

<label>
    <span>Output subtitle language</span>
    <select name="output_language">
        <option value="same">Same as spoken language</option>
        <option value="en">English</option>
        <option value="ig">Igbo</option>
        <option value="yo">Yoruba</option>
        <option value="ha">Hausa</option>
        <option value="sw">Swahili</option>
        <option value="fr">French</option>
        <option value="es">Spanish</option>
        <option value="pt">Portuguese</option>
        <option value="zh">Mandarin Chinese</option>
    </select>
</label>

<label>
    <span>Translation mode</span>
    <select name="translation_mode">
        <option value="none">No translation</option>
        <option value="translate_to_english">Translate to English</option>
        <option value="custom_output">Translate to selected output language</option>
    </select>
</label>

                            <label><span>Speech model</span>
<small class="cmmt-language-note">
    For Yoruba, Igbo, and Hausa, use Medium model for better accuracy. Results may require human review. 
</small>
                                <select name="model_size">
                                    <option value="<?php echo esc_attr($settings['default_model']); ?>"><?php echo esc_html(ucfirst($settings['default_model'])); ?> default</option>
                                    <option value="tiny">Tiny</option>
                                    <option value="base">Base</option>
                                    <option value="small">Small</option>
                                    <option value="medium">Medium</option>
                                </select>
                            </label>

                            <label class="cmsg-file">
                                <span>Select video file</span>
                                <input type="file" name="video_file" id="cmsg-video-file" accept="video/mp4,video/quicktime,video/x-matroska,video/webm,video/x-msvideo,video/m4v" required>
                            </label>
                        </div>

                        <div class="cmsg-order-summary">
                            <div>
                                <span class="cmsg-order-label">Order Summary</span>
                                <strong>Estimated subtitle total</strong>
                            </div>
                            <div class="cmsg-order-total" id="cmsg-subtitle-estimate"><?php echo esc_html(CMSG_Admin_Settings::money(0)); ?></div>
                        </div>

                        <?php if ($settings['paypal_enabled'] === '1') : ?>
                            <div class="cmsg-paywall">
                                <div class="cmsg-paywall__copy">
                                    <span class="cmsg-kicker">Payment</span>
                                    <h4>Complete payment with PayPal</h4>
                                    <p>Processing begins after payment is confirmed.</p>
                                </div>
                                <div id="cmsg-paypal-subtitle" class="cmsg-paypal-buttons"></div>
                            </div>
                        <?php endif; ?>

                        <div class="cmsg-actions">
                            <button type="submit" class="cmsg-btn cmsg-btn--primary">Finalize Paid Draft</button>
                            <button type="button" class="cmsg-btn cmsg-btn--ghost" data-cmsg-switch="large_upload">Need Secure Large Upload?</button>
                        </div>
                    </form>

                    <div id="cmsg-status" class="cmsg-status"></div>
                </div>
            </div>

            <!-- OPTION 2: SECURE LARGE UPLOAD -->
            <div class="cmsg-tab-panel" data-cmsg-panel="large_upload">
                <div class="cmsg-card cmsg-card--glass">
                    <div class="cmsg-card-head">
                        <div>
                            <span class="cmsg-kicker">Option 2</span>
                            <h3>Upload Large File Securely</h3>
                        </div>
                        <span class="cmsg-inline-status">Google Cloud Upload</span>
                    </div>

                    <p class="cmsg-helper">
                        Fill out your information, select your large file, then click prepare upload. Once the upload reaches 100%, complete PayPal payment before processing begins.
                    </p>

                    <form id="cmmt-large-upload-form" enctype="multipart/form-data">
                        <div class="cmsg-grid">
                            <label><span>Email</span><input type="email" name="request_email" required></label>
                            <label><span>Runtime minutes</span><input type="number" min="1" step="1" name="runtime_minutes" id="cmmt-large-runtime" required></label>

                            <label><span>Output Type</span>
                                <select name="caption_mode" class="cmmt-caption-mode">
                                    <option value="subtitle">Subtitle Only — dialogue transcription</option>
                                    <option value="closed_caption">Closed Captioning — dialogue + sound cues</option>
                                </select>
                            </label>

                            <label><span>Language</span>
                                <select name="language_code">
                                    <option value="auto">Auto-detect</option>
                                    <option value="en">English</option>
                                    <option value="fr">French</option>
                                    <option value="es">Spanish</option>
                                    <option value="es">Spanish</option>
                                    <option value="pt">Portuguese</option>
                                    <option value="sw">Swahili</option>
                                </select>
                            </label>

<label>
    <span>Spoken language in video</span>
    <select name="source_language">
        <option value="auto">Auto Detect</option>
        <option value="en">English</option>
        <option value="ig">Igbo</option>
        <option value="yo">Yoruba</option>
        <option value="ha">Hausa</option>
        <option value="sw">Swahili</option>
        <option value="fr">French</option>
        <option value="es">Spanish</option>
        <option value="pt">Portuguese</option>
        <option value="zh">Mandarin Chinese</option>
    </select>
</label>

<label>
    <span>Output subtitle language</span>
    <select name="output_language">
        <option value="same">Same as spoken language</option>
        <option value="en">English</option>
        <option value="ig">Igbo</option>
        <option value="yo">Yoruba</option>
        <option value="ha">Hausa</option>
        <option value="sw">Swahili</option>
        <option value="fr">French</option>
        <option value="es">Spanish</option>
        <option value="pt">Portuguese</option>
        <option value="zh">Mandarin Chinese</option>
    </select>
</label>

<label>
    <span>Translation mode</span>
    <select name="translation_mode">
        <option value="none">No translation</option>
        <option value="translate_to_english">Translate to English</option>
        <option value="custom_output">Translate to selected output language</option>
    </select>
</label>

                            <label><span>Speech model</span>
<small class="cmmt-language-note">
    For Yoruba, Igbo, and Hausa, use Medium model for better accuracy. Results may require human review. 
</small>
                                <select name="model_size">
                                    <option value="<?php echo esc_attr($settings['default_model']); ?>"><?php echo esc_html(ucfirst($settings['default_model'])); ?> default</option>
                                    <option value="tiny">Tiny</option>
                                    <option value="base">Base</option>
                                    <option value="small">Small</option>
                                    <option value="medium">Medium</option>
                                </select>
                            </label>

                            <label class="cmsg-file">
                                <span>Select large video file</span>
                                <input type="file" name="large_video_file" id="cmmt-large-video-file" accept="video/mp4,video/quicktime,video/x-matroska,video/webm,video/x-msvideo,video/m4v" required>
                                <small>Supported formats: MP4, MOV, MKV, AVI, WEBM, M4V.</small>
                            </label>
                        </div>

                        <div class="cmmt-large-flow-actions">
                            <button type="button" class="cmsg-btn cmsg-btn--primary" id="cmmt-prepare-large-upload">
                                Click to Prepare Upload
                            </button>
                        </div>

                        <div class="cmmt-upload-monitor">
                            <div id="cmmt-large-upload-status" class="cmmt-status-bar">
                                Waiting for file selection.
                            </div>

                            <div class="cmmt-progress-wrap">
                                <div class="cmmt-progress-label">
                                    <span>Progress</span>
                                    <strong id="cmmt-large-progress-percent">0%</strong>
                                </div>
                                <div class="cmmt-progress-track">
                                    <div id="cmmt-large-progress-bar" class="cmmt-progress-fill" style="width:0%;"></div>
                                </div>
                            </div>
                        </div>

                        <div class="cmsg-order-summary">
                            <div>
                                <span class="cmsg-order-label">Order Summary</span>
                                <strong>Estimated subtitle total</strong>
                            </div>
                            <div class="cmsg-order-total" id="cmmt-large-estimate"><?php echo esc_html(CMSG_Admin_Settings::money(0)); ?></div>
                        </div>

<div id="cmmt-large-download-wrap" class="cmmt-download-wrap" style="display:none;">
                            <a href="#" id="cmmt-large-download-link" class="cmsg-btn cmsg-btn--primary">Download Subtitle File</a>
                        </div>

                        <?php if ($settings['paypal_enabled'] === '1') : ?>
                            <div class="cmsg-paywall cmmt-large-paywall is-disabled" id="cmmt-large-paywall">
                                <div class="cmsg-paywall__copy">
                                    <span class="cmsg-kicker">Payment</span>
                                    <h4>Complete payment with PayPal</h4>
                                    <p>Payment unlocks subtitle processing after the upload reaches 100%.</p>
                                </div>

                                <div id="cmmt-paypal-large-upload" class="cmsg-paypal-buttons"></div>
                            </div>
                        <?php endif; ?>

                        <button type="submit" class="cmsg-btn cmsg-btn--primary" id="cmmt-finalize-large-upload" style="display:none;">
                            Finalize Paid Large Upload Draft
                        </button>
                    </form>
                </div>
            </div>

<!-- OPTION 3: GOOGLE DRIVE -->
<div class="cmsg-tab-panel" data-cmsg-panel="drive">
    <div class="cmsg-card cmsg-card--glass">
        <div class="cmsg-card-head">
            <div>
                <span class="cmsg-kicker">Option 3</span>
                <h3>Import from Google Drive</h3>
            </div>
            <span class="cmsg-inline-status">Drive intake</span>
        </div>

        <form id="cmmt-drive-form">
            <input type="hidden" name="source_type" value="google_drive">

            <div class="cmsg-grid">
                <label>
                    <span>Email</span>
                    <input type="email" name="request_email" required>
                </label>

                <label>
                    <span>Runtime minutes</span>
                    <input type="number" min="1" step="1" name="runtime_minutes" id="cmmt-drive-runtime" required>
                </label>

                <label>
                    <span>Output Type</span>
                    <select name="caption_mode" class="cmmt-caption-mode">
                        <option value="subtitle">Subtitle Only — dialogue transcription</option>
                        <option value="closed_caption">Closed Captioning — dialogue + sound cues</option>
                    </select>
                </label>

<label>
    <span>Spoken language in video</span>
    <select name="source_language">
        <option value="auto">Auto Detect</option>
        <option value="en">English</option>
        <option value="ig">Igbo</option>
        <option value="yo">Yoruba</option>
        <option value="ha">Hausa</option>
        <option value="sw">Swahili</option>
        <option value="fr">French</option>
        <option value="es">Spanish</option>
        <option value="pt">Portuguese</option>
        <option value="zh">Mandarin Chinese</option>
    </select>
</label>

<label>
    <span>Output subtitle language</span>
    <select name="output_language">
        <option value="same">Same as spoken language</option>
        <option value="en">English</option>
        <option value="ig">Igbo</option>
        <option value="yo">Yoruba</option>
        <option value="ha">Hausa</option>
        <option value="sw">Swahili</option>
        <option value="fr">French</option>
        <option value="es">Spanish</option>
        <option value="pt">Portuguese</option>
        <option value="zh">Mandarin Chinese</option>
    </select>
</label>

<label>
    <span>Translation mode</span>
    <select name="translation_mode">
        <option value="none">No translation</option>
        <option value="translate_to_english">Translate to English</option>
        <option value="custom_output">Translate to selected output language</option>
    </select>
</label>

<label>
    <span>Speech model</span>
    <select name="model_size">
        <option value="<?php echo esc_attr($settings['default_model']); ?>">
            <?php echo esc_html(ucfirst($settings['default_model'])); ?> default
        </option>
        <option value="tiny">Tiny</option>
        <option value="base">Base</option>
        <option value="small">Small</option>
        <option value="medium">Medium</option>
    </select>
    <small class="cmmt-language-note">
        For Yoruba, Igbo, and Hausa, use Medium model for better accuracy. Results may require human review.
    </small>
</label>

                <label class="cmsg-file">
                    <span>Google Drive share link</span>
                    <input type="url" name="drive_link" id="cmmt-drive-link" placeholder="https://drive.google.com/file/d/..." required>
                </label>
            </div>
<div class="cmsg-drive-warning">
  Google Drive import is recommended for files under 2GB only.
  For files over 2GB, feature films, 4K exports, masters, or long-form content,
  please use the Large File / Google Cloud upload option.
</div>

            <div class="cmsg-actions">
                <button type="button" class="cmsg-btn cmsg-btn--ghost" id="cmmt-validate-drive">
                    Validate Drive Link
                </button>
            </div>

            <div id="cmmt-drive-status" class="cmsg-status">
                Paste a Google Drive link, then click Validate Drive Link.
            </div>

            <div id="cmmt-drive-progress-status" class="cmmt-status-bar">
                <strong id="cmmt-drive-progress-percent">0%</strong>
                <div class="cmmt-progress-track">
                    <div id="cmmt-drive-progress-bar" class="cmmt-progress-fill" style="width:0%;"></div>
                </div>
            </div>

<div class="cmsg-order-summary">
    <span>Order Summary</span>
    <strong>Estimated subtitle total</strong>
    <div class="cmsg-order-total" id="cmmt-drive-estimate">$0.00</div>
</div>

            <div id="cmmt-drive-download-wrap" class="cmmt-download-wrap" style="display:none;">
                <a href="#" id="cmmt-drive-download-link" class="cmsg-btn cmsg-btn--primary">
                    Download Subtitle File
                </a>
            </div>

            <div class="cmsg-paywall" id="cmmt-drive-paywall">
                <p>Complete PayPal payment to begin processing your Google Drive video.</p>
                <div id="cmmt-paypal-drive" class="cmsg-paypal-buttons"></div>
            </div>
        </form>
    </div>
</div>

<?php /*
            <!-- OPTION 4: POSTER DESIGN -->
            <div class="cmsg-tab-panel" data-cmsg-panel="poster">
                <div class="cmsg-card cmsg-card--glass">
                    <div class="cmsg-card-head">
                        <div>
                            <span class="cmsg-kicker">Poster Studio</span>
                            <h3>AI Poster Design Request</h3>
                        </div>
                        <span class="cmsg-inline-status">Creative order</span>
                    </div>

                    <form id="cmmt-poster-form" enctype="multipart/form-data">
                        <div class="cmsg-grid">
                            <label><span>Email</span><input type="email" name="request_email" required></label>
                            <label><span>Project title</span><input type="text" name="title" required></label>
                            <label><span>Tagline</span><input type="text" name="tagline"></label>
                            <label><span>Genre</span><input type="text" name="genre" placeholder="Thriller, Romance, Action..."></label>

                            <label class="cmsg-file">
                                <span>Describe what you want in your poster</span>
                                <textarea name="poster_description" rows="5" placeholder="Describe style, mood, characters, colors, references, and poster direction."></textarea>
                            </label>

                            <label class="cmsg-file">
                                <span>Upload poster elements / references</span>
                                <input type="file" name="poster_assets[]" multiple>
                            </label>
                        </div>

                        <div id="cmmt-paypal-poster-upload" class="cmsg-paypal-buttons"></div>

                        <div class="cmsg-actions">
                            <button type="submit" class="cmsg-btn cmsg-btn--primary">Generate Poster Concepts</button>
                        </div>
                    </form>

                    <div id="cmmt-poster-status" class="cmsg-status"></div>
                </div>
            </div>

*/ ?>

        </div>

        <aside class="cmsg-flow-sidebar">
            <div class="cmsg-card cmsg-card--sidebar">
                <span class="cmsg-kicker">Workflow</span>
                <h3>How it works</h3>
                <ul class="cmsg-progress">
                    <li>Choose workflow</li>
                    <li>Upload or submit project details</li>
                    <li>Pay securely with PayPal</li>
                    <li>Processing begins</li>
                    <li>Download when ready</li>
                </ul>
            </div>

            <?php if (current_user_can('manage_options')) : ?>
                <div class="cmsg-card cmsg-card--sidebar">
                    <span class="cmsg-kicker">Admin Tools</span>
                    <h3>Internal File Tools</h3>
                    <p>Use admin tools for internal fallback processing.</p>
                    <a href="/wp-admin/admin.php?page=cmsg-jobs" class="cmsg-btn cmsg-btn--ghost">Open Admin Jobs</a>
                </div>
            <?php endif; ?>
        </aside>
    </div>
</section>
