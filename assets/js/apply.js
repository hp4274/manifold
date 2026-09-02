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

  /* the server refuses anything past this, so say so before the upload starts */
  var MAX_FILE_BYTES = 10 * 1024 * 1024;

  /* ---------- show the chosen file name inside the upload box ---------- */
  Array.prototype.forEach.call(form.querySelectorAll('input[type="file"]'), function (input) {
    input.addEventListener('change', function () {
      var picked = input.files && input.files[0];
      var box = input.closest('.upload');
      var label = box && box.querySelector('[data-file-label]');

      if (picked && picked.size > MAX_FILE_BYTES) {
        input.setCustomValidity(
          'That file is ' + (picked.size / 1024 / 1024).toFixed(1) +
          ' MB — 10 MB is the most we can take. Choose a smaller one.'
        );
      } else {
        input.setCustomValidity('');
      }

      if (!label) return;
      label.textContent = picked ? picked.name : 'Choose file…';
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
        shapeNumber(dial);
      });
    });
  }

  /* ---------- the number is as long as its country says ----------
     Ten digits is India, not the world: a British mobile is ten but a landline
     can be nine, Singapore is eight, China eleven. The box takes what the
     chosen code allows and says so, rather than refusing a real number.

     The same table is in admin/config.php, which is what checks a submission
     that never went through this form. Change one and change the other. */
  var DIAL_DIGITS = {
    '91': [10, 10], '1': [10, 10], '44': [9, 10], '61': [9, 9],
    '64': [8, 10], '27': [9, 9], '234': [10, 10], '254': [9, 9],
    '233': [9, 9], '255': [9, 9], '256': [9, 9], '251': [9, 9],
    '20': [10, 10], '212': [9, 9], '260': [9, 9], '263': [9, 9],
    '971': [9, 9], '966': [9, 9], '974': [8, 8], '965': [8, 8],
    '968': [8, 8], '973': [8, 8], '962': [9, 9], '972': [9, 9],
    '92': [10, 10], '880': [10, 10], '94': [9, 9], '977': [10, 10],
    '975': [8, 8], '960': [7, 7], '95': [8, 10], '93': [9, 9],
    '86': [11, 11], '81': [10, 10], '82': [9, 10], '65': [8, 8],
    '60': [9, 10], '62': [9, 12], '66': [9, 9], '84': [9, 10],
    '63': [10, 10], '852': [8, 8], '49': [10, 11], '33': [9, 9],
    '39': [9, 10], '34': [9, 9], '351': [9, 9], '31': [9, 9],
    '32': [9, 9], '41': [9, 9], '43': [10, 11], '46': [7, 9],
    '47': [8, 8], '45': [8, 8], '358': [9, 10], '353': [9, 9],
    '48': [9, 9], '30': [10, 10], '420': [9, 9], '36': [9, 9],
    '40': [9, 9], '359': [9, 9], '7': [10, 10], '380': [9, 9],
    '90': [10, 10], '55': [10, 11], '52': [10, 10], '54': [10, 10],
    '56': [9, 9], '57': [10, 10], '51': [9, 9]
  };

  function dialDigits(code) {
    /* E.164 stops a whole number at 15 digits and nothing real is under six,
       which is what an unlisted country is held to */
    return DIAL_DIGITS[code] || [6, 15 - Math.min(4, String(code).length)];
  }

  /* How a number is written where it is dialled. A `#` is a digit; everything
     else is punctuation the box types for you, so +1 reads
     "(212) 555-0143" and +91 reads "98765 43210". A country nobody wrote a
     mask for is grouped in threes, which reads better than an unbroken run of
     digits and is wrong about nothing. */
  var DIAL_MASK = {
    '1':   '(###) ###-####',
    '91':  '##### #####',
    '44':  '#### ######',
    '61':  '### ### ###',
    '64':  '## ### ####',
    '27':  '## ### ####',
    '234': '### ### ####',
    '254': '### ######',
    '233': '## ### ####',
    '255': '### ### ###',
    '256': '### ######',
    '251': '## ### ####',
    '20':  '## #### ####',
    '212': '### ######',
    '260': '## #######',
    '263': '## ### ####',
    '971': '## ### ####',
    '966': '## ### ####',
    '974': '#### ####',
    '965': '#### ####',
    '968': '#### ####',
    '973': '#### ####',
    '962': '# #### ####',
    '972': '##-###-####',
    '92':  '### #######',
    '880': '#### ######',
    '94':  '## ### ####',
    '977': '###-#######',
    '975': '## ## ## ##',
    '960': '###-####',
    '95':  '## ### ####',
    '93':  '## ### ####',
    '86':  '### #### ####',
    '81':  '##-####-####',
    '82':  '##-####-####',
    '65':  '#### ####',
    '60':  '##-### ####',
    '62':  '###-####-####',
    '66':  '##-###-####',
    '84':  '### ### ####',
    '63':  '### ### ####',
    '852': '#### ####',
    '49':  '#### #######',
    '33':  '# ## ## ## ##',
    '39':  '### ### ####',
    '34':  '### ## ## ##',
    '351': '### ### ###',
    '31':  '# ## ## ## ##',
    '32':  '### ## ## ##',
    '41':  '## ### ## ##',
    '43':  '### ########',
    '46':  '##-### ## ##',
    '47':  '### ## ###',
    '45':  '## ## ## ##',
    '358': '## ### ####',
    '353': '## ### ####',
    '48':  '### ### ###',
    '30':  '### ### ####',
    '420': '### ### ###',
    '36':  '## ### ####',
    '40':  '### ### ###',
    '359': '## ### ####',
    '7':   '### ###-##-##',
    '380': '## ### ####',
    '90':  '### ### ####',
    '55':  '(##) #####-####',
    '52':  '## #### ####',
    '54':  '## ####-####',
    '56':  '# #### ####',
    '57':  '### ### ####',
    '51':  '### ### ###'
  };

  /** The mask for a code, or threes for a country nobody wrote one for. */
  function dialMask(code) {
    if (DIAL_MASK[code]) return DIAL_MASK[code];

    var max = dialDigits(code)[1];
    var parts = [];

    for (var left = max; left > 0; left -= 3) {
      parts.push(left >= 5 ? '###' : new Array(left + 1).join('#'));
    }

    return parts.join(' ');
  }

  /** The digits somebody actually typed, with a pasted country code dropped. */
  function bareDigits(value, code) {
    var digits = String(value).replace(/\D/g, '');
    var max = dialDigits(code)[1];

    /* pasting "+1 212 555 0143" into a box that already says +1 should not
       leave the 1 in front of the number */
    if (digits.length > max && digits.indexOf(code) === 0) {
      digits = digits.slice(code.length);
    }

    return digits.slice(0, max);
  }

  /** Digits poured into a mask, stopping wherever they run out. */
  function applyMask(digits, mask) {
    var out = '';
    var at = 0;

    for (var i = 0; i < mask.length && at < digits.length; i++) {
      out += mask.charAt(i) === '#' ? digits.charAt(at++) : mask.charAt(i);
    }

    return out;
  }

  /**
   * Writes the number back in the shape its country uses, and puts the caret
   * back where the typing was — counted in digits, because the punctuation
   * around it has just moved.
   */
  function formatNumber(box, code) {
    var caret = box.selectionStart;
    var typed = caret === null ? box.value.length : caret;
    var before = box.value.slice(0, typed).replace(/\D/g, '').length;
    var mask = dialMask(code);

    /* The limit is counted in characters and the mask spends three of them on
       brackets, a space and a dash — so it is set from the mask in use here,
       every time, rather than once when the country was picked. Left behind by
       a country change it cut a ten-digit American number off at seven. */
    box.maxLength = mask.length;

    box.value = applyMask(bareDigits(box.value, code), mask);

    if (caret === null || document.activeElement !== box) return;

    /* the position after the same number of digits, punctuation and all */
    var seen = 0;
    var at = box.value.length;

    for (var i = 0; i < box.value.length; i++) {
      if (/\d/.test(box.value.charAt(i))) {
        seen++;

        if (seen === before) {
          at = i + 1;
          break;
        }
      }
    }

    at = before === 0 ? 0 : at;
    box.setSelectionRange(at, at);
  }

  /**
   * Holds a number to the length its country uses.
   *
   * Not `pattern`: the box now carries brackets and dashes, and what matters is
   * how many digits are inside them — which one attribute cannot say for a
   * country whose numbers run to nine digits or ten.
   */
  function checkNumber(box, code) {
    var range = dialDigits(code);
    var digits = box.value.replace(/\D/g, '').length;
    var said = range[0] === range[1]
      ? range[0] + ' digits'
      : range[0] + ' to ' + range[1] + ' digits';

    box.setCustomValidity(
      digits === 0 || (digits >= range[0] && digits <= range[1])
        ? ''
        : 'A number for +' + code + ' is ' + said + '. This one has ' + digits + '.'
    );

    return said;
  }

  /** Puts the rule and the shape for one dial code onto the number beside it. */
  function shapeNumber(dial) {
    var box = dial.form.querySelector(
      '[name="' + (dial.name === 'mobile_code' ? 'mobile_number' : 'alt_mobile_number') + '"]'
    );

    if (!box) return;

    var code = dial.value;
    var mask = dialMask(code);

    /* the mask is longer than the number inside it, and `pattern` goes with it:
       the digits are counted in checkNumber() instead */
    box.maxLength = mask.length;
    box.removeAttribute('pattern');
    box.placeholder = mask.replace(/#/g, '0');

    formatNumber(box, code);

    var said = checkNumber(box, code);

    box.title = said.charAt(0).toUpperCase() + said.slice(1)
      + ', written as ' + mask.replace(/#/g, '0');

    /* a box already marked wrong under the old rule is asked again under this
       one, so switching country clears a complaint it has just answered */
    if (box.value !== '' && box.dataset.touched === '1') {
      grade(box);
    }

    if (!box.dataset.masked) {
      box.dataset.masked = '1';

      box.addEventListener('input', function () {
        formatNumber(box, dial.value);
        checkNumber(box, dial.value);
      });
    }
  }

  /* Every code carries its own length and shape, whoever set it and whenever.
     This runs here rather than up beside the nationality: `var` hoists the two
     tables above by name but not by value, and reading them before they are
     assigned threw — which stopped the whole file, took the submit handler
     with it, and left the form posting like plain HTML. */
  if (dialFields.length) {
    Array.prototype.forEach.call(dialFields, function (dial) {
      /* `change` is a person picking one; `input` is a restored draft or the
         portal prefill putting one in. Both have to reshape the number. */
      dial.addEventListener('change', function () { shapeNumber(dial); });
      dial.addEventListener('input', function () { shapeNumber(dial); });
      shapeNumber(dial);
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

  /* what the field is called, for messages that name it rather than saying
     "this one" twelve times down the page */
  function labelOf(el) {
    var box = boxOf(el);
    var label = (el.id && form.querySelector('label[for="' + el.id + '"]'))
      || (box && (box.querySelector('legend') || box.querySelector('label')));
    var text = label ? label.textContent : (el.getAttribute('aria-label') || el.name || '');

    return text.replace(/\*/g, '').replace(/\s+/g, ' ').trim();
  }

  function why(el) {
    var v = el.validity;
    var name = labelOf(el);

    if (v.valueMissing) {
      if (el.type === 'checkbox') return 'Tick this to continue.';
      if (el.type === 'file')     return 'Choose a file for ' + (name || 'this') + '.';
      if (el.tagName === 'SELECT') return 'Choose a ' + (name || 'value').toLowerCase() + '.';
      return (name || 'This') + ' is needed.';
    }
    if (v.rangeUnderflow || v.rangeOverflow) {
      return el.title || 'That date is outside the range we can accept.';
    }
    if (v.typeMismatch) {
      if (el.type === 'email') return 'That is not a complete email address — it needs an @ and a domain.';
      if (el.type === 'url')   return 'That is not a complete web address.';
      return el.title || 'That is not the right kind of value.';
    }
    if (v.patternMismatch) {
      return el.title || 'That is not in the format this field takes.';
    }
    if (v.tooShort) {
      return (name || 'This') + ' needs at least ' + el.minLength + ' characters.';
    }
    if (v.tooLong) {
      return (name || 'This') + ' can be at most ' + el.maxLength + ' characters.';
    }
    if (v.stepMismatch) {
      return el.title || 'That value is not one this field steps to.';
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

    /* opening the file picker blurs the field: an empty upload is not a
       mistake yet, only a missing one at Next or at submit is */
    if (el.type === 'file' && !(el.files && el.files.length)) return;

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
    if (el.type === 'file' && !(el.files && el.files.length)) return;

    el.dataset.touched = '1';
    grade(el);
  });

  /* ---------- one step at a time ----------
     The page ships as twelve `.form-step` sections in one long scroll, and it
     stays that way without JavaScript. With JavaScript the sections become a
     wizard: one on screen, a bar saying where you are, and Next refusing to
     move on until the step it is leaving is filled in correctly. Nothing in the
     markup changes, so the form still posts every field it always did. */
  var steps   = Array.prototype.slice.call(form.querySelectorAll('.form-step'));
  var actions = form.querySelector('.form-actions');
  var current = 0;
  var progressLabel, progressBar, prevButton, nextButton;

  function fieldsIn(root) {
    return Array.prototype.filter.call(
      root.querySelectorAll('input, select, textarea'), checkable
    );
  }

  /* every wrong field in `root` turns red at once; the first one is returned */
  function markIn(root) {
    var first = null;

    fieldsIn(root).forEach(function (el) {
      /* an upload nobody has filled yet is not a mistake on the way through:
         a file too big is, and a missing one is caught again at submit */
      if (el.type === 'file' && !(el.files && el.files.length)) return;

      el.dataset.touched = '1';
      if (!grade(el) && !first) first = el;
    });

    return first;
  }

  function stepTitle(step) {
    var heading = step.querySelector('.form-step__head h2');
    return heading ? heading.textContent.trim() : '';
  }

  function show(index, focusIt) {
    current = Math.max(0, Math.min(index, steps.length - 1));

    steps.forEach(function (step, i) {
      step.classList.toggle('is-current', i === current);
    });

    var last = current === steps.length - 1;
    prevButton.disabled = current === 0;
    nextButton.hidden = last;
    if (actions) actions.hidden = !last;

    progressLabel.textContent =
      'Step ' + (current + 1) + ' of ' + steps.length + ': ' + stepTitle(steps[current]);
    progressBar.style.width = ((current + 1) / steps.length * 100) + '%';
    progressBar.parentNode.setAttribute('aria-valuenow', String(current + 1));

    if (focusIt !== false) {
      /* only move the page when the step head is not already sitting under the
         progress bar — scroll-margin-top keeps it clear of header and bar */
      var head = steps[current].getBoundingClientRect().top;
      var floor = (progressBar.parentNode.parentNode.getBoundingClientRect().bottom || 0) + 8;
      if (head < floor || head > window.innerHeight * 0.6) {
        steps[current].scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
      var first = steps[current].querySelector('input, select, textarea');
      if (first) first.focus({ preventScroll: true });
    }
  }

  /* the step holding a given field, so an error link can open it */
  function stepOf(el) {
    for (var i = 0; i < steps.length; i++) {
      if (steps[i].contains(el)) return i;
    }
    return -1;
  }

  function buildWizard() {
    if (steps.length < 2) return false;

    form.classList.add('apply-form--wizard');

    /* the reveal observer never sees a hidden step, so it would leave every
       one after the first stuck at opacity 0 */
    steps.forEach(function (step) { step.classList.remove('reveal'); });

    var progress = document.createElement('div');
    progress.className = 'form-progress';
    progress.innerHTML =
      '<p class="form-progress__label" role="status" aria-live="polite"></p>' +
      '<div class="form-progress__track" role="progressbar" aria-valuemin="1" aria-valuemax="' +
        steps.length + '"><span class="form-progress__bar"></span></div>';
    form.insertBefore(progress, steps[0]);

    progressLabel = progress.querySelector('.form-progress__label');
    progressBar   = progress.querySelector('.form-progress__bar');

    var nav = document.createElement('div');
    nav.className = 'form-nav';
    nav.innerHTML =
      '<button type="button" class="btn-pill btn-pill--ghost-ink" data-step-prev>' +
        '<i class="bi bi-arrow-left" aria-hidden="true"></i> Previous</button>' +
      '<button type="button" class="btn-pill btn-pill--accent" data-step-next>' +
        'Next <i class="bi bi-arrow-right" aria-hidden="true"></i></button>';
    form.insertBefore(nav, actions || null);

    prevButton = nav.querySelector('[data-step-prev]');
    nextButton = nav.querySelector('[data-step-next]');

    prevButton.addEventListener('click', function () { show(current - 1); });

    nextButton.addEventListener('click', function () {
      var bad = markIn(steps[current]);

      if (bad) {
        say('Finish this step before moving on — the fields in red still need an answer.', 'error');
        bad.focus();
        bad.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
      }

      show(current + 1);
    });

    /* Enter in a text field moves on rather than submitting from step 3 */
    form.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' || e.target.tagName === 'TEXTAREA') return;
      if (current === steps.length - 1) return;

      e.preventDefault();
      nextButton.click();
    });

    show(0, false);
    return true;
  }

  var stepped = buildWizard();

  /* ---------- what went wrong, all in one place ----------
     A banner above the form listing every field still wanting an answer, each
     a link that opens the step it lives on and puts the cursor in it. */
  function summarise(bad) {
    var old = form.querySelector('.form-errors');
    if (old) old.remove();
    if (!bad.length) return;

    var box = document.createElement('div');
    box.className = 'form-errors';
    box.setAttribute('role', 'alert');
    box.setAttribute('tabindex', '-1');

    var heading = document.createElement('p');
    heading.className = 'form-errors__title';
    heading.innerHTML = '<i class="bi bi-exclamation-triangle" aria-hidden="true"></i> ' +
      bad.length + (bad.length === 1 ? ' field needs' : ' fields need') + ' attention';
    box.appendChild(heading);

    var list = document.createElement('ul');

    bad.forEach(function (el) {
      var item = document.createElement('li');
      var link = document.createElement('a');
      var where = stepOf(el);

      link.href = el.id ? '#' + el.id : '#';
      link.textContent = (labelOf(el) || el.name) + ' — ' + why(el);
      link.addEventListener('click', function (e) {
        e.preventDefault();
        if (stepped && where > -1) show(where, false);
        el.focus();
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
      });

      item.appendChild(link);
      list.appendChild(item);
    });

    box.appendChild(list);
    form.insertBefore(box, form.firstChild);
    box.focus();
    box.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  /* ---------- keep what has been typed ----------
     Sixty fields is more than anyone wants to lose to a closed tab. Everything
     but the files, the honeypot and the hidden fields is kept in this browser
     and put back on the next visit; a successful submission clears it. */
  var draftKey = 'manifold.apply.' + (form.querySelector('[name="form"]') || {}).value;

  function draftable(el) {
    return el.name && el.type !== 'file' && el.type !== 'hidden' && el.name !== 'website';
  }

  function saveDraft() {
    var data = {};

    fieldsIn(form).forEach(function (el) {
      if (!draftable(el)) return;
      data[el.name] = (el.type === 'checkbox' || el.type === 'radio') ? el.checked : el.value;
    });

    try {
      window.localStorage.setItem(draftKey, JSON.stringify(data));
    } catch (e) {
      /* private window, or no room left — the form still works, it just forgets */
    }
  }

  function restoreDraft() {
    var raw;

    try {
      raw = window.localStorage.getItem(draftKey);
    } catch (e) {
      return;
    }
    if (!raw) return;

    var data;
    try {
      data = JSON.parse(raw);
    } catch (e) {
      return;
    }

    var restored = 0;

    fieldsIn(form).forEach(function (el) {
      if (!draftable(el) || !(el.name in data)) return;

      var value = data[el.name];

      if (el.type === 'checkbox' || el.type === 'radio') {
        if (el.checked === !!value) return;
        el.checked = !!value;
      } else {
        if (el.value === value || value === '') return;
        el.value = value;
      }

      restored++;

      /* a value put in rather than typed fires nothing, and the phone box
         formats itself off its own input event */
      el.dispatchEvent(new Event('input', { bubbles: true }));

      /* the dial code was restored as chosen, so nationality must not overwrite it */
      if (el.name === 'mobile_code' || el.name === 'alt_mobile_code') el.dataset.chosen = '1';
    });

    if (restored) {
      say('Picked up where you left off — ' + restored + ' answers restored.', 'ok');
      /* the consent gate and anything else watching has to see the new values */
      form.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  function clearDraft() {
    try {
      window.localStorage.removeItem(draftKey);
    } catch (e) {
      /* nothing was stored in the first place */
    }
  }

  var saveTimer;

  form.addEventListener('input', function () {
    window.clearTimeout(saveTimer);
    saveTimer = window.setTimeout(saveDraft, 400);
  });

  form.addEventListener('change', saveDraft);

  restoreDraft();

  /* ---------- validation ----------
     The submit button is shown locked until the declaration and the terms are
     ticked — lockUntilAccepted() in main.js does that for every form on the
     site. It stays pressable, and this is why: pressing it marks every wrong
     or missing field at once, the two unticked boxes included, and lists them
     with a link each. */
  form.addEventListener('submit', function (e) {
    e.preventDefault();

    /* every wrong field turns red at once, not one at a time */
    var bad = fieldsIn(form).filter(function (el) {
      el.dataset.touched = '1';
      return !grade(el);
    });

    if (bad.length) {
      summarise(bad);
      say(bad.length + (bad.length === 1 ? ' field needs' : ' fields need') + ' attention before this can be sent.', 'error');
      return;
    }

    summarise([]);

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
        summarise(fieldsIn(form).filter(function (el) { return !grade(el); }));
        if (button) {
          button.disabled = false;
          button.textContent = 'Submit application';
        }
      });
  });

  function showSuccess(message) {
    clearDraft();

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
