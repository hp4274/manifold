/* ==========================================================================
   Manifold Clean Energy — apply.js
   Application forms: file name labels, required-field guard, success state
   Loaded only by apply-*.html
   ========================================================================== */
(function () {
  'use strict';

  var form = document.getElementById('applyForm');
  if (!form) return;

  /* ---------- show the chosen file name inside the upload box ---------- */
  Array.prototype.forEach.call(form.querySelectorAll('input[type="file"]'), function (input) {
    input.addEventListener('change', function () {
      var box = input.closest('.upload');
      var label = box && box.querySelector('[data-file-label]');
      if (!label) return;
      label.textContent = input.files && input.files.length ? input.files[0].name : 'Choose file…';
    });
  });

  /* ---------- validation ---------- */
  function firstInvalid() {
    var required = form.querySelectorAll('[required]');
    for (var i = 0; i < required.length; i++) {
      var el = required[i];
      var empty = el.type === 'checkbox' ? !el.checked : !String(el.value).trim();
      if (empty || (el.checkValidity && !el.checkValidity())) return el;
    }
    return null;
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();

    var bad = firstInvalid();
    if (bad) {
      if (bad.reportValidity) {
        bad.reportValidity();
      } else {
        alert('Please complete the required fields before submitting.');
      }
      bad.focus();
      bad.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }

    var payload = new FormData(form);
    var button = form.querySelector('button[type="submit"]');

    if (button) {
      button.disabled = true;
      button.textContent = 'Sending…';
    }

    fetch(form.getAttribute('action'), {
      method: 'POST',
      body: payload,
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
    })
      .then(function (response) { return response.json().catch(function () { return {}; }); })
      .then(function (result) {
        if (result && result.ok) {
          showSuccess(result.message);
          return;
        }
        throw new Error((result && result.message) || 'Submission failed.');
      })
      .catch(function (err) {
        alert(err.message || 'Submission failed. Please call +91 97251 54186.');
        if (button) {
          button.disabled = false;
          button.textContent = 'Submit application';
        }
      });
  });

  function showSuccess(message) {
    var text = message || 'Our team reviews every application by hand. Expect a call or an ' +
      'email within two working days to arrange the technical assessment.';

    form.innerHTML =
      '<div class="form-done">' +
        '<span class="form-done__mark"><i class="bi bi-check-lg" aria-hidden="true"></i></span>' +
        '<h2>Thank you — your application is in.</h2>' +
        '<p>' + text.replace(/[<>&]/g, '') + '</p>' +
        '<a href="index.html" class="btn-pill btn-pill--accent">Back to home <i class="bi bi-arrow-right"></i></a>' +
      '</div>';
    form.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
})();
