/* ==========================================================================
   Manifold Clean Energy — date picker

   A browser's own calendar cannot be styled, so every date and datetime field
   is given one of ours instead. The original input keeps its name, its value
   and its ISO format, so nothing on the server changes — it is simply moved
   out of sight behind a button that opens the panel below.

   Without this file the fields still work: they fall back to the native
   control, which the stylesheets already dress as well as they can.
   ========================================================================== */
(function () {
  'use strict';

  var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December'];
  var SHORT_MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                      'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  var DAYS = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];

  function pad(n) {
    return (n < 10 ? '0' : '') + n;
  }

  /** "2026-09-01" / "2026-09-01T09:30" → a Date, or null. */
  function parseValue(value) {
    if (!value) return null;

    var parts = String(value).split('T');
    var date = parts[0].split('-');
    if (date.length !== 3) return null;

    var time = (parts[1] || '00:00').split(':');
    var d = new Date(+date[0], +date[1] - 1, +date[2], +(time[0] || 0), +(time[1] || 0));

    return isNaN(d.getTime()) ? null : d;
  }

  function toValue(date, withTime) {
    var iso = date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());

    return withTime ? iso + 'T' + pad(date.getHours()) + ':' + pad(date.getMinutes()) : iso;
  }

  function toLabel(date, withTime) {
    var label = date.getDate() + ' ' + SHORT_MONTHS[date.getMonth()] + ' ' + date.getFullYear();

    return withTime ? label + ', ' + pad(date.getHours()) + ':' + pad(date.getMinutes()) : label;
  }

  function sameDay(a, b) {
    return a && b
      && a.getFullYear() === b.getFullYear()
      && a.getMonth() === b.getMonth()
      && a.getDate() === b.getDate();
  }

  function enhance(input) {
    if (input.dataset.picker === 'on') return;
    input.dataset.picker = 'on';

    var withTime = input.type === 'datetime-local';

    /* data-limit="past" allows nothing after today, "future" nothing before it.
       A static page cannot write today's date, so it is turned into a real
       min/max here — that way the native control obeys it as well. */
    var limit = input.getAttribute('data-limit');

    if (limit === 'past' && !input.getAttribute('max')) {
      input.setAttribute('max', toValue(new Date(), withTime));
    }

    if (limit === 'future' && !input.getAttribute('min')) {
      input.setAttribute('min', toValue(new Date(), withTime));
    }

    var min = parseValue(input.getAttribute('min'));
    var max = parseValue(input.getAttribute('max'));

    /* the real field stays in the form, just out of the way */
    input.classList.add('datefield__input');

    var wrap = document.createElement('div');
    wrap.className = 'datefield';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'datefield__button';
    button.setAttribute('aria-haspopup', 'dialog');
    button.setAttribute('aria-expanded', 'false');
    button.innerHTML =
      '<span class="datefield__value"></span>' +
      '<i class="bi bi-calendar3" aria-hidden="true"></i>';
    wrap.appendChild(button);

    /* the clock is its own control beside the date: twelve hours, ten-minute
       steps and a meridiem, which reads better than a 24-hour spinner */
    var hourPick = null;
    var minutePick = null;
    var meridiemPick = null;

    if (withTime) {
      wrap.classList.add('datefield--time');

      var time = document.createElement('div');
      time.className = 'timefield';
      time.innerHTML =
        '<select class="timefield__part timefield__hour" aria-label="Hour"></select>' +
        '<span class="timefield__colon">:</span>' +
        '<select class="timefield__part timefield__minute" aria-label="Minute"></select>' +
        '<select class="timefield__part timefield__meridiem" aria-label="AM or PM">' +
          '<option value="am">AM</option><option value="pm">PM</option>' +
        '</select>';

      hourPick = time.querySelector('.timefield__hour');
      minutePick = time.querySelector('.timefield__minute');
      meridiemPick = time.querySelector('.timefield__meridiem');

      for (var hour = 1; hour <= 12; hour++) {
        var hourOption = document.createElement('option');
        hourOption.value = String(hour);
        hourOption.textContent = pad(hour);
        hourPick.appendChild(hourOption);
      }

      for (var minute = 0; minute < 60; minute += 10) {
        var minuteOption = document.createElement('option');
        minuteOption.value = String(minute);
        minuteOption.textContent = pad(minute);
        minutePick.appendChild(minuteOption);
      }

      wrap.appendChild(time);

      time.addEventListener('change', function () {
        if (chosen) {
          var next = new Date(chosen.getTime());
          next.setHours(readHours(), readMinutes(), 0, 0);
          commit(next);
        }
      });
    }

    /** The hour the three selects add up to, in 24-hour terms. */
    function readHours() {
      var hour = Number(hourPick.value) % 12;

      return meridiemPick.value === 'pm' ? hour + 12 : hour;
    }

    function readMinutes() {
      return Number(minutePick.value);
    }

    /** Put a time on the selects, snapped to the ten-minute steps they offer. */
    function paintTime(date) {
      if (!withTime) return;

      var source = date || new Date(new Date().setHours(9, 0, 0, 0));
      var hour24 = source.getHours();

      hourPick.value = String(hour24 % 12 === 0 ? 12 : hour24 % 12);
      minutePick.value = String(Math.round(source.getMinutes() / 10) % 6 * 10);
      meridiemPick.value = hour24 >= 12 ? 'pm' : 'am';
    }

    /* the label a screen reader reads for the field belongs to the button now */
    var label = input.id ? document.querySelector('label[for="' + input.id + '"]') : null;
    if (label) {
      button.setAttribute('aria-label', label.textContent.trim());
    }

    var valueText = button.querySelector('.datefield__value');
    var panel = null;
    var view = null;          /* the month on screen */
    var chosen = parseValue(input.value);

    function paintButton() {
      if (chosen) {
        /* the time has a control of its own, so the button stays a date */
        valueText.textContent = toLabel(chosen, false);
        button.classList.remove('is-empty');
      } else {
        valueText.textContent = 'Pick a date';
        button.classList.add('is-empty');
      }
    }

    function outOfRange(date) {
      /* on a date-only field the clock does not count: today at 14:20 is still
         inside a limit of today */
      var day = withTime ? date : new Date(date.getFullYear(), date.getMonth(), date.getDate());

      if (min && day < new Date(min.getFullYear(), min.getMonth(), min.getDate())) return true;
      if (max && day > new Date(max.getFullYear(), max.getMonth(), max.getDate())) return true;

      return false;
    }

    function commit(date) {
      chosen = date;
      input.value = date ? toValue(date, withTime) : '';
      input.dispatchEvent(new Event('change', { bubbles: true }));
      paintButton();
    }

    function buildPanel() {
      panel = document.createElement('div');
      panel.className = 'datepicker';
      panel.setAttribute('role', 'dialog');
      panel.setAttribute('aria-label', 'Choose a date');

      panel.innerHTML =
        '<div class="datepicker__head">' +
          '<button type="button" class="datepicker__nav" data-move="-1" aria-label="Previous month">' +
            '<i class="bi bi-chevron-left" aria-hidden="true"></i></button>' +
          '<div class="datepicker__period">' +
            '<select class="datepicker__month" aria-label="Month"></select>' +
            '<select class="datepicker__year" aria-label="Year"></select>' +
          '</div>' +
          '<button type="button" class="datepicker__nav" data-move="1" aria-label="Next month">' +
            '<i class="bi bi-chevron-right" aria-hidden="true"></i></button>' +
        '</div>' +
        '<div class="datepicker__weekdays"></div>' +
        '<div class="datepicker__grid"></div>';

      var weekdays = panel.querySelector('.datepicker__weekdays');
      DAYS.forEach(function (day) {
        var cell = document.createElement('span');
        cell.textContent = day;
        weekdays.appendChild(cell);
      });

      var monthSelect = panel.querySelector('.datepicker__month');
      MONTHS.forEach(function (name, index) {
        var option = document.createElement('option');
        option.value = String(index);
        option.textContent = name;
        monthSelect.appendChild(option);
      });

      var yearSelect = panel.querySelector('.datepicker__year');
      var thisYear = new Date().getFullYear();
      var firstYear = min ? min.getFullYear() : thisYear - 90;
      var lastYear = max ? max.getFullYear() : thisYear + 10;

      for (var year = firstYear; year <= lastYear; year++) {
        var option = document.createElement('option');
        option.value = String(year);
        option.textContent = String(year);
        yearSelect.appendChild(option);
      }

      panel.addEventListener('click', function (e) {
        var nav = e.target.closest('[data-move]');
        if (nav) {
          view.setMonth(view.getMonth() + Number(nav.dataset.move));
          paintPanel();
          return;
        }

        var day = e.target.closest('[data-day]');
        if (day && !day.disabled) {
          var picked = new Date(view.getFullYear(), view.getMonth(), Number(day.dataset.day));

          /* the time comes from the control outside the panel */
          if (withTime) {
            picked.setHours(readHours(), readMinutes(), 0, 0);
          }

          commit(picked);
          paintPanel();
          close();
          return;
        }

      });

      panel.addEventListener('change', function (e) {
        if (e.target.matches('.datepicker__month, .datepicker__year')) {
          view = new Date(
            Number(panel.querySelector('.datepicker__year').value),
            Number(panel.querySelector('.datepicker__month').value),
            1
          );
          paintPanel();
        }

      });

      wrap.appendChild(panel);
    }

    function paintPanel() {
      panel.querySelector('.datepicker__month').value = String(view.getMonth());
      panel.querySelector('.datepicker__year').value = String(view.getFullYear());

      var grid = panel.querySelector('.datepicker__grid');
      grid.innerHTML = '';

      var first = new Date(view.getFullYear(), view.getMonth(), 1);
      /* weeks start on Monday here, and getDay() calls Sunday 0 */
      var lead = (first.getDay() + 6) % 7;
      var days = new Date(view.getFullYear(), view.getMonth() + 1, 0).getDate();
      var today = new Date();

      for (var blank = 0; blank < lead; blank++) {
        grid.appendChild(document.createElement('span'));
      }

      for (var day = 1; day <= days; day++) {
        var date = new Date(view.getFullYear(), view.getMonth(), day);
        var cell = document.createElement('button');

        cell.type = 'button';
        cell.className = 'datepicker__day';
        cell.dataset.day = String(day);
        cell.textContent = String(day);

        if (sameDay(date, today)) cell.classList.add('is-today');
        if (sameDay(date, chosen)) cell.classList.add('is-chosen');
        if (outOfRange(date)) cell.disabled = true;

        grid.appendChild(cell);
      }
    }

    function onOutside(e) {
      /* the clicked day is often gone by now — the grid repaints on selection —
         so ask the event where it travelled rather than the live DOM */
      var path = typeof e.composedPath === 'function' ? e.composedPath() : null;
      var inside = path ? path.indexOf(wrap) !== -1 : wrap.contains(e.target);

      if (!inside) close();
    }

    function onKey(e) {
      if (e.key === 'Escape' && panel && panel.classList.contains('is-open')) {
        close();
        button.focus();
      }
    }

    function open() {
      if (!panel) buildPanel();

      view = new Date((chosen || new Date()).getFullYear(), (chosen || new Date()).getMonth(), 1);
      paintPanel();

      panel.classList.add('is-open');
      button.setAttribute('aria-expanded', 'true');

      /* flip upwards when there is no room below */
      var room = window.innerHeight - button.getBoundingClientRect().bottom;
      panel.classList.toggle('is-above', room < panel.offsetHeight + 24);

      document.addEventListener('click', onOutside);
      document.addEventListener('keydown', onKey);
    }

    function close() {
      if (!panel) return;

      panel.classList.remove('is-open');
      button.setAttribute('aria-expanded', 'false');
      document.removeEventListener('click', onOutside);
      document.removeEventListener('keydown', onKey);
    }

    button.addEventListener('click', function (e) {
      e.stopPropagation();

      if (panel && panel.classList.contains('is-open')) {
        close();
      } else {
        open();
      }
    });

    /* something else filling the form in — the portal prefill, say */
    input.addEventListener('change', function () {
      if (input.value !== (chosen ? toValue(chosen, withTime) : '')) {
        chosen = parseValue(input.value);
        paintTime(chosen);
        paintButton();
      }
    });

    paintTime(chosen);
    paintButton();
  }

  function scan() {
    document.querySelectorAll('input[type="date"], input[type="datetime-local"]').forEach(enhance);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scan);
  } else {
    scan();
  }
})();
