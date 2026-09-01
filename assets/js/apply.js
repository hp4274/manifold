/* ==========================================================================
   Manifold Clean Energy — apply.js
   Application forms: file name labels, required-field guard, success state
   Loaded only by apply-*.html
   ========================================================================== */
(function () {

  /* main.js puts a popup helper on window. If it has not loaded — a cached
     half-deploy, a blocked script — the browser's own box is still better than
     saying nothing at all. */
  function say(message, kind) {
    if (typeof window.manifoldToast === 'function') {
      window.manifoldToast(message, kind || 'error');
      return;
    }

    window.alert(message);
  }
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

  /* ---------- the dial code follows the nationality ----------
     Picking "German" sets +49 on both mobile fields, because that is nearly
     always what is meant. It is a suggestion, not a rule: touch the code and it
     stays where you put it, even if the nationality changes afterwards. */
  var nationality = form.querySelector('[name="nationality"]');
  var dialFields  = form.querySelectorAll('[name="mobile_code"], [name="alt_mobile_code"]');

  if (nationality && dialFields.length) {
    Array.prototype.forEach.call(dialFields, function (dial) {
      dial.addEventListener('change', function () { dial.dataset.chosen = '1'; });
    });

    nationality.addEventListener('change', function () {
      var option = nationality.options[nationality.selectedIndex];
      var code   = option && option.dataset ? option.dataset.dial : '';

      if (!code) return;

      Array.prototype.forEach.call(dialFields, function (dial) {
        if (dial.dataset.chosen === '1') return;

        dial.value = code;
      });
    });
  }

  /* ---------- telling somebody a field is wrong ----------
     Red arrives late and leaves early: nothing is marked while it is still
     being typed for the first time, and the moment a wrong answer becomes a
     right one the field goes back to normal without waiting for anything. */
  function boxOf(el) {
    return el.closest('.field') || el.closest('.field-consent');
  }

  function checkable(el) {
    return el.willValidate && el.type !== 'hidden' && el.type !== 'submit';
  }

  function why(el) {
    var v = el.validity;

    if (v.valueMissing) {
      return el.type === 'checkbox' ? 'Please tick this to continue.' : 'This one is needed.';
    }
    if (v.rangeUnderflow || v.rangeOverflow) {
      return el.title || 'That date is outside the range we can accept.';
    }
    if (v.typeMismatch || v.patternMismatch || v.tooShort || v.tooLong) {
      return el.title || 'That does not look right yet.';
    }

    return el.validationMessage || 'Please check this one.';
  }

  function complain(el, message) {
    var box = boxOf(el);
    if (!box) return;

    box.classList.add('field--error');
    el.setAttribute('aria-invalid', 'true');

    var note = box.querySelector('[data-live-error]');

    if (!note) {
      note = document.createElement('span');
      note.className = 'field-error';
      note.setAttribute('data-live-error', '');
      /* announced when it appears, so it is not a colour change alone */
      note.setAttribute('role', 'alert');
      note.innerHTML = '<i class="bi bi-exclamation-circle" aria-hidden="true"></i><span></span>';
      box.appendChild(note);
    }

    note.lastChild.textContent = message;
  }

  function settle(el) {
    var box = boxOf(el);
    el.removeAttribute('aria-invalid');
    if (!box) return;

    box.classList.remove('field--error');

    var note = box.querySelector('[data-live-error]');
    if (note) note.remove();
  }

  function grade(el) {
    if (!checkable(el)) return true;

    if (el.checkValidity()) {
      settle(el);
      return true;
    }

    complain(el, why(el));
    return false;
  }

  /* blur is the first judgement — blur does not bubble, so this listens on the
     way down */
  form.addEventListener('blur', function (e) {
    var el = e.target;
    if (!checkable(el)) return;

    el.dataset.touched = '1';
    grade(el);
  }, true);

  form.addEventListener('input', function (e) {
    var el = e.target;
    if (!checkable(el)) return;

    /* a ten digit number is judged as soon as the tenth digit lands: waiting
       for the field to be left would be telling somebody something they can
       already see */
    var full = el.maxLength > 0 && String(el.value).length >= el.maxLength;

    if (el.dataset.touched || full) grade(el);
  });

  form.addEventListener('change', function (e) {
    var el = e.target;
    if (!checkable(el)) return;

    el.dataset.touched = '1';
    grade(el);
  });

  /* ---------- validation ---------- */
  function markAll() {
    var fields = form.querySelectorAll('input, select, textarea');
    var first  = null;

    Array.prototype.forEach.call(fields, function (el) {
      if (!checkable(el)) return;

      el.dataset.touched = '1';
      if (!grade(el) && !first) first = el;
    });

    return first;
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();

    /* every wrong field turns red at once, not one at a time */
    var bad = markAll();

    if (bad) {
      say('Please fix the fields marked in red before submitting.', 'error');
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
        say(err.message || 'Submission failed. Please call +91 97251 54186.', 'error');
        markAll();
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
        '<a href="./" class="btn-pill btn-pill--accent">Back to home <i class="bi bi-arrow-right"></i></a>' +
      '</div>';
    form.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
})();
