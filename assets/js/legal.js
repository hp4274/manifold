/* ==========================================================================
   Manifold Clean Energy — legal.js
   Policy switcher: one document visible at a time, deep-linkable by hash
   Loaded only by privacy-policy.html
   ========================================================================== */
(function () {
  'use strict';

  var nav = document.getElementById('legalNav');
  if (!nav) return;

  var buttons = Array.prototype.slice.call(nav.querySelectorAll('button[data-doc]'));
  var docs = Array.prototype.slice.call(document.querySelectorAll('.legal-doc'));

  function show(id, push) {
    var match = docs.some(function (doc) { return doc.id === id; });
    if (!match) id = docs.length ? docs[0].id : '';

    docs.forEach(function (doc) { doc.hidden = doc.id !== id; });
    buttons.forEach(function (btn) {
      btn.setAttribute('aria-selected', String(btn.dataset.doc === id));
    });

    if (push && window.history && window.history.replaceState) {
      window.history.replaceState(null, '', '#' + id);
    }
  }

  buttons.forEach(function (btn) {
    btn.addEventListener('click', function () {
      show(btn.dataset.doc, true);
      /* keep the reader at the top of the document they just opened */
      var panel = document.getElementById('legalPanel');
      if (panel && window.scrollY > panel.offsetTop) {
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    });
  });

  window.addEventListener('hashchange', function () {
    show(window.location.hash.replace('#', ''), false);
  });

  show(window.location.hash.replace('#', ''), false);
})();
