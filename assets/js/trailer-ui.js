jQuery(function($){
  let trailerDraftId = null;
  let trailerPaymentToken = '';
  let trailerJobId = null;

  function setStatus(html, state){
    const $el = $('#cmmt-trailer-status');
    if(window.CMMT && window.CMMT.setStatus){
      window.CMMT.setStatus($el, html, state);
    } else {
      $el.removeClass('is-error is-success is-working is-info').addClass(state || '').html(html);
    }
  }

  function field(name){ return $('#cmmt-trailer-form [name="' + name + '"]').val() || ''; }

  function payload(){
    return {
      request_email: field('request_email'),
      title: field('title'),
      trailer_type: field('trailer_type'),
      runtime_target: field('runtime_target'),
      genre: field('genre'),
      tone: field('tone'),
      target_audience: field('target_audience'),
      music_style: field('music_style'),
      cta: field('cta'),
      description: field('description'),
      required_elements: field('required_elements'),
      text_cards: field('text_cards'),
      asset_links: field('asset_links')
    };
  }

  function postEncoded(data){
    data.nonce = cmsgData.nonce;
    return fetch(cmsgData.ajaxUrl, {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body:new URLSearchParams(data)
    }).then(function(r){
      return r.text().then(function(text){
        try { return JSON.parse(text); }
        catch(e){ throw new Error('Server returned non-JSON response: ' + text.substring(0, 160)); }
      });
    });
  }

  function createDraft(){
    const p = payload();
    p.action = 'cmsg_create_trailer_draft';
    return postEncoded(p).then(function(resp){
      if(!resp.success) throw new Error((resp.data && resp.data.message) || 'Unable to create trailer draft.');
      return resp.data;
    });
  }

  function createOrder(draftId){
    return postEncoded({action:'cmsg_create_trailer_paypal_order', draft_id:draftId}).then(function(resp){
      if(!resp.success || !resp.data || !resp.data.orderID) throw new Error((resp.data && resp.data.message) || 'PayPal order failed.');
      return resp.data.orderID;
    });
  }

  function captureOrder(draftId, orderId){
    return postEncoded({action:'cmsg_capture_trailer_paypal_order', draft_id:draftId, order_id:orderId}).then(function(resp){
      if(!resp.success) throw new Error((resp.data && resp.data.message) || 'PayPal capture failed.');
      return resp.data;
    });
  }

  function finalizeTrailer(){
    const p = payload();
    p.action = 'cmsg_finalize_paid_trailer_draft';
    p.draft_id = trailerDraftId;
    p.payment_token = trailerPaymentToken;
    setStatus('Finalizing paid trailer brief package...', 'is-working');
    return postEncoded(p).then(function(resp){
      if(!resp.success) throw new Error((resp.data && resp.data.message) || 'Unable to finalize trailer request.');
      trailerJobId = resp.data.job_id;
      renderDownloads(resp.data.manifest || []);
      setStatus((resp.data.message || 'Trailer brief package created.') + '<br><strong>Trailer Job ID:</strong> ' + trailerJobId, 'is-success');
      return resp.data;
    });
  }

  function renderDownloads(manifest){
    const $box = $('#cmmt-trailer-downloads');
    if(!manifest || !manifest.length){ $box.empty(); return; }
    let html = '<div class="cmmt-trailer-download-card"><h4>Trailer Studio Deliverables</h4><p>Download the structured brief package generated from your description and required elements.</p><div class="cmmt-trailer-download-list">';
    manifest.forEach(function(item){
      if(item.url){
        html += '<a class="cmsg-btn cmsg-btn--secondary" target="_blank" rel="noopener" href="' + item.url + '">' + (item.label || 'Download') + '</a>';
      }
    });
    html += '</div></div>';
    $box.html(html);
  }

  function updatePreview(){
    const p = payload();
    const required = (p.required_elements || '').split(/\r?\n/).filter(Boolean).slice(0,5);
    const cards = (p.text_cards || '').split(/\r?\n/).filter(Boolean).slice(0,5);
    let html = '<strong>Trailer Brief Preview</strong>';
html += '<p style="display:block !important; visibility:visible !important; opacity:1 !important; color:#111827 !important; -webkit-text-fill-color:#111827 !important; font-size:15px !important; font-weight:600 !important; line-height:1.5 !important; margin:0 0 8px 0 !important;"><b style="color:#111827 !important; -webkit-text-fill-color:#111827 !important; font-weight:900 !important;">' + (p.title || 'Untitled project') + '</b> · ' + (p.trailer_type || 'Trailer') + ' · ' + (p.runtime_target || '60_sec') + '</p>';

html += '<p style="display:block !important; visibility:visible !important; opacity:1 !important; color:#111827 !important; -webkit-text-fill-color:#111827 !important; font-size:15px !important; font-weight:600 !important; line-height:1.5 !important; margin:0 0 8px 0 !important;"><b style="color:#111827 !important; -webkit-text-fill-color:#111827 !important; font-weight:900 !important;">Genre/Tone:</b> ' + (p.genre || 'Not set') + ' / ' + (p.tone || 'Not set') + '</p>';
    if(required.length){ html += '<p><b>Required elements:</b> ' + required.join(' · ') + '</p>'; }
    if(cards.length){ html += '<p><b>Text cards:</b> ' + cards.join(' / ') + '</p>'; }
    $('#cmmt-trailer-brief-preview').html(html);
  }

  $('#cmmt-trailer-form').on('input change', 'input, textarea, select', updatePreview);
  updatePreview();

  if (window.paypal && cmsgData && cmsgData.paypal && cmsgData.paypal.enabled && document.getElementById('cmmt-paypal-trailer')) {
    window.paypal.Buttons({
      createOrder:function(){
        setStatus('Creating trailer draft and PayPal order...', 'is-working');
        return createDraft().then(function(d){
          trailerDraftId = d.draft_id;
          return createOrder(trailerDraftId);
        });
      },
      onApprove:function(data){
        return captureOrder(trailerDraftId, data.orderID).then(function(resp){
          trailerPaymentToken = resp.payment_token || '';
          setStatus('Payment confirmed. Click Finalize Paid Trailer Brief to generate the structured package.', 'is-success');
        });
      },
      onError:function(err){ setStatus('PayPal error: ' + err, 'is-error'); }
    }).render('#cmmt-paypal-trailer');
  }

  $('#cmmt-trailer-form').on('submit', function(e){
    e.preventDefault();
    if(!trailerDraftId || !trailerPaymentToken){
      setStatus('Complete the PayPal step first.', 'is-error');
      return;
    }
    finalizeTrailer().catch(function(err){ setStatus(err.message || 'Unable to finalize trailer request.', 'is-error'); });
  });
});
