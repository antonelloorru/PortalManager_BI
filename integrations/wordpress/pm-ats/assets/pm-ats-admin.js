/*! PortalManager ATS — pm-ats-admin.js · @version 1.3.4
 * Selezione immagine dalla Libreria media (testata «Lavora con noi»): URL + ID allegato, anteprima, rimozione. */
(function ($) {
  'use strict';
  $(function () {
    $('.pm-ats-media').each(function () {
      var box = $(this), url = box.find('.pm-ats-media-url'), id = box.find('.pm-ats-media-id'), prev = box.find('.pm-ats-media-prev'), frame = null;
      function show(u) { prev.empty(); if (u) $('<img>', { src: u, alt: '' }).appendTo(prev); box.find('.pm-ats-media-del').prop('hidden', !u); }
      box.on('click', '.pm-ats-media-pick', function (e) {
        e.preventDefault();
        if (typeof wp === 'undefined' || !wp.media) return;
        if (!frame) {
          frame = wp.media({ title: box.data('title'), button: { text: box.data('button') }, library: { type: 'image' }, multiple: false });
          frame.on('select', function () {
            var a = frame.state().get('selection').first().toJSON();
            url.val(a.url).trigger('change.pmats'); id.val(a.id);
            show(a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url);
          });
        }
        frame.open();
      });
      box.on('click', '.pm-ats-media-del', function (e) { e.preventDefault(); url.val(''); id.val('0'); show(''); });
      url.on('input', function () { id.val('0'); show($.trim(url.val())); });
    });
  });
})(jQuery);
