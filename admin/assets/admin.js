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

      var count = document.querySelector('[data-table-count]');
      if (count) {
        var active = Object.keys(state).filter(function (k) { return state[k]; });
        count.textContent = active.length
          ? shown + (shown === 1 ? ' match' : ' matches')
          : 'Across all forms';
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

  document.documentElement.classList.add('js');
})();
