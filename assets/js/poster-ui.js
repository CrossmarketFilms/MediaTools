jQuery(function($){
  if (!$('#cmmt-poster-form').length) return;

  let posterDraftId = null;
  let posterPaymentToken = '';
  let posterJobId = null;
  let selectedConcept = 0;
  let previewUrls = [];
  let modalConceptIndex = 0;

  function setStatus(html, state){
    const el = $('#cmmt-poster-status');
    if (window.CMMT && typeof window.CMMT.setStatus === 'function') {
      window.CMMT.setStatus(el, html, state);
    } else {
      el.removeClass('is-working is-success is-error').addClass(state || '').html(html);
    }
  }

  function setStep(index){
    $('#cmmt-poster-steps li').removeClass('is-active is-complete').each(function(i){
      if (i < index) $(this).addClass('is-complete');
      if (i === index) $(this).addClass('is-active');
    });
  }

  function setPosterProgress(percent, label){
    percent = Math.max(0, Math.min(100, parseInt(percent || 0, 10)));
    $('#cmmt-poster-progress-wrap').show();
    $('#cmmt-poster-progress-bar').css('width', percent + '%');
    if (label) $('#cmmt-poster-progress-label').text(label);
  }

  function startPosterProgress(){
    setPosterProgress(8, 'Creating poster draft...');
  }

  function finishPosterProgress(){
    setPosterProgress(100, 'Previews ready.');
    setTimeout(function(){ $('#cmmt-poster-progress-wrap').fadeOut(300); }, 1200);
  }

  function setFinalizeProgress(percent, label){
    percent = Math.max(0, Math.min(100, parseInt(percent || 0, 10)));
    $('#cmmt-finalize-progress-wrap').show();
    $('#cmmt-finalize-progress-bar').css('width', percent + '%');
    if (label) $('#cmmt-finalize-progress-label').html(label);
  }

  function resetFinalizeProgress(){
    $('#cmmt-finalize-progress-bar').css('width', '0%');
    $('#cmmt-finalize-progress-label').html('Waiting to create final posters...');
  }

  function castMaxCount(){
    return parseInt($('#cmmt-principal-cast-list').data('max-count') || 10, 10);
  }

  function addCastMember(index){
    var list = $('#cmmt-principal-cast-list');
    var template = $('#cmmt-cast-member-template').html();
    if (!list.length || !template) return;

    var number = index + 1;
    var html = template.replace(/__INDEX__/g, index).replace(/__NUMBER__/g, number);
    list.append(html);
    var card = list.find('.cmmt-cast-member-card').last();
    card.find('.cmmt-cast-role').val(index < 2 ? 'lead' : 'supporting');
    refreshCastMemberIndexes();
  }

  function updateCastMemberHeadings(){
    var leadCount = 0;
    var supportingCount = 0;

    $('#cmmt-principal-cast-list .cmmt-cast-member-card').each(function(index){
      var card = $(this);
      var role = card.find('.cmmt-cast-role').val() || (index < 2 ? 'lead' : 'supporting');
      var label;

      if (role === 'lead') {
        leadCount += 1;
        label = 'Lead Character ' + leadCount;
      } else {
        supportingCount += 1;
        label = 'Supporting Character ' + supportingCount;
      }

      card.find('.cmmt-cast-member-title').text(label);
    });
  }

  function refreshCastMemberIndexes(){
    var list = $('#cmmt-principal-cast-list');

    list.find('.cmmt-cast-member-card').each(function(index){
      var card = $(this);
      card.attr('data-cast-index', index);
      card.find('.cmmt-cast-name').attr('name', 'cast_members[' + index + '][name]');
      card.find('.cmmt-cast-role').attr('name', 'cast_members[' + index + '][role]');
      card.find('.cmmt-cast-image').attr('name', 'cast_members[' + index + '][image]');
      card.find('.cmmt-cast-instruction').attr('name', 'cast_members[' + index + '][instruction]');
    });

    updateCastMemberHeadings();
    $('#cmmt-add-cast-member').prop('disabled', list.find('.cmmt-cast-member-card').length >= castMaxCount());
  }

  function initCastMembers(){
    var list = $('#cmmt-principal-cast-list');
    if (!list.length || list.children().length) return;

    var initial = parseInt(list.data('initial-count') || 6, 10);
    var max = castMaxCount();
    initial = Math.max(1, Math.min(max, initial));

    for (var i = 0; i < initial; i++) {
      addCastMember(i);
    }

    refreshCastMemberIndexes();
  }

  function collectCastMembers(){
    var members = [];

    $('#cmmt-principal-cast-list .cmmt-cast-member-card').each(function(index){
      var card = $(this);
      members.push({
        name: card.find('.cmmt-cast-name').val() || '',
        role: card.find('.cmmt-cast-role').val() || (index < 2 ? 'lead' : 'supporting'),
        instruction: card.find('.cmmt-cast-instruction').val() || '',
        has_image: !!(card.find('.cmmt-cast-image')[0] && card.find('.cmmt-cast-image')[0].files && card.find('.cmmt-cast-image')[0].files.length)
      });
    });

    return members;
  }

  function syncLegacyCastFields(){
    var members = collectCastMembers();
    $('#cmmt-cast-actor-1-instruction').val((members[0] && members[0].instruction) || '');
    $('#cmmt-cast-actor-2-instruction').val((members[1] && members[1].instruction) || '');
    $('#cmmt-cast-actor-3-instruction').val((members[2] && members[2].instruction) || '');
  }

  function validateCastFiles(){
    var allowed = ['jpg', 'jpeg', 'png', 'webp'];
    var invalid = [];

    $('#cmmt-principal-cast-list .cmmt-cast-image').each(function(index){
      var file = this.files && this.files[0] ? this.files[0] : null;
      if (!file) return;

      var name = (file.name || '').toLowerCase();
      var ext = name.indexOf('.') >= 0 ? name.split('.').pop() : '';
      if (allowed.indexOf(ext) === -1) {
        invalid.push('Cast Member ' + (index + 1) + ': ' + file.name);
      }
    });

    if (invalid.length) {
      setStatus('Unsupported actor reference file type. Upload JPG, JPEG, PNG, or WEBP only. Unsupported: ' + invalid.join(', '), 'is-error');
      return false;
    }

    return true;
  }

  function collectPosterPayload(){
    var castMembers = collectCastMembers();
    return {
      request_email: $('#cmmt-poster-form [name="request_email"]').val(),
      title: $('#cmmt-poster-form [name="title"]').val(),
      tagline: $('#cmmt-poster-form [name="tagline"]').val(),
      genre: $('#cmmt-poster-form [name="genre"]').val(),
      mood: $('#cmmt-poster-form [name="mood"]').val(),
      style_preset: $('#cmmt-poster-form [name="style_preset"]').val(),
      poster_description: $('#cmmt-poster-form [name="poster_description"]').val(),
      title_font_style: $('#cmmt-poster-form [name="title_font_style"]').val() || 'cinematic_bold',
      tagline_font_style: $('#cmmt-poster-form [name="tagline_font_style"]').val() || 'clean_sans',
      title_position: $('#cmmt-poster-form [name="title_position"]').val() || 'bottom_cinematic',
      preserve_identity: $('#cmmt-poster-form [name="preserve_identity"]').is(':checked') ? 1 : 0,
      cast_members: castMembers,

      cast_actor_1_instruction: (castMembers[0] && castMembers[0].instruction) || '',
      cast_actor_2_instruction: (castMembers[1] && castMembers[1].instruction) || '',
      cast_actor_3_instruction: (castMembers[2] && castMembers[2].instruction) || '',
    };
  }

function collectPosterPayloadFormData(actionName){
    var form = document.getElementById('cmmt-poster-form');
    var fd = new FormData(form);
    var castMembers = collectCastMembers();

    syncLegacyCastFields();
    fd.set('action', actionName);
    fd.set('nonce', cmsgData.nonce);
    fd.set('cast_members', JSON.stringify(castMembers));

    fd.set('cast_actor_1_instruction', (castMembers[0] && castMembers[0].instruction) || '');
    fd.set('cast_actor_2_instruction', (castMembers[1] && castMembers[1].instruction) || '');
    fd.set('cast_actor_3_instruction', (castMembers[2] && castMembers[2].instruction) || '');

    return fd;
}

  function fetchJson(formData){
    return fetch(cmsgData.ajaxUrl, { method: 'POST', body: formData })
      .then(function(r){
        return r.text().then(function(text){
          try { return JSON.parse(text); }
          catch(e){ throw new Error('Server returned non-JSON response: ' + text.substring(0, 220)); }
        });
      });
  }

  function postEncoded(data){
    return fetch(cmsgData.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: new URLSearchParams(data)
    }).then(function(r){
      return r.text().then(function(text){
        try { return JSON.parse(text); }
        catch(e){ throw new Error('Server returned non-JSON response: ' + text.substring(0, 220)); }
      });
    });
  }

  function renderPreviewGrid(previews){
    var grid = $('#cmmt-poster-preview-grid');
    grid.empty();
    previewUrls = previews || [];
    selectedConcept = 0;

    previewUrls.forEach(function(url, index){
      var activeClass = index === 0 ? ' active' : '';
      grid.append(
        '<div class="cmmt-poster-preview selectable' + activeClass + '" data-index="' + index + '">' +
          '<div class="cmmt-poster-preview__frame"><img src="' + url + '" alt="Poster preview ' + (index + 1) + '"></div>' +
          '<button type="button" class="cmmt-select-label">View / Select Concept ' + (index + 1) + '</button>' +
        '</div>'
      );
    });

       $('#cmmt-cast-builder-panel').show();
  }

  $(document).off('click.cmmtPosterSelect', '.cmmt-poster-preview')
    .on('click.cmmtPosterSelect', '.cmmt-poster-preview', function(){
      modalConceptIndex = parseInt($(this).data('index') || 0, 10);
      var img = $(this).find('img').attr('src');
      $('#cmmt-poster-modal-img').attr('src', img);
      $('#cmmt-poster-modal').css('display', 'flex');
    });

  $('#cmmt-poster-modal-close, .cmmt-poster-modal__backdrop').off('click.cmmtPosterModal').on('click.cmmtPosterModal', function(){
    $('#cmmt-poster-modal').hide();
  });

  $('#cmmt-poster-modal-select').off('click.cmmtPosterModalSelect').on('click.cmmtPosterModalSelect', function(){
    selectedConcept = modalConceptIndex;
    $('.cmmt-poster-preview').removeClass('active');
    $('.cmmt-poster-preview[data-index="' + selectedConcept + '"]').addClass('active');
    $('#cmmt-poster-modal').hide();
    setStep(3);
setStatus('This image is now selected for final poster creation. Complete PayPal to unlock final poster files.', 'is-success');
  });

  initCastMembers();

  $('#cmmt-add-cast-member').off('click.cmmtPosterCast').on('click.cmmtPosterCast', function(){
    var count = $('#cmmt-principal-cast-list .cmmt-cast-member-card').length;
    if (count >= castMaxCount()) return;
    addCastMember(count);
  });

  $(document).off('click.cmmtPosterCastRemove', '.cmmt-remove-cast-member').on('click.cmmtPosterCastRemove', '.cmmt-remove-cast-member', function(){
    $(this).closest('.cmmt-cast-member-card').remove();
    if (!$('#cmmt-principal-cast-list .cmmt-cast-member-card').length) {
      addCastMember(0);
      return;
    }
    refreshCastMemberIndexes();
  });

  $(document).off('change.cmmtPosterCastRole', '.cmmt-cast-role').on('change.cmmtPosterCastRole', '.cmmt-cast-role', updateCastMemberHeadings);

/* FACE MAPPING BUTTON */

  $('#cmmt-generate-previews').off('click.cmmtPoster').on('click.cmmtPoster', function(e){
    e.preventDefault();
    e.stopPropagation();

    var payload = collectPosterPayload();
    if (!payload.request_email || !payload.title) {
      setStatus('Email and movie title are required.', 'is-error');
      return;
    }

    if (!validateCastFiles()) {
      return;
    }

    posterDraftId = null;
    posterPaymentToken = '';
    posterJobId = null;
    previewUrls = [];
    $('#cmmt-poster-preview-grid').empty();
    $('#cmmt-poster-downloads').empty();
    setStep(1);
    startPosterProgress();
    setStatus('Creating poster draft and generating watermarked previews...', 'is-working');

    var draftFd = collectPosterPayloadFormData('cmsg_create_poster_draft');

    fetchJson(draftFd)
      .then(function(resp){
        if (!resp.success) throw new Error((resp.data && resp.data.message) || 'Unable to create poster draft.');
        posterDraftId = resp.data.draft_id;
        setPosterProgress(35, 'Preparing uploaded references...');

        var previewFd = collectPosterPayloadFormData('cmsg_generate_poster_previews');
        previewFd.set('draft_id', posterDraftId);
        setPosterProgress(65, 'Rendering AI poster concepts...');
        return fetchJson(previewFd);
      })
      .then(function(prev){
        if (!prev.success) throw new Error((prev.data && prev.data.message) || 'Unable to generate previews.');
        var previews = prev.data.previews || [];
        if (prev.data.prompt_preview) {
          $('#cmmt-prompt-preview-card').show();
          $('#cmmt-prompt-preview').text(prev.data.prompt_preview);
        }
        if (!previews.length) throw new Error('Preview generation failed. Please try again shortly or contact support.');
        renderPreviewGrid(previews);
        finishPosterProgress();
        setStep(2);
        setStatus('Watermarked previews are ready. Click a poster to expand and select your preferred concept.', 'is-success');
      })
      .catch(function(err){
        setPosterProgress(0, 'Preview failed.');
        setTimeout(function(){ $('#cmmt-poster-progress-wrap').fadeOut(300); }, 800);
        setStatus('Preview generation error: ' + err.message, 'is-error');
      });
  });

  function ensurePosterDraft(){
    if (posterDraftId) return Promise.resolve(posterDraftId);
    var payload = collectPosterPayload();
    if (!payload.request_email || !payload.title) {
      setStatus('Generate previews first so the poster draft exists.', 'is-error');
      return Promise.reject(new Error('Poster draft missing.'));
    }
    var fd = collectPosterPayloadFormData('cmsg_create_poster_draft');
    return fetchJson(fd).then(function(resp){
      if (!resp.success || !resp.data || !resp.data.draft_id) throw new Error((resp.data && resp.data.message) || 'Unable to create poster draft.');
      posterDraftId = resp.data.draft_id;
      return posterDraftId;
    });
  }

  function createPosterOrder(draftId){
    return postEncoded({ action: 'cmsg_create_poster_paypal_order', nonce: cmsgData.nonce, draft_id: draftId })
      .then(function(resp){
        if (!resp.success || !resp.data || !resp.data.orderID) throw new Error((resp.data && resp.data.message) || 'PayPal order failed.');
        return resp.data.orderID;
      });
  }

  function capturePosterOrder(draftId, orderId){
    return postEncoded({ action: 'cmsg_capture_poster_paypal_order', nonce: cmsgData.nonce, draft_id: draftId, order_id: orderId })
      .then(function(resp){
        if (!resp.success) throw new Error((resp.data && resp.data.message) || 'PayPal capture failed.');
        return resp.data;
      });
  }

  if (window.paypal && cmsgData.paypal && cmsgData.paypal.enabled && document.getElementById('cmmt-paypal-poster')) {
    $('#cmmt-paypal-poster').empty();
    window.paypal.Buttons({
      createOrder: function(){
        setStep(3);
        return ensurePosterDraft().then(function(draftId){ return createPosterOrder(draftId); });
      },
      onApprove: function(data){
        return capturePosterOrder(posterDraftId, data.orderID).then(function(resp){
          posterPaymentToken = resp.payment_token || '';
          setStep(4);
          setStatus('Payment confirmed. Click “Create Final Posters & Download” to generate your selected poster in two professional formats: Vertical (900×1285) and Banner (895×504). Download links will appear below, and a delivery email will be sent automatically.', 'is-success');
        });
      },
      onError: function(err){ setStatus('PayPal error: ' + err, 'is-error'); }
    }).render('#cmmt-paypal-poster');
  }

  $('#cmmt-poster-form').off('submit.cmmtPoster').on('submit.cmmtPoster', function(e){
    e.preventDefault();
    e.stopPropagation();

    if (!posterDraftId || !posterPaymentToken) {
      setStatus('Complete the PayPal step first.', 'is-error');
      return;
    }

    var activePreviewUrl = $('.cmmt-poster-preview.active img').attr('src') || '';
    var selectedPreviewUrl = activePreviewUrl || previewUrls[selectedConcept] || '';
    if (!selectedPreviewUrl) {
      setStatus('Please select a poster concept before creating final files.', 'is-error');
      return;
    }

    if (!validateCastFiles()) {
      return;
    }

    setStep(4);
    resetFinalizeProgress();
    $('#cmmt-finalize-poster').prop('disabled', true).text('Creating Final Posters...');
    $('#cmmt-poster-downloads').empty();

    setFinalizeProgress(8, 'Starting final poster creation...');
    setStatus('Creating final clean poster files from your selected concept. Please keep this page open.', 'is-working');

    var finalFd = collectPosterPayloadFormData('cmsg_finalize_paid_poster_draft');
    finalFd.set('draft_id', posterDraftId);
    finalFd.set('payment_token', posterPaymentToken);
    finalFd.set('selected_concept', selectedConcept || 0);
    finalFd.set('selected_preview_url', selectedPreviewUrl);

    setTimeout(function(){ setFinalizeProgress(25, 'Preparing selected concept and reference assets...'); }, 300);
    setTimeout(function(){ setFinalizeProgress(45, 'Creating vertical poster with safe title margins...'); }, 1200);
    setTimeout(function(){ setFinalizeProgress(65, 'Creating square poster version...'); }, 2200);
    setTimeout(function(){ setFinalizeProgress(82, 'Creating landscape/banner poster version...'); }, 3200);

    fetchJson(finalFd)
      .then(function(resp){
        if (!resp.success) throw new Error((resp.data && resp.data.message) || 'Unable to finalize poster job.');
        posterJobId = resp.data.job_id;
        setFinalizeProgress(90, 'Final files created. Preparing secure download links and email...');
        return postEncoded({ action: 'cmsg_issue_poster_download_grant', nonce: cmsgData.nonce, job_id: posterJobId });
      })
      .then(function(dl){
        if (!dl || !dl.success || !dl.data) throw new Error((dl.data && dl.data.message) || 'Download generation failed.');
        var urls = dl.data.downloads || {};
        var html = '<div class="cmmt-download-set">';
        if (urls.vertical) html += '<a class="cmsg-download" href="' + urls.vertical + '" target="_blank" rel="noopener"><strong>Download Vertical Poster</strong><span>900 × 1285</span></a>';
        if (urls.square) html += '<a class="cmsg-download" href="' + urls.square + '" target="_blank" rel="noopener"><strong>Download Square Poster</strong><span>1080 × 1080</span></a>';
        if (urls.banner) html += '<a class="cmsg-download" href="' + urls.banner + '" target="_blank" rel="noopener"><strong>Download Landscape/Banner Poster</strong><span>896 × 504</span></a>';
        html += '</div>';
        $('#cmmt-poster-downloads').html(html);
        setFinalizeProgress(100, 'Done. Downloads are ready below and an email has been sent with the completed poster files.');
        setStep(5);
        setStatus('Your clean poster files are ready. Use the download buttons below. A copy has also been emailed to you.', 'is-success');
        $('#cmmt-finalize-poster').prop('disabled', false).text('Create Final Posters & Download');
        document.getElementById('cmmt-poster-download-card').scrollIntoView({behavior:'smooth', block:'start'});
      })
      .catch(function(err){
        setFinalizeProgress(0, 'Final poster creation failed. Please try again or contact support.');
        $('#cmmt-finalize-poster').prop('disabled', false).text('Create Final Posters & Download');
        setStatus('Unable to finalize poster job: ' + err.message, 'is-error');
      });
  });
});
