jQuery(function($){
  window.CMMT = window.CMMT || {};
  window.CMMT.setStatus = function($el, html, state){
    $el.removeClass('is-error is-success is-working');
    if(state) $el.addClass(state);
    $el.html(html);
  };
  window.CMMT.ajaxPost = function(payload){
    return $.ajax({
      url: cmsgData.ajaxUrl,
      type: 'POST',
      data: payload
    });
  };
});