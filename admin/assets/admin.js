/* ==========================================================================
   Manifold admin — list interactions
   Delete asks for confirmation; Details rows expand in place.
   With JS off every action still posts — delete just goes through unconfirmed,
   so the server checks CSRF and the record before removing anything.
   ========================================================================== */
(function () {
  'use strict';

  /* ---------- confirm before a destructive action ---------- */
  document.addEventListener('submit', function (e) {
    var form = e.target.closest('[data-confirm]');
    if (!form) return;

    if (!window.confirm(form.dataset.confirm)) {
      e.preventDefault();
    }
  });

  /* ---------- a switch that applies itself ----------
     A checkbox inside a form only takes effect when something is submitted,
     which makes a toggle feel broken. Any form marked data-autosubmit posts as
     soon as one of its controls changes. There is no button to press here — the
     one in the markup sits inside a <noscript>, for a browser without this
     script — so the form dims itself instead, and the page it reloads reports
     what happened. */
  document.addEventListener('change', function (e) {
    var form = e.target.closest('form[data-autosubmit]');

    if (!form) return;

    form.classList.add('is-saving');
    form.submit();
  });

  /* ---------- detail slide-over ----------
     The record's markup is rendered once, hidden, below the table; opening a
     row copies it into the drawer so only one copy is ever interactive. */
  var drawer = document.getElementById('drawer');

  if (drawer) {
    var drawerBody   = document.getElementById('drawerBody');
    var drawerTitle  = document.getElementById('drawerTitle');
    var drawerMeta   = document.getElementById('drawerMeta');
    var drawerStatus = document.getElementById('drawerStatus');
    var lastTrigger  = null;

    function openDrawer(toggle) {
      var source = document.getElementById(toggle.dataset.drawer);
      if (!source) return;

      lastTrigger = toggle;

      drawerTitle.textContent = toggle.dataset.title || 'Submission';
      drawerMeta.textContent  = toggle.dataset.meta || '';
      drawerStatus.textContent = toggle.dataset.statusLabel || '';
      drawerStatus.className   = 'pill pill--' + (toggle.dataset.status || 'new');

      drawerBody.innerHTML = source.innerHTML;

      drawer.hidden = false;
      void drawer.offsetWidth;                 /* let the transition run */
      drawer.classList.add('is-open');
      document.body.classList.add('has-drawer');

      var close = drawer.querySelector('.drawer__close');
      if (close) close.focus();
    }

    function closeDrawer() {
      drawer.classList.remove('is-open');
      document.body.classList.remove('has-drawer');

      window.setTimeout(function () {
        drawer.hidden = true;
        drawerBody.innerHTML = '';
      }, 260);

      if (lastTrigger) lastTrigger.focus();
    }

    document.addEventListener('click', function (e) {
      var toggle = e.target.closest('.row-toggle');

      if (toggle) {
        openDrawer(toggle);
        return;
      }

      if (e.target.closest('[data-drawer-close]')) {
        closeDrawer();
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !drawer.hidden) closeDrawer();
    });

    /* ---------- the drawer's own tab bar ---------- */
    drawerBody.addEventListener('click', function (e) {
      var tab = e.target.closest('.detail-tab');
      if (!tab) return;

      var wanted = tab.dataset.tab;

      Array.prototype.forEach.call(drawerBody.querySelectorAll('.detail-tab'), function (button) {
        var on = button === tab;
        button.classList.toggle('is-active', on);
        button.setAttribute('aria-selected', String(on));
      });

      Array.prototype.forEach.call(drawerBody.querySelectorAll('.detail-panel'), function (panel) {
        panel.classList.toggle('is-active', panel.dataset.panel === wanted);
      });

      drawerBody.scrollTop = 0;
    });
  }

  /* ---------- dialogs ----------
     Anything with data-modal-open="id" opens that dialog; the backdrop, the
     cross and Escape all close it. */
  function openModal(modal) {
    modal.classList.add('is-open');
    document.body.classList.add('has-modal');

    var first = modal.querySelector('input:not([type="hidden"]), select, textarea');
    if (first) first.focus();
  }

  function closeModal(modal) {
    modal.classList.remove('is-open');
    document.body.classList.remove('has-modal');
  }

  document.addEventListener('click', function (e) {
    var opener = e.target.closest('[data-modal-open]');
    if (opener) {
      var target = document.getElementById(opener.dataset.modalOpen);
      if (target) openModal(target);
      return;
    }

    var closer = e.target.closest('[data-modal-close]');
    if (closer) closeModal(closer.closest('.modal-x'));
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;

    var open = document.querySelector('.modal-x.is-open');
    if (open) closeModal(open);
  });

  /* a dialog rendered open (editing an account) still locks the page behind it */
  if (document.querySelector('.modal-x.is-open')) document.body.classList.add('has-modal');

  /* ---------- blog: the schedule date belongs to one status ----------
     A draft or a published post has nothing to say about a future moment, so
     the field only appears once Scheduled is chosen. */
  var statusPick = document.getElementById('status');
  var scheduleField = document.getElementById('publish_at');

  if (statusPick && scheduleField) {
    var scheduleRow = scheduleField.closest('.field');

    var showSchedule = function () {
      var scheduling = statusPick.value === 'scheduled';

      scheduleRow.hidden = !scheduling;

      if (scheduling) {
        scheduleField.setAttribute('required', 'required');
      } else {
        scheduleField.removeAttribute('required');
      }
    };

    statusPick.addEventListener('change', showSchedule);
    showSchedule();
  }

  /* ---------- click-to-cycle column filters ----------
     Each header button steps through the values actually present in the
     table — All → first → second → … → All. Two headers combine with AND. */
  var table = document.getElementById('latestTable');

  if (table) {
    var rows    = Array.prototype.slice.call(table.tBodies[0].rows);
    var buttons = Array.prototype.slice.call(table.querySelectorAll('.th-filter'));
    var state   = {};

    /* whatever the page put there is what clearing a filter goes back to, so the
       wording lives in the markup and cannot drift from it */
    var count        = document.querySelector('[data-table-count]');
    var countDefault = count ? count.textContent : '';

    /* distinct values per filter, in the order they appear */
    function optionsFor(key) {
      var seen = [];

      rows.forEach(function (row) {
        var value = row.dataset[key];
        if (value && seen.indexOf(value) === -1) seen.push(value);
      });

      return seen;
    }

    function labelFor(key, value) {
      for (var i = 0; i < rows.length; i++) {
        if (rows[i].dataset[key] === value) {
          return rows[i].dataset[key + 'Label'] || value;
        }
      }

      return value;
    }

    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function apply() {
      var shown = 0;

      rows.forEach(function (row) {
        var match = Object.keys(state).every(function (key) {
          return !state[key] || row.dataset[key] === state[key];
        });

        var wasHidden = row.hidden;
        row.hidden = !match;

        if (!match) return;

        /* the left-hand number counts what is on screen, not database ids */
        var seq = row.querySelector('[data-seq]');
        if (seq) seq.textContent = String(shown + 1);

        /* only animate rows that have just appeared, and stagger the first few */
        if (!reduceMotion && wasHidden) {
          row.classList.remove('is-in');
          void row.offsetWidth;                 /* restart the animation */
          row.style.animationDelay = Math.min(shown, 6) * 35 + 'ms';
          row.classList.add('is-in');
        }

        shown++;
      });

      var empty = document.querySelector('[data-table-empty]');
      if (empty) empty.hidden = shown > 0;

      if (count) {
        var active = Object.keys(state).filter(function (k) { return state[k]; });
        count.textContent = active.length
          ? shown + (shown === 1 ? ' match' : ' matches')
          : countDefault;
      }
    }

    buttons.forEach(function (button) {
      var key = button.dataset.filter;
      state[key] = '';

      button.addEventListener('click', function () {
        var options = optionsFor(key);
        var index   = options.indexOf(state[key]);   /* -1 while unfiltered */
        var next    = index + 1 >= options.length ? '' : options[index + 1];

        state[key] = next;

        /* the header itself becomes the filter — no extra chip */
        var label = button.querySelector('.th-filter__label');

        label.classList.remove('is-swapping');
        void label.offsetWidth;
        label.classList.add('is-swapping');

        label.textContent = next ? labelFor(key, next) : button.dataset.default;
        button.classList.toggle('is-filtered', next !== '');

        apply();
      });
    });
  }

  /* ---------- live search ----------
     Anything with data-finder answers as it is typed: the endpoint returns the
     same markup the page rendered, so the block is swapped whole rather than
     rebuilt here. A quarter of a second of quiet before asking keeps it to one
     request per word, and an older answer arriving late is thrown away.

     Without JS the form still posts as a plain GET and the server renders the
     same list, so nothing here is load-bearing. */
  var finder = document.querySelector('[data-finder]');
  var target = document.getElementById('raffleResults');

  if (finder && target && window.fetch) {
    var box      = finder.querySelector('input[type="search"]');
    var clear    = finder.querySelector('.finder__clear');
    var endpoint = finder.dataset.endpoint;
    var draw     = finder.dataset.draw;
    var timer    = null;
    var seq      = 0;
    var shown    = box.value.trim();

    function paint(query) {
      /* the page already shows this, so asking again would only flicker */
      if (query === shown) return;

      var mine = ++seq;

      finder.classList.add('is-busy');

      fetch(endpoint + '?q=' + encodeURIComponent(query) + '&draw=' + encodeURIComponent(draw),
            { credentials: 'same-origin' })
        .then(function (response) { return response.text(); })
        .then(function (html) {
          /* a slower earlier request must not overwrite a newer answer */
          if (mine !== seq) return;

          shown = query;
          target.innerHTML = html;
          finder.classList.remove('is-busy');
        })
        .catch(function () {
          if (mine !== seq) return;

          finder.classList.remove('is-busy');
        });
    }

    function queue() {
      var query = box.value.trim();

      if (clear) clear.hidden = query === '';

      if (timer) clearTimeout(timer);

      /* an empty box needs no request — the server would say the same thing */
      if (query === '') {
        seq++;
        shown = '';
        target.innerHTML = '<p class="finder__none finder__none--idle">Start typing to find somebody.</p>';
        finder.classList.remove('is-busy');
        return;
      }

      timer = setTimeout(function () { paint(query); }, 250);
    }

    box.addEventListener('input', queue);
    /* the × inside a search box fires search, not input, in some browsers */
    box.addEventListener('search', queue);

    /* Enter would reload the page for a list that is already on screen */
    finder.addEventListener('submit', function (e) {
      e.preventDefault();

      if (timer) clearTimeout(timer);

      paint(box.value.trim());
    });
  }

  /* ---------- countdowns ----------
     Anything with data-countdown="<ISO timestamp>" counts down to it, once a
     second. Zero is not a special case worth a reload: the page says what
     happens next either way, so it just stops at "now". */
  var clocks = document.querySelectorAll('[data-countdown]');

  if (clocks.length) {
    function spell(ms) {
      if (ms <= 0) return 'now';

      var total   = Math.floor(ms / 1000);
      var days    = Math.floor(total / 86400);
      var hours   = Math.floor((total % 86400) / 3600);
      var minutes = Math.floor((total % 3600) / 60);
      var seconds = total % 60;

      function pad(n) { return (n < 10 ? '0' : '') + n; }

      if (days > 0) return days + 'd ' + pad(hours) + 'h ' + pad(minutes) + 'm';

      return pad(hours) + ':' + pad(minutes) + ':' + pad(seconds);
    }

    function tickClocks() {
      var now = Date.now();

      clocks.forEach(function (clock) {
        var target = Date.parse(clock.dataset.countdown);

        if (isNaN(target)) return;

        clock.textContent = spell(target - now);
      });
    }

    tickClocks();
    setInterval(tickClocks, 1000);
  }

  document.documentElement.classList.add('js');
})();
