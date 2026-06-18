/* ===== Crossmarket Option 2 Secure Large Upload UX - Clean Replacement ===== */
(function($){
"use strict";

$(function(){

 // if (!document.getElementById("cmmt-large-upload-form")) return;

  // GLOBAL STATE
  var state = window.cmmtLargeState = {
    draftId: null,
    paymentToken: "",
    objectKey: "",
    jobId: null,
    uploadReady: false,
    isUploading: false,
    isPaid: false,
    isProcessing: false,
    isCompleted: false
  };

  // HELPERS
  function ajaxUrl() {
    if (typeof cmsgData !== "undefined" && cmsgData.ajaxUrl) return cmsgData.ajaxUrl;
    if (typeof ajaxurl !== "undefined") return ajaxurl;
    return "/wp-admin/admin-ajax.php";
  }

  function nonce() {
    return (typeof cmsgData !== "undefined" && cmsgData.nonce) ? cmsgData.nonce : "";
  }

  function setLargeProgress(percent) {
    percent = Math.max(0, Math.min(100, parseInt(percent || 0, 10)));
    $("#cmmt-large-progress-bar").css("width", percent + "%");
    $("#cmmt-large-progress-percent").text(percent + "%");
  }

  function setLargeStatusText(message, stateClass) {
    var box = $("#cmmt-large-upload-status");
    box.removeClass("is-working is-success is-error");
    if (stateClass) box.addClass(stateClass);
    box.text(message);
  }

  function enablePayPal() {
    $("#cmmt-large-paywall")
      .removeClass("is-disabled")
      .css({"pointer-events":"auto","opacity":"1"});

    $("#cmmt-paypal-large-upload")
      .css({"pointer-events":"auto","opacity":"1"});
  }

  function disablePayPal() {
    $("#cmmt-large-paywall")
      .addClass("is-disabled")
      .css({"pointer-events":"none","opacity":".45"});
  }

  function lockPrepareButton() {
    $("#cmmt-prepare-large-upload")
      .prop("disabled", true)
      .addClass("is-disabled")
      .text("File Uploaded — Continue to PayPal");
  }

  function unlockPrepareButton() {
    $("#cmmt-prepare-large-upload")
      .prop("disabled", false)
      .removeClass("is-disabled")
      .text("Click to Prepare Upload");
  }

  function getLargeFile() {
    var input = $("#cmmt-large-video-file")[0];
    return input && input.files ? input.files[0] : null;
  }

  function postEncoded(data) {
    return fetch(ajaxUrl(), {
      method: "POST",
      headers: {"Content-Type": "application/x-www-form-urlencoded; charset=UTF-8"},
      body: new URLSearchParams(data)
    }).then(function(r){ return r.json(); });
  }

  function postFormData(formData) {
    return fetch(ajaxUrl(), {
      method: "POST",
      body: formData
    }).then(function(r){ return r.json(); });
  }


// ===== ESTIMATE CALCULATION =====
function updateEstimate() {

  var runtime = parseFloat($("#cmmt-large-runtime").val() || 0);
  var pricePerMinute = 0;
  var currency = "$";

  if (typeof cmsgData !== "undefined" && cmsgData.pricing) {
    pricePerMinute = parseFloat(cmsgData.pricing.subtitlePerMinute || 0);
    currency = cmsgData.pricing.currency || "$";
  }

  // before upload/runtime verification
  if (!window.largeRuntimeVerified) {

    if (runtime > 0 && pricePerMinute > 0) {

      $("#cmmt-large-estimate").text(
        "Price will be displayed once upload is complete."
      );

    } else {

      $("#cmmt-large-estimate").text(
        "Final runtime and billing will be calculated after upload."
      );
    }

    return;
  }

  // after backend verification
  if (window.largeVerifiedRuntime > 0 && pricePerMinute > 0) {

    $("#cmmt-large-estimate").text(
      currency + (window.largeVerifiedRuntime * pricePerMinute).toFixed(2)
    );

  } else {

    $("#cmmt-large-estimate").text(currency + "0.00");
  }
}


  // CREATE DRAFT
  function createLargeDraft(file) {
    var fd = new FormData();

    fd.append("action", "cmsg_create_draft");
    fd.append("nonce", nonce());
    fd.append("source_type", "gcs_upload");
fd.append("caption_mode", $("#cmmt-large-upload-form [name='caption_mode']").val() || "subtitle");

    fd.append("request_email", $('#cmmt-large-upload-form [name="request_email"]').val() || "");
    fd.append("runtime_minutes", $('#cmmt-large-upload-form [name="runtime_minutes"]').val() || "");
fd.append("language_code", $('#cmmt-large-upload-form [name="language_code"]').val() || "auto");

fd.append("source_language", $('#cmmt-large-upload-form [name="source_language"]').val() || "auto");
fd.append("output_language", $('#cmmt-large-upload-form [name="output_language"]').val() || "same");
fd.append("translation_mode", $('#cmmt-large-upload-form [name="translation_mode"]').val() || "none");


fd.append("model_size", $('#cmmt-large-upload-form [name="model_size"]').val() || "small");

    fd.append("original_filename", file ? file.name : "upload.bin");
    fd.append("file_size", file ? file.size : 0);

    return postFormData(fd).then(function(resp){
      if (!resp.success) {
        throw new Error((resp.data && resp.data.message) || "Unable to create upload draft.");
      }
      return resp.data;
    });
  }

  // GET SIGNED URL
  function getSignedUpload(draftId, file) {
    return postEncoded({
      action: "cmsg_get_gcs_signed_upload",
      nonce: nonce(),
      draft_id: draftId,
      filename: file.name,
      content_type: file.type || "application/octet-stream"
    }).then(function(resp){
      if (!resp.success || !resp.data || !resp.data.upload_url || !resp.data.object_key) {
        throw new Error((resp.data && resp.data.message) || "Unable to prepare secure cloud upload.");
      }
      return resp.data;
    });
  }

  // UPLOAD TO GCS (REAL PROGRESS BAR)
  function uploadToGCS(uploadUrl, file) {
    return new Promise(function(resolve, reject){

      var xhr = new XMLHttpRequest();

      xhr.open("PUT", uploadUrl, true);
      xhr.setRequestHeader("Content-Type", file.type || "application/octet-stream");

      xhr.upload.onprogress = function(e) {
        if (e.lengthComputable) {
          var percent = Math.round((e.loaded / e.total) * 100);
          setLargeProgress(percent);
          setLargeStatusText("Uploading file to secure cloud storage... " + percent + "%", "is-working");
        }
      };

      xhr.onload = function() {
        if (xhr.status >= 200 && xhr.status < 300) {
          setLargeProgress(100);
          resolve();
        } else {
          reject(new Error("Cloud upload failed. Status: " + xhr.status));
        }
      };

      xhr.onerror = function() {
        reject(new Error("Network error during cloud upload."));
      };

      xhr.send(file);
    });
  }

  // CONFIRM UPLOAD
  function confirmGCSUpload(draftId, objectKey) {
    return postEncoded({
      action: "cmsg_confirm_gcs_upload",
      nonce: nonce(),
      draft_id: draftId,
      object_key: objectKey
    }).then(function(resp){
      if (!resp.success) {
        throw new Error((resp.data && resp.data.message) || "Upload confirmation failed.");
      }
window.largeRuntimeVerified = true;
window.largeVerifiedRuntime = parseFloat(resp.data.runtime_minutes || 0);

$("#cmmt-large-runtime")
  .val(window.largeVerifiedRuntime)
  .prop("readonly", true);

updateEstimate();

setLargeStatusText(
  "Upload confirmed. Runtime verified at " +
  window.largeVerifiedRuntime +
  " minutes. Final billing has been calculated. You may now complete PayPal payment.",
  "is-success"
);
      return resp.data;
    });
  }
  // CREATE PAYPAL ORDER
  function createPayPalOrder(draftId) {
    return postEncoded({
      action: "cmsg_create_paypal_order",
      nonce: nonce(),
      draft_id: draftId
    }).then(function(resp){
      if (!resp.success || !resp.data || !resp.data.orderID) {
        throw new Error((resp.data && resp.data.message) || "Unable to create PayPal order.");
      }
      return resp.data.orderID;
    });
  }

  // CAPTURE PAYMENT
  function capturePayPalOrder(draftId, orderId) {
    return postEncoded({
      action: "cmsg_capture_paypal_order",
      nonce: nonce(),
      draft_id: draftId,
      order_id: orderId,
      kind: "subtitle"
    }).then(function(resp){
      console.log("PAYPAL AJAX RESPONSE:", resp);

var orderId = (resp.data && (resp.data.orderID || resp.data.order_id || resp.data.payment_token)) || "";

if (!resp.success || !orderId) {
  console.error("INVALID PAYPAL RESPONSE", resp);
  alert("PayPal AJAX failed:\n\n" + JSON.stringify(resp, null, 2));
  throw new Error("PayPal order creation failed.");
}
      return resp.data;
    });
  }

  // FINALIZE JOB AFTER PAYMENT
  function finalizePaidDraft() {
    var fd = new FormData();

    fd.append("action", "cmsg_finalize_paid_draft");
    fd.append("nonce", nonce());
    fd.append("draft_id", state.draftId);
    fd.append("payment_token", state.paymentToken);

    return postFormData(fd).then(function(resp){
      if (!resp.success) {
        throw new Error((resp.data && resp.data.message) || "Finalize failed.");
      }
      return resp.data;
    });
  }

  // JOB POLLING
  function pollJob(jobId) {

    state.jobId = jobId;
    state.isProcessing = true;

    var progress = 10;
    setLargeProgress(progress);

    setLargeStatusText(
      "Payment received. Your file is being processed. A download button will appear below once processing is complete. You will also receive an email with the download link once email delivery is enabled.",
      "is-success"
    );

var timer = setInterval(function(){

  progress = Math.min(progress + 5, 95);
  setLargeProgress(progress);

  if (progress >= 95) {
    setLargeStatusText(
      "Final closed-caption cue scan is running. Long feature films may remain here while audio cues are added to the VTT file.",
      "is-info"
    );
  }

      postEncoded({
        action: "cmsg_job_status",
        nonce: nonce(),
        job_id: jobId
      }).then(function(resp){

        if (!resp.success || !resp.data) return;

        if (resp.data.status === "completed") {

          clearInterval(timer);
          state.isProcessing = false;
          state.isCompleted = true;

          setLargeProgress(100);
          setLargeStatusText("Job completed, Your subtitle file is ready. Click the download button below or check your email for a download link.", "is-success");

          postEncoded({
  action: "cmsg_issue_download_grant",
  nonce: nonce(),
  job_id: jobId
}).then(function(grantResp){

if (grantResp.success && grantResp.data) {

  var srtUrl = grantResp.data.srt_download_url || grantResp.data.download_url || "";
  var vttUrl = grantResp.data.vtt_download_url || "";

  $("#cmmt-large-download-wrap")
    .css({
      display: "block",
      visibility: "visible",
      opacity: "1",
      marginTop: "16px"
    })
    .show();

  if (srtUrl) {
    $("#cmmt-large-download-link")
      .attr("href", srtUrl)
      .attr("download", "")
      .removeAttr("target")
      .attr("rel", "noopener")
      .css({
        display: "inline-flex",
        visibility: "visible",
        opacity: "1",
        pointerEvents: "auto"
      })
      .text("Download SRT Subtitle File");
  }

  if (vttUrl) {

    var vttBtn = $("#cmmt-large-vtt-download-link");

    if (!vttBtn.length) {

      $("#cmmt-large-download-link").after(
'<a href="#" id="cmmt-large-vtt-download-link" class="cmsg-btn cmsg-btn--primary" style="margin-left:18px; display:inline-flex; align-items:center; justify-content:center; visibility:visible; opacity:1; pointer-events:auto;">'     
 );

      vttBtn = $("#cmmt-large-vtt-download-link");
    }

    vttBtn
      .attr("href", vttUrl)
      .attr("download", "")
      .removeAttr("target")
      .attr("rel", "noopener")
      .css({
        display: "inline-flex",
        visibility: "visible",
        opacity: "1",
        pointerEvents: "auto"
      })
      .text("Download VTT Closed Caption File");
  }

} else {

    setLargeStatusText("Job completed, but download link could not be created. Please contact support.", "is-error");
  }

});
        }

        if (resp.data.status === "failed") {
          clearInterval(timer);
          state.isProcessing = false;
          setLargeStatusText(resp.data.message || "Job failed. Please contact support.", "is-error");
        }

      });

    }, 5000);
  }
  // FILE SELECT
  $("#cmmt-large-video-file").off("change").on("change", function(){

    if (state.uploadReady) {
      setLargeStatusText(
        "A file is already uploaded. Continue to PayPal or refresh the page to start over.",
        "is-success"
      );
      lockPrepareButton();
      enablePayPal();
      return;
    }

    setLargeProgress(0);
    setLargeStatusText("File selected. Click to Prepare Upload.", "is-working");
  });

  // PREPARE UPLOAD BUTTON
  $("#cmmt-prepare-large-upload").off("click").on("click", function(e){

    e.preventDefault();

    // 🔒 PREVENT RE-UPLOAD
    if (state.uploadReady) {
      setLargeProgress(100);
      setLargeStatusText("File already uploaded. Please click PayPal to continue.", "is-success");
      lockPrepareButton();
      enablePayPal();
      return;
    }

    if (state.isUploading) {
      setLargeStatusText("Upload already in progress. Please wait.", "is-working");
      return;
    }

    var file = getLargeFile();

    if (!file) {
      setLargeStatusText("Please choose a large video file first.", "is-error");
      return;
    }

    state.isUploading = true;

    // RESET STATE
    state.uploadReady = false;
    state.draftId = null;
    state.paymentToken = "";
    state.objectKey = "";
    state.jobId = null;
    state.isPaid = false;
    state.isProcessing = false;
    state.isCompleted = false;

    setLargeProgress(0);
    disablePayPal();
    unlockPrepareButton();
    $("#cmmt-large-download-wrap").hide();

    setLargeStatusText("Preparing secure cloud upload...", "is-working");

    createLargeDraft(file)
      .then(function(draft){

        state.draftId = draft.draft_id;

        setLargeStatusText("Secure upload link created. Starting upload...", "is-working");

        return getSignedUpload(state.draftId, file);
      })
      .then(function(policy){

        state.objectKey = policy.object_key;

        return uploadToGCS(policy.upload_url, file);
      })
      .then(function(){

        setLargeStatusText("Cloud upload complete. Confirming file...", "is-working");

        return confirmGCSUpload(state.draftId, state.objectKey);
      })
      .then(function(){

        state.isUploading = false;
        state.uploadReady = true;

        setLargeProgress(100);

        setLargeStatusText(
          "File uploaded, click PayPal button to make payment before job commences.",
          "is-success");
       //  updateEstimate();

        lockPrepareButton();
        enablePayPal();
        renderPayPal();
      })
      .catch(function(err){

        state.isUploading = false;

        setLargeStatusText(err.message || String(err), "is-error");

        unlockPrepareButton();
        disablePayPal();
      });
  });

$("#cmmt-large-runtime").on("input change", function(){
  updateEstimate();
});

  // PAYPAL BUTTON
  function renderPayPal(){

    if (!window.paypal || !document.getElementById("cmmt-paypal-large-upload")) return;

var container = $("#cmmt-paypal-large-upload");

container
  .empty()
  .removeData("rendered")
  .css({
    display: "block",
    visibility: "visible",
    opacity: "1",
    minHeight: "45px",
    pointerEvents: "auto"
  });

container.data("rendered", true);
    window.paypal.Buttons({

      createOrder: function(){

        if (!state.uploadReady || !state.draftId) {
          setLargeStatusText("Upload the file first before paying.", "is-error");
          throw new Error("Upload not ready");
        }

        return createPayPalOrder(state.draftId);
      },

      onApprove: function(data){

        return capturePayPalOrder(state.draftId, data.orderID)

          .then(function(resp){

            state.isPaid = true;
            state.paymentToken = resp.payment_token || "";

            setLargeStatusText(
              "Payment received. Processing started...",
              "is-success"
            );

            setLargeProgress(10);

            return finalizePaidDraft();
          })
          .then(function(finalResp){

            if (finalResp && finalResp.job_id) {
              pollJob(finalResp.job_id);
            }
          })
          .catch(function(err){

            setLargeStatusText(err.message || String(err), "is-error");
          });
      },

      onError: function(err){
        setLargeStatusText("PayPal error: " + err, "is-error");
      }

    }).render("#cmmt-paypal-large-upload");
  }

  renderPayPal();

  // FORM SUBMIT BLOCK
  $("#cmmt-large-upload-form").off("submit").on("submit", function(e){
    e.preventDefault();

    if (state.uploadReady && !state.isPaid) {
      setLargeStatusText("File already uploaded. Please click PayPal to continue.", "is-success");
      enablePayPal();
    }
  });

  // INIT STATE
  disablePayPal();
  setLargeProgress(0);
  updateEstimate();

}); // end document ready

})(jQuery);
/* ===== END CLEAN VERSION ===== */

/* ===== Crossmarket Linkable Tab Switching Fix ===== */
jQuery(function($){

  function normalizeCmsgTarget(target) {
    target = (target || "").toString().replace(/^#/, "").trim();

    var aliases = {
      "large": "large_upload",
      "large-file": "large_upload",
      "large_file": "large_upload",
      "gcs": "large_upload",
      "google_cloud": "large_upload",
      "google-cloud": "large_upload",
      "google_drive": "drive",
      "google-drive": "drive",
      "poster_studio": "poster",
      "poster-studio": "poster"
    };

    return aliases[target] || target;
  }

  function cmsgPanelExists(target) {
    return $('.cmsg-tab-panel[data-cmsg-panel="' + target + '"]').length > 0;
  }

  function switchCmsgPanel(target, scrollToPanel) {
    target = normalizeCmsgTarget(target);

    if (!target || !cmsgPanelExists(target)) return false;

    $(".cmsg-source-card").removeClass("is-active");
    $('.cmsg-source-card[data-cmsg-tab="' + target + '"]').addClass("is-active");

    $(".cmsg-tab-panel").removeClass("is-active").hide();
    $('.cmsg-tab-panel[data-cmsg-panel="' + target + '"]').addClass("is-active").show();

    if (window.history && window.history.replaceState) {
      window.history.replaceState(null, "", "#" + target);
    }

    if (scrollToPanel) {
      var panel = $('.cmsg-tab-panel[data-cmsg-panel="' + target + '"]').first();
      if (panel.length) {
        $("html, body").animate({
          scrollTop: Math.max(0, panel.offset().top - 90)
        }, 350);
      }
    }

    return true;
  }

  window.switchCmsgPanel = switchCmsgPanel;

  $(document).off("click.cmsgTabs", ".cmsg-source-card")
    .on("click.cmsgTabs", ".cmsg-source-card", function(e){
      e.preventDefault();
      switchCmsgPanel($(this).data("cmsg-tab"), true);
    });

  $(document).off("click.cmsgTabs", "[data-cmsg-switch]")
    .on("click.cmsgTabs", "[data-cmsg-switch]", function(e){
      e.preventDefault();
      switchCmsgPanel($(this).data("cmsg-switch"), true);
    });

  $(document).off("click.cmsgHashTabs", 'a[href^="#"]')
    .on("click.cmsgHashTabs", 'a[href^="#"]', function(e){
      var target = normalizeCmsgTarget($(this).attr("href"));
      if (!cmsgPanelExists(target)) return;

      e.preventDefault();
      switchCmsgPanel(target, true);
    });

  var hashTarget = normalizeCmsgTarget(window.location.hash);
  var initial = $(".cmsg-source-card.is-active").first().data("cmsg-tab") || "small";

  if (hashTarget && cmsgPanelExists(hashTarget)) {
    switchCmsgPanel(hashTarget, false);
  } else {
    switchCmsgPanel(initial, false);
  }

  $(window).off("hashchange.cmsgTabs").on("hashchange.cmsgTabs", function(){
    var target = normalizeCmsgTarget(window.location.hash);
    if (target && cmsgPanelExists(target)) {
      switchCmsgPanel(target, true);
    }
  });

});
/* ===== End Crossmarket Linkable Tab Switching Fix ===== */

/* ===== SMALL FILE UPLOAD RESTORE (Estimate + PayPal) ===== */
jQuery(function($){

  var smallState = {
    draftId: null,
    paymentToken: ""
  };

  function getSmallRuntime() {
    return parseFloat(
      $('#cmsg-upload-form [name="runtime_minutes"]').val() ||
      $('#cmsg-upload-form input[type="number"]').val() ||
      0
    );
  }

  function updateSmallEstimate() {
    var runtime = getSmallRuntime();
    var price = 0;
    var currency = "$";

    if (typeof cmsgData !== "undefined" && cmsgData.pricing) {
      price = parseFloat(cmsgData.pricing.subtitlePerMinute || 0);
      currency = cmsgData.pricing.currency || "$";
    }

    var total = runtime > 0 ? runtime * price : 0;

    var box = $("#cmsg-estimate, .cmsg-order-total").first();
    if (box.length) {
      box.text(currency + total.toFixed(2));
    }
  }

  function createSmallDraft() {
    var fd = new FormData($("#cmsg-upload-form")[0]);

    fd.append("action", "cmsg_create_draft");
    fd.append("nonce", (cmsgData && cmsgData.nonce) || "");
    fd.append("source_type", "browser_upload");
fd.append("caption_mode", $("#cmsg-upload-form [name='caption_mode']").val() || "subtitle");

    return fetch((cmsgData && cmsgData.ajaxUrl) || ajaxurl, {
      method: "POST",
      body: fd
    }).then(r => r.json());
  }

  function createOrder(draftId) {
    return fetch((cmsgData && cmsgData.ajaxUrl) || ajaxurl, {
      method: "POST",
      headers: {"Content-Type":"application/x-www-form-urlencoded"},
      body: new URLSearchParams({
        action: "cmsg_create_paypal_order",
        nonce: cmsgData.nonce,
        draft_id: draftId
      })
    }).then(r => r.json()).then(res => res.data.orderID);
  }

  function captureOrder(draftId, orderId) {
    return fetch((cmsgData && cmsgData.ajaxUrl) || ajaxurl, {
      method: "POST",
      headers: {"Content-Type":"application/x-www-form-urlencoded"},
      body: new URLSearchParams({
        action: "cmsg_capture_paypal_order",
        nonce: cmsgData.nonce,
        draft_id: draftId,
        order_id: orderId
      })
    }).then(r => r.json());
  }

  function renderSmallPayPal() {

var container = $('#cmsg-upload-form').find('#cmsg-paypal-buttons, .cmsg-paypal-buttons').first();

if (!container.length) {
  container = $('.cmsg-tab-panel[data-cmsg-panel="small"]').find('#cmsg-paypal-buttons, .cmsg-paypal-buttons').first();
}

     if (!container.length || !window.paypal) return;

container.empty();
container.removeData("rendered");
container.css({
  display: "block",
  visibility: "visible",
  opacity: "1",
  minHeight: "45px"
});
container.data("rendered", true);

    window.paypal.Buttons({

      createOrder: function() {
        return createSmallDraft().then(function(draft){
          smallState.draftId = draft.data.draft_id;
          return createOrder(smallState.draftId);
        });
      },

onApprove: function(data) {

  return captureOrder(smallState.draftId, data.orderID)
    .then(function(captureResp){

      $("#cmsg-upload-status")
        .text("Payment successful. Processing...");

      if (!captureResp.success || !captureResp.data || !captureResp.data.payment_token) {
        return;
      }

      var paymentToken = captureResp.data.payment_token;

      var poller = setInterval(function(){

        fetch((cmsgData && cmsgData.ajaxUrl) || ajaxurl, {
          method: "POST",
          headers: {"Content-Type":"application/x-www-form-urlencoded"},
          body: new URLSearchParams({
            action: "cmsg_finalize_paid_draft",
            nonce: cmsgData.nonce,
            payment_token: paymentToken
          })
        })
        .then(r => r.json())
        .then(function(resp){

          if (!resp.success || !resp.data) {
            return;
          }

          if (resp.data.status === "completed") {

            clearInterval(poller);

            $("#cmsg-upload-status")
              .text("Job completed. Your subtitle file is ready.");

            fetch((cmsgData && cmsgData.ajaxUrl) || ajaxurl, {
              method: "POST",
              headers: {"Content-Type":"application/x-www-form-urlencoded"},
              body: new URLSearchParams({
                action: "cmsg_issue_download_grant",
                nonce: cmsgData.nonce,
                job_id: resp.data.job_id
              })
            })
            .then(r => r.json())
            .then(function(grantResp){

              if (!grantResp.success || !grantResp.data) {
                return;
              }

              var srtUrl = grantResp.data.srt_download_url || grantResp.data.download_url || "";
              var vttUrl = grantResp.data.vtt_download_url || "";

              if (srtUrl) {

                if (!$("#cmsg-download-link").length) {
                  $("#cmsg-upload-status").after(
                    '<div id="cmsg-download-wrap" style="margin-top:15px;"><a href="#" id="cmsg-download-link" class="cmsg-btn">Download SRT Subtitle File</a></div>'
                  );
                }

                $("#cmsg-download-link")
                  .attr("href", srtUrl)
                  .attr("download", "")
                  .removeAttr("target")
                  .show();
              }

              if (vttUrl) {

                if (!$("#cmsg-vtt-download-link").length) {
                  $("#cmsg-download-link").after(
'<a href="#" id="cmmt-large-vtt-download-link" class="cmsg-btn cmsg-btn--primary" style="margin-left:18px; display:inline-flex; align-items:center; justify-content:center; visibility:visible; opacity:1; pointer-events:auto;">'
                  );
                }

                $("#cmsg-vtt-download-link")
                  .attr("href", vttUrl)
                  .attr("download", "")
                  .removeAttr("target")
                  .show();
              }

            });

          }

          if (resp.data.status === "failed") {
            clearInterval(poller);

            $("#cmsg-upload-status")
              .text(resp.data.message || "Processing failed.");
          }

        });

      }, 5000);

    });
}

    }).render(container[0]);
  }

  // INIT
  if ($("#cmsg-upload-form").length) {

    updateSmallEstimate();
    renderSmallPayPal();

    $("#cmsg-upload-form").on("input change", "input, select", function(){
      updateSmallEstimate();
    });

  }

});
/* ===== END SMALL FILE RESTORE ===== */

/* ===== GOOGLE DRIVE IMPORT FLOW ===== */
jQuery(function($){

  var driveState = {
    draftId: null,
    paymentToken: "",
    jobId: null,
    isPaid: false
  };

  function driveAjaxUrl() {
    if (typeof cmsgData !== "undefined" && cmsgData.ajaxUrl) return cmsgData.ajaxUrl;
    if (typeof ajaxurl !== "undefined") return ajaxurl;
    return "/wp-admin/admin-ajax.php";
  }

  function driveNonce() {
    return (typeof cmsgData !== "undefined" && cmsgData.nonce) ? cmsgData.nonce : "";
  }

  function setDriveStatus(message, cls) {
    var box = $("#cmmt-drive-status");
    box.removeClass("is-working is-success is-error");
    if (cls) box.addClass(cls);
    box.text(message);
  }

  function postDrive(data) {
    return fetch(driveAjaxUrl(), {
      method: "POST",
      headers: {"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},
      body: new URLSearchParams(data)
    }).then(r => r.json());
  }

function createDriveDraft() {
  var form = $("#cmmt-drive-form")[0];

  if (!form) {
    return Promise.reject(new Error("Google Drive form not found."));
  }

  var fd = new FormData(form);
  var driveLink = ($("#cmmt-drive-link").val() || "").trim();

  fd.append("action", "cmsg_create_draft");
  fd.append("nonce", driveNonce());
  fd.append("source_type", "google_drive");
  fd.append("caption_mode", $("#cmmt-drive-form [name='caption_mode']").val() || "subtitle");

  fd.append("source_language", $("#cmmt-drive-form [name='source_language']").val() || "auto");
  fd.append("output_language", $("#cmmt-drive-form [name='output_language']").val() || "same");
  fd.append("translation_mode", $("#cmmt-drive-form [name='translation_mode']").val() || "none");
  fd.append("model_size", $("#cmmt-drive-form [name='model_size']").val() || "small");

  fd.append("drive_url", driveLink);
  fd.append("drive_link", driveLink);

  // Required by backend draft validation for non-browser uploads
  fd.append("original_filename", "google-drive-video.mp4");
  fd.append("file_size", 1);

  return fetch(driveAjaxUrl(), {
    method: "POST",
    body: fd
  })
  .then(function(r){
  return r.text().then(function(text){
    try {
      return JSON.parse(text);
    } catch(e) {
      throw new Error("Create draft returned non-JSON response: " + text.substring(0, 120));
    }
  });
})
.then(function(resp){
      if (!resp.success || !resp.data || !resp.data.draft_id) {
      throw new Error((resp.data && resp.data.message) || "Unable to create Google Drive draft.");
    }

    return resp.data.draft_id;
  });
}

  function createDriveOrder(draftId) {
    return postDrive({
      action: "cmsg_create_paypal_order",
      nonce: driveNonce(),
      draft_id: draftId
    }).then(function(resp){
      if (!resp.success || !resp.data || !resp.data.orderID) {
        throw new Error((resp.data && resp.data.message) || "PayPal orderID missing.");
      }

      return resp.data.orderID;
    });
  }

  function captureDriveOrder(draftId, orderId) {
    return postDrive({
      action: "cmsg_capture_paypal_order",
      nonce: driveNonce(),
      draft_id: draftId,
      order_id: orderId,
      kind: "subtitle"
    }).then(function(resp){
      if (!resp.success) {
        throw new Error((resp.data && resp.data.message) || "Unable to capture PayPal payment.");
      }

      return resp.data || {};
    });
  }

  function finalizeDriveDraft() {
    var fd = new FormData();
    fd.append("action", "cmsg_finalize_paid_draft");
    fd.append("nonce", driveNonce());
    fd.append("draft_id", driveState.draftId);
    fd.append("payment_token", driveState.paymentToken);

    return fetch(driveAjaxUrl(), {
      method: "POST",
      body: fd
    })
    .then(r => r.json())
    .then(function(resp){
      if (!resp.success) {
        throw new Error((resp.data && resp.data.message) || "Unable to finalize Google Drive job.");
      }

      return resp.data || {};
    });
  }

function setDriveProgress(percent) {
  percent = Math.max(0, Math.min(100, parseInt(percent || 0, 10)));

  $("#cmmt-drive-progress-percent").text(percent + "%");
  $("#cmmt-drive-progress-bar").css("width", percent + "%");
}

  function pollDriveJob(jobId) {
  var progress = 25;
  setDriveProgress(progress);

    setDriveStatus("Payment received. Your Google Drive file is being processed. A download button will appear below once processing is complete.", "is-success");

    var timer = setInterval(function(){

      postDrive({
        action: "cmsg_job_status",
        nonce: driveNonce(),
        job_id: jobId
      }).then(function(resp){

        if (!resp.success || !resp.data) return;

        if (resp.data.status === "completed") {
          clearInterval(timer);

          setDriveProgress(100);
          setDriveStatus("Job completed. Your subtitle file is ready. Click the download button below or check your email for a download link.", "is-success");

          postDrive({
            action: "cmsg_issue_download_grant",
            nonce: driveNonce(),
            job_id: jobId
          }).then(function(grantResp){

if (grantResp.success && grantResp.data) {
  var srtUrl = grantResp.data.srt_download_url || grantResp.data.download_url || "";
  var vttUrl = grantResp.data.vtt_download_url || "";

  if (srtUrl) {
    $("#cmsg-download-link")
      .attr("href", srtUrl)
      .attr("download", "")
      .removeAttr("target")
      .show()
      .text("Download SRT Subtitle File");
  }

  if (vttUrl) {
    var vttBtn = $("#cmsg-vtt-download-link");

    if (!vttBtn.length) {
      $("#cmsg-download-link").after(
'<a href="#" id="cmmt-large-vtt-download-link" class="cmsg-btn cmsg-btn--primary" style="margin-left:18px; display:inline-flex; align-items:center; justify-content:center; visibility:visible; opacity:1; pointer-events:auto;">' 
     );
      vttBtn = $("#cmsg-vtt-download-link");
    }

    vttBtn
      .attr("href", vttUrl)
      .attr("download", "")
      .removeAttr("target")
      .show()
      .text("Download VTT Closed Caption File");
 }

              $("#cmmt-drive-download-wrap")
                .css({
                  display: "block",
                  visibility: "visible",
                  opacity: "1",
                  marginTop: "16px"
                })
                .show();
            } else {
              setDriveStatus("Job completed, but download link could not be created. Please check your email.", "is-error");
            }

          });
        }

        if (resp.data.status === "failed") {
          clearInterval(timer);
          setDriveStatus(resp.data.message || "Google Drive job failed. Please contact support.", "is-error");
        }

      });

    }, 5000);
  }

  function renderDrivePayPal() {
    var container = $("#cmmt-paypal-drive");

    if (!container.length || !window.paypal) return;

    container.empty().css({
      display: "block",
      visibility: "visible",
      opacity: "1",
      minHeight: "45px",
      pointerEvents: "auto"
    });

    window.paypal.Buttons({

      createOrder: function() {
        setDriveStatus("Preparing Google Drive subtitle order...", "is-working");

        return createDriveDraft()
          .then(function(draftId){
            driveState.draftId = draftId;
            return createDriveOrder(draftId);
          });
      },

      onApprove: function(data) {
        return captureDriveOrder(driveState.draftId, data.orderID)
          .then(function(resp){
            driveState.isPaid = true;
            driveState.paymentToken = resp.payment_token || "";

            setDriveStatus("Payment received. Starting Google Drive processing...", "is-success");

            return finalizeDriveDraft();
          })
          .then(function(finalResp){
            if (finalResp && finalResp.job_id) {
              driveState.jobId = finalResp.job_id;
              pollDriveJob(finalResp.job_id);
            }
          })
          .catch(function(err){
            setDriveStatus(err.message || String(err), "is-error");
          });
      },

      onError: function(err) {
        setDriveStatus("PayPal error: " + err, "is-error");
      }

    }).render(container[0]);
  }

  if ($("#cmmt-drive-form").length && $("#cmmt-paypal-drive").length) {
    renderDrivePayPal();
  }

});
/* ===== END GOOGLE DRIVE IMPORT FLOW ===== */

/* ===== GOOGLE DRIVE VALIDATION + PROGRESS UI ===== */
jQuery(function($){

  function setDriveStatus(message, cls) {
    var box = $("#cmmt-drive-status");
    box.removeClass("is-working is-success is-error");
    if (cls) box.addClass(cls);
    box.text(message);
  }

  function setDriveProgress(percent) {
    percent = Math.max(0, Math.min(100, parseInt(percent || 0, 10)));
    $("#cmmt-drive-progress-percent").text(percent + "%");
    $("#cmmt-drive-progress-bar").css("width", percent + "%");
  }

  function isValidGoogleDriveLink(url) {
    return /^https:\/\/drive\.google\.com\/file\/d\/[^\/]+/.test(url) ||
           /^https:\/\/drive\.google\.com\/open\?id=/.test(url) ||
           /^https:\/\/drive\.google\.com\/uc\?id=/.test(url);
  }

  $("#cmmt-validate-drive").off("click.cmsgDrive").on("click.cmsgDrive", function(e){
    e.preventDefault();

    var link = ($("#cmmt-drive-link").val() || "").trim();

    if (!link) {
      setDriveStatus("Please paste a Google Drive share link first.", "is-error");
      setDriveProgress(0);
      return;
    }

    if (!isValidGoogleDriveLink(link)) {
      setDriveStatus("This does not look like a valid Google Drive file link.", "is-error");
      setDriveProgress(0);
      return;
    }

    setDriveStatus("Drive link looks valid. Google Drive import is for files under 2GB only. For larger files, feature films, masters, 4K exports, or long-form content, use the Large File / Google Cloud upload option. Proceed with PayPal only if this file is under 2GB and sharing is set to 'Anyone with the link can view'.", "is-success");
    setDriveProgress(10);
  });

});
/* ===== END GOOGLE DRIVE VALIDATION + PROGRESS UI ===== */
/* ===== GOOGLE DRIVE FLOW ===== */
jQuery(function($){

  function setDriveStatus(message, cls) {
    var box = $("#cmmt-drive-status");
    box.removeClass("is-working is-success is-error");
    if (cls) box.addClass(cls);
    box.text(message);
  }

  function setDriveProgress(percent) {
    percent = Math.max(0, Math.min(100, parseInt(percent || 0, 10)));
    $("#cmmt-drive-progress-percent").text(percent + "%");
    $("#cmmt-drive-progress-bar").css("width", percent + "%");
  }

  function updateDriveEstimate() {
    var runtime = parseFloat($("#cmmt-drive-runtime").val() || 0);
    var pricePerMinute = 0.05; // fallback
    var currency = "$";

    if (typeof cmsgData !== "undefined" && cmsgData.pricing) {
      pricePerMinute = parseFloat(cmsgData.pricing.subtitlePerMinute || 0.05);
      currency = cmsgData.pricing.currency || "$";
    }

    if (runtime > 0) {
      $("#cmmt-drive-estimate").text(currency + (runtime * pricePerMinute).toFixed(2));
    } else {
      $("#cmmt-drive-estimate").text(currency + "0.00");
    }
  }

  $("#cmmt-drive-runtime").on("input change", function(){
    updateDriveEstimate();
  });

  updateDriveEstimate();

  $("#cmmt-validate-drive").on("click", function(e){
    e.preventDefault();

    var link = ($("#cmmt-drive-link").val() || "").trim();

    if (!link) {
      setDriveStatus("Please paste a Google Drive link first.", "is-error");
      return;
    }

    if (!link.includes("drive.google.com")) {
      setDriveStatus("Invalid Google Drive link.", "is-error");
      return;
    }

    setDriveStatus("Drive link looks valid. Proceed with PayPal payment.", "is-success");
    setDriveProgress(10);
  });

});
/* ===== END GOOGLE DRIVE FLOW ===== */
