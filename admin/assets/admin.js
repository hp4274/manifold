/* ==========================================================================
   Manifold admin — list interactions
   Delete asks for confirmation; Details rows expand in place.
   With JS off every action still posts — delete just goes through unconfirmed,
   so the server checks CSRF and the record before removing anything.
   ========================================================================== */
(function () {
  'use strict';

  /* ---------- confirm before a destructive action ----------
     The browser's own confirm box says "localhost says" above the question and
     looks like nothing else on the page, which is a poor frame for "delete this
     permanently". This asks the same question in the product's own dialog.

     Two things it has to get right that a naive version does not:

       1. The answer often rides on the button, not the form —
          <button name="action" value="delete"> — and form.submit() sends the
          form without any submitter. The clicked button's name and value are
          copied into a hidden field before submitting, or fifteen actions here
          would post with no action at all.

       2. form.submit() does not fire another submit event, so the dialog
          cannot loop. The flag is belt and braces for anything that submits
          the form another way.

     With JavaScript off the form posts as it always did, unconfirmed, and the
     server still checks CSRF and the record before removing anything. */
  /* the link itself, selected and ready, when the browser will not take it */
  function showCopyFallback(text) {
    var ask = document.createElement('div');
    ask.className = 'ask';
    ask.innerHTML =
      '<div class="ask__backdrop" data-ask-close></div>' +
      '<div class="ask__card" role="dialog" aria-modal="true" aria-labelledby="copyText">' +
        '<i class="bi bi-clipboard ask__icon" aria-hidden="true"></i>' +
        '<p class="ask__text" id="copyText">Your browser would not take this automatically. ' +
          'It is selected below — press Ctrl+C, or ⌘C on a Mac.</p>' +
        '<input class="ask__copy" type="text" readonly>' +
        '<div class="ask__actions">' +
          '<button type="button" class="btn btn--primary" data-ask-close>Done</button>' +
        '</div>' +
      '</div>';

    var field = ask.querySelector('.ask__copy');
    field.value = text;

    ask.addEventListener('click', function (e) {
      if (!e.target.closest('[data-ask-close]')) return;

      ask.classList.remove('is-open');
      document.body.classList.remove('has-ask');
      setTimeout(function () { ask.remove(); }, 200);
    });

    document.body.appendChild(ask);
    document.body.classList.add('has-ask');
    requestAnimationFrame(function () { ask.classList.add('is-open'); });

    field.focus();
    field.select();
  }

  function askDialog(message, danger, onYes) {
    var ask = document.createElement('div');
    ask.className = 'ask';
    ask.innerHTML =
      '<div class="ask__backdrop" data-ask-close></div>' +
      '<div class="ask__card" role="alertdialog" aria-modal="true" aria-labelledby="askText">' +
        '<i class="bi ' + (danger ? 'bi-exclamation-triangle' : 'bi-question-circle') +
          ' ask__icon" aria-hidden="true"></i>' +
        '<p class="ask__text" id="askText"></p>' +
        '<div class="ask__actions">' +
          '<button type="button" class="btn btn--ghost" data-ask-close>Cancel</button>' +
          '<button type="button" class="btn ' + (danger ? 'btn--danger' : 'btn--primary') +
            '" data-ask-yes>' + (danger ? 'Yes, go ahead' : 'Continue') + '</button>' +
        '</div>' +
      '</div>';

    /* the question is set as text, never as markup: it carries names and notes
       somebody typed, and those are not ours to render as HTML */
    ask.querySelector('.ask__text').textContent = message;

    var opener = document.activeElement;

    function close() {
      ask.classList.remove('is-open');
      setTimeout(function () { ask.remove(); }, 200);
      document.body.classList.remove('has-ask');
      if (opener && opener.focus) opener.focus();
    }

    ask.addEventListener('click', function (e) {
      if (e.target.closest('[data-ask-close]')) {
        close();
        return;
      }

      if (e.target.closest('[data-ask-yes]')) {
        close();
        onYes();
      }
    });

    document.addEventListener('keydown', function onKey(e) {
      if (!ask.isConnected) {
        document.removeEventListener('keydown', onKey);
        return;
      }

      if (e.key === 'Escape') close();
    });

    document.body.appendChild(ask);
    document.body.classList.add('has-ask');
    requestAnimationFrame(function () { ask.classList.add('is-open'); });

    /* the safe answer is the one under the cursor */
    ask.querySelector('[data-ask-close]').focus();
  }

  document.addEventListener('submit', function (e) {
    var form = e.target.closest('[data-confirm]');
    if (!form || form.dataset.confirmed === '1') return;

    e.preventDefault();

    /* which button was pressed, before the dialog takes the focus away */
    var submitter = e.submitter || null;

    /* red where the outcome is red: a delete button, a danger button, or a
       question that opens by naming what it is about to destroy */
    var danger = /^(delete|remove|turn down)/i.test(form.dataset.confirm)
      || !!form.querySelector('.is-delete, .btn--danger, .is-reject');

    askDialog(form.dataset.confirm, danger, function () {
      if (submitter && submitter.name) {
        var carried = document.createElement('input');
        carried.type  = 'hidden';
        carried.name  = submitter.name;
        carried.value = submitter.value;
        form.appendChild(carried);
      }

      form.dataset.confirmed = '1';
      form.submit();
    });
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

  /* Is a file being looked at? Escape has to close that first — closing the
     drawer underneath it instead would take away the decision the file was
     opened for. Declared here because the handlers below ask it. */
  function viewerOpen() {
    return !!viewer && !viewer.hidden;
  }

  /* ---------- detail slide-over ----------
     The record's markup is rendered once, hidden, below the table; opening a
     row copies it into the drawer so only one copy is ever interactive. */
  var drawer = document.getElementById('drawer');

  if (drawer) {
    var drawerBody   = document.getElementById('drawerBody');
    var drawerTitle  = document.getElementById('drawerTitle');
    var drawerMeta   = document.getElementById('drawerMeta');
    var drawerStatus = document.getElementById('drawerStatus');
    var drawerCode   = document.getElementById('drawerCode');
    var lastTrigger  = null;

    /* One record's markup, kept once it has been fetched. Opening the same row
       twice should not ask twice. */
    var drawerCache = {};

    function fillDrawer(toggle, html) {
      drawerBody.innerHTML = html;

      /* The partial wraps the record in the hidden .drawer-source element the
         inline lists relied on; fetched, that wrapper would hide the record
         inside the drawer. Lift its contents out. */
      var wrapper = drawerBody.firstElementChild;

      if (wrapper && wrapper.classList.contains('drawer-source')) {
        wrapper.removeAttribute('hidden');
        drawerBody.innerHTML = wrapper.innerHTML;
      }

      /* a trigger may ask for a tab other than the first — the pay button opens
         the same drawer straight onto Payouts */
      showTab(toggle.dataset.tabIndex || '0');

      drawer.dispatchEvent(new CustomEvent('drawer:open'));
    }

    function openDrawer(toggle) {
      var inline = document.getElementById(toggle.dataset.drawer);
      var url    = toggle.dataset.drawerUrl;

      /* nothing to show and nowhere to get it */
      if (!inline && !url) return;

      lastTrigger = toggle;

      drawerTitle.textContent  = toggle.dataset.title || 'Submission';
      drawerMeta.textContent   = toggle.dataset.meta || '';
      drawerStatus.textContent = toggle.dataset.statusLabel || '';
      drawerStatus.className   = 'pill pill--' + (toggle.dataset.status || 'new');

      /* only applications have one, so the chip stays out of the way otherwise */
      var code = toggle.dataset.code || '';
      drawerCode.textContent = code;
      drawerCode.hidden      = code === '';

      drawer.hidden = false;
      void drawer.offsetWidth;                 /* let the transition run */
      drawer.classList.add('is-open');
      document.body.classList.add('has-drawer');

      var close = drawer.querySelector('.drawer__close');
      if (close) close.focus();

      /* Rendered into the page already — the way every list used to work, and
         still the way anything that keeps its markup inline does. */
      if (inline) {
        fillDrawer(toggle, inline.innerHTML);

        return;
      }

      var key = toggle.dataset.drawer || url;

      if (drawerCache[key]) {
        fillDrawer(toggle, drawerCache[key]);

        return;
      }

      /* A list sends its rows and nothing else; the record behind one is asked
         for when somebody opens it. That is 200 KB a dashboard no longer sends
         for ten rows nobody clicked. */
      drawerBody.innerHTML = '<p class="drawer__loading">Fetching…</p>';

      fetch(url, { credentials: 'same-origin' })
        .then(function (response) {
          if (!response.ok) throw new Error('That record could not be opened.');

          return response.text();
        })
        .then(function (html) {
          drawerCache[key] = html;

          /* they may have closed it, or opened another, while this was coming */
          if (lastTrigger === toggle && !drawer.hidden) fillDrawer(toggle, html);
        })
        .catch(function () {
          drawerBody.innerHTML = '<p class="drawer__loading">That record could not be opened. '
            + 'Reload the page and try again.</p>';
        });
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
      /* any element carrying data-drawer opens one — the Details button and the
         pay icon are the same gesture with a different tab */
      var toggle = e.target.closest('[data-drawer]');

      if (toggle) {
        openDrawer(toggle);
        return;
      }

      if (e.target.closest('[data-drawer-close]')) {
        closeDrawer();
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !viewerOpen() && !drawer.hidden) closeDrawer();
    });

    /* ---------- the drawer's own tab bar ---------- */
    function showTab(wanted) {
      var tabs = drawerBody.querySelectorAll('.detail-tab');
      if (!tabs.length) return;

      Array.prototype.forEach.call(tabs, function (button) {
        var on = button.dataset.tab === wanted;
        button.classList.toggle('is-active', on);
        button.setAttribute('aria-selected', String(on));
      });

      Array.prototype.forEach.call(drawerBody.querySelectorAll('.detail-panel'), function (panel) {
        panel.classList.toggle('is-active', panel.dataset.panel === wanted);
      });

      drawerBody.scrollTop = 0;
    }

    drawerBody.addEventListener('click', function (e) {
      var tab = e.target.closest('.detail-tab');
      if (tab) {
        showTab(tab.dataset.tab);
        return;
      }

      /* a button inside a panel can send you to another one */
      var jump = e.target.closest('[data-tab-go]');
      if (jump) showTab(jump.dataset.tabGo);
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

    /* The dialog was opened by ?edit=N in the address bar. Leaving it there
       after it has been dismissed means a reload — or the back button — puts
       the same form straight back up, so the parameter goes with the dialog. */
    if (window.history && history.replaceState && /[?&]edit=/.test(location.search)) {
      var params = new URLSearchParams(location.search);
      params.delete('edit');

      var query = params.toString();
      history.replaceState({}, '', location.pathname + (query ? '?' + query : ''));
    }
  }

  document.addEventListener('click', function (e) {
    var opener = e.target.closest('[data-modal-open]');
    if (opener) {
      var target = document.getElementById(opener.dataset.modalOpen);
      if (target) {
        /* opened from one distributor's drawer: start on them, but leave the
           select free to be changed before saving */
        var dist = target.querySelector('[name="distributor_id"]');
        if (dist && opener.dataset.distId) dist.value = opener.dataset.distId;

        openModal(target);
      }
      return;
    }

    var closer = e.target.closest('[data-modal-close]');
    if (closer) closeModal(closer.closest('.modal-x'));
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape' || viewerOpen()) return;

    var open = document.querySelector('.modal-x.is-open');
    if (open) closeModal(open);
  });

  /* ---------- flash messages ----------
     What the last action did is news, not part of the page: it belongs over the
     top of it and out of the way, not pushing the table down every time
     somebody saves something. Each one is lifted out of the document on load
     and shown as a popup in the corner.

     A confirmation reads in a second and then only takes up room, so it clears
     itself after five. An error stays until it is dismissed — it is the one
     thing somebody may need to still be there when they look back.

     The markup stays a plain <p class="alert"> in every page. Without this
     script they render where they always did, which is why nothing here is
     load-bearing. */
  var toasts = document.querySelectorAll('.alert');

  if (toasts.length) {
    var stack = document.createElement('div');
    stack.className = 'toast-stack';
    /* announced, never focused: reading a table should not be interrupted */
    stack.setAttribute('aria-live', 'polite');
    document.body.appendChild(stack);

    var HOLD = 5000;                       /* long enough to read, short enough to go */

    var dismiss = function (toast) {
      if (toast.dataset.going) return;

      toast.dataset.going = '1';
      toast.classList.add('is-going');
      setTimeout(function () { toast.remove(); }, 160);
    };

    /* "Payment rejected. The applicant has been emailed." is two things: what
       happened, and what it means. The first sentence carries the message and
       anything after it becomes the quieter line underneath — so every toast
       has a headline rather than a paragraph in a box. */
    var split = function (html) {
      var plain = html.replace(/<[^>]*>/g, '');
      var stop  = plain.search(/[.!?](\s|$)/);

      if (stop < 0 || stop >= plain.length - 2) {
        return [html, ''];
      }

      /* the split has to land on the same character in the markup, so tags in
         either half stay closed — anything with markup inside keeps one line */
      if (/<[a-z]/i.test(html)) {
        return [html, ''];
      }

      return [plain.slice(0, stop + 1), plain.slice(stop + 1).trim()];
    };

    Array.prototype.forEach.call(toasts, function (alert) {
      var isError = alert.classList.contains('alert--error');
      var isWarn  = alert.classList.contains('alert--warn');
      var kind    = isError ? 'error' : (isWarn ? 'warn' : 'ok');

      var toast = document.createElement('div');
      toast.className = 'toast toast--' + kind;
      toast.setAttribute('role', kind === 'ok' ? 'status' : 'alert');

      var icon = document.createElement('span');
      icon.className = 'toast__icon';
      icon.innerHTML = '<i class="bi ' + (kind === 'ok'
        ? 'bi-check-lg'
        : (kind === 'warn' ? 'bi-exclamation-triangle' : 'bi-exclamation-octagon'))
        + '" aria-hidden="true"></i>';

      var parts = split(alert.innerHTML.trim());

      var body = document.createElement('div');
      body.className = 'toast__body';
      body.innerHTML = '<p class="toast__title">' + parts[0] + '</p>'
        + (parts[1] ? '<p class="toast__detail">' + parts[1] + '</p>' : '');

      var close = document.createElement('button');
      close.type = 'button';
      close.className = 'toast__close';
      close.setAttribute('aria-label', 'Dismiss this message');
      close.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
      close.addEventListener('click', function () { dismiss(toast); });

      toast.appendChild(icon);
      toast.appendChild(body);
      toast.appendChild(close);

      /* A confirmation goes on its own and says so: the bar runs down while it
         waits, and stops while somebody is reading or tabbing through it. An
         error stays — it is the one thing that may need to still be there. */
      if (kind === 'ok') {
        var timer = document.createElement('span');
        timer.className = 'toast__timer';
        timer.style.animationDuration = HOLD + 'ms';
        toast.appendChild(timer);

        var left  = HOLD;
        var since = Date.now();
        var clock = setTimeout(function () { dismiss(toast); }, left);

        toast.addEventListener('mouseenter', function () {
          clearTimeout(clock);
          left -= Date.now() - since;
        });

        toast.addEventListener('mouseleave', function () {
          since = Date.now();
          clock = setTimeout(function () { dismiss(toast); }, Math.max(left, 1200));
        });
      }

      stack.appendChild(toast);
      alert.remove();
    });

    /* Escape clears whatever is still on screen */
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;

      Array.prototype.forEach.call(stack.querySelectorAll('.toast'), dismiss);
    });
  }

  /* ---------- paging a table that cannot reload ----------
     The lists inside the drawer are already on the page — reloading to see
     page 2 would close the drawer, so those page in the browser. Any table
     marked data-paged="10" hides all but its own slice and grows a pager under
     itself. Server-rendered lists keep using ?page= and partials/pager.php;
     this is only for tables that have nowhere to navigate to. */
  function pageTable(table) {
    var size = parseInt(table.dataset.paged, 10) || 10;
    if (!table.tBodies.length) return;

    var rows = Array.prototype.slice.call(table.tBodies[0].rows);
    if (rows.length <= size) return;

    var pages = Math.ceil(rows.length / size);
    var bar = document.createElement('nav');
    bar.className = 'pager';
    bar.setAttribute('aria-label', 'Pages');
    table.parentNode.insertBefore(bar, table.nextSibling);

    function show(page) {
      var first = (page - 1) * size;

      rows.forEach(function (row, i) {
        row.hidden = i < first || i >= first + size;
      });

      var links = '';
      for (var n = 1; n <= pages; n++) {
        /* first, last and a window around here — the same shape as the
           server-side pager, so the two do not look like different features */
        if (n === 1 || n === pages || Math.abs(n - page) <= 1) {
          links += n === page
            ? '<span class="pager__page is-here" aria-current="page">' + n + '</span>'
            : '<button type="button" class="pager__page" data-page="' + n + '">' + n + '</button>';
        } else if (Math.abs(n - page) === 2) {
          links += '<span class="pager__gap" aria-hidden="true">…</span>';
        }
      }

      bar.innerHTML = '<span class="pager__count">' + (first + 1) + '–'
        + Math.min(first + size, rows.length) + ' of ' + rows.length + '</span>'
        + '<span class="pager__links">' + links + '</span>';
    }

    bar.addEventListener('click', function (e) {
      var hit = e.target.closest('[data-page]');
      if (hit) show(parseInt(hit.dataset.page, 10));
    });

    show(1);
  }

  /* the drawer copies its markup in fresh each time it opens, so the tables
     inside it are paged then rather than once at load */
  if (drawer) {
    drawer.addEventListener('drawer:open', function () {
      Array.prototype.forEach.call(drawerBody.querySelectorAll('[data-paged]'), pageTable);
    });
  }

  Array.prototype.forEach.call(document.querySelectorAll('.main [data-paged]'), pageTable);

  /* ---------- copy to clipboard ----------
     Anything with data-copy="text" puts that text on the clipboard and says so
     on the button itself for a moment. execCommand is the fallback: the async
     clipboard API is only available on https and on localhost. */
  document.addEventListener('click', function (e) {
    var button = e.target.closest('[data-copy]');
    if (!button) return;

    var text = button.dataset.copy;
    var said = button.innerHTML;

    /* an icon button is a 36px circle — the word "Copied" does not fit in one,
       so it gets a tick where its icon was instead */
    var done = function () {
      button.innerHTML = button.classList.contains('icon-btn')
        ? '<i class="bi bi-check-lg" aria-hidden="true"></i>'
        : 'Copied';
      setTimeout(function () { button.innerHTML = said; }, 1400);
    };

    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done);
      return;
    }

    var box = document.createElement('textarea');
    box.value = text;
    box.setAttribute('readonly', '');
    box.style.position = 'fixed';
    box.style.left = '-9999px';
    document.body.appendChild(box);
    box.select();
    try {
      document.execCommand('copy');
      done();
    } catch (err) {
      /* No clipboard and no execCommand — an old browser, or a page served over
         plain http. Rather than a browser prompt box, the link is put on screen
         already selected, so it is one keystroke away from copied. */
      showCopyFallback(text);
    }
    document.body.removeChild(box);
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

  /* ---------- click-to-cycle column headers ----------
     The header itself is the control. A filter header steps through the values
     actually present in the table — All → first → second → … → All — and two
     filter headers combine with AND. A sort header toggles high → low → off,
     and says which way it is pointing in its own label.

     Every table with class="is-filterable" gets this, so a new list does not
     need new JavaScript — only the right attributes in its markup. */
  Array.prototype.forEach.call(document.querySelectorAll('table.is-filterable'), function (table) {
    if (!table.tBodies.length) return;

    var rows    = Array.prototype.slice.call(table.tBodies[0].rows);
    var buttons = Array.prototype.slice.call(table.querySelectorAll('.th-filter'));
    var state   = {};
    var sortKey = '';
    var sortDir = '';

    /* whatever the page put there is what clearing a filter goes back to, so the
       wording lives in the markup and cannot drift from it */
    var count        = table.closest('.panel') || document;
    count            = count.querySelector('[data-table-count]');
    var countDefault = count ? count.textContent : '';

    /* the order the server sent, so switching sorting off restores it */
    var original = rows.slice();

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

    /* the label fades through as it swaps, so a changed header is noticed */
    function swap(button, text, changed) {
      var label = button.querySelector('.th-filter__label');

      if (!reduceMotion) {
        label.classList.remove('is-swapping');
        void label.offsetWidth;
        label.classList.add('is-swapping');
      }

      label.textContent = text;
      button.classList.toggle('is-filtered', changed);
    }

    function apply() {
      var shown = 0;

      rows.forEach(function (row) {
        var match = Object.keys(state).every(function (key) {
          return !state[key] || row.dataset[key] === state[key];
        });

        var wasHidden = row.hidden;
        row.hidden = !match;

        if (!match) return;

        shown++;

        /* the left-hand number counts what is on screen, not database ids */
        var seq = row.querySelector('.td-seq');
        if (seq) seq.textContent = shown;

        if (wasHidden && !reduceMotion) {
          row.classList.remove('is-in');
          void row.offsetWidth;
          row.classList.add('is-in');
        }
      });

      if (count) {
        var filtered = Object.keys(state).some(function (key) { return state[key]; });
        count.textContent = filtered
          ? shown + (shown === 1 ? ' match' : ' matches')
          : countDefault;
      }
    }

    /* sorting reorders the rows themselves, so paging and filtering see the
       new order rather than fighting it */
    function reorder() {
      var body = table.tBodies[0];

      if (sortDir === '') {
        original.forEach(function (row) { body.appendChild(row); });
        return;
      }

      rows.slice().sort(function (a, b) {
        var x = parseFloat(a.dataset[sortKey] || 0);
        var y = parseFloat(b.dataset[sortKey] || 0);

        return sortDir === 'high' ? y - x : x - y;
      }).forEach(function (row) {
        body.appendChild(row);
      });
    }

    buttons.forEach(function (button) {
      button.addEventListener('click', function () {
        var key = button.dataset.filter;

        /* ---- a sort header: high to low → low to high → off ---- */
        if (button.dataset.sort) {
          var order = ['', 'high', 'low'];
          var next  = order[(order.indexOf(sortDir) + 1) % order.length];

          sortKey = button.dataset.sort;
          sortDir = next;

          swap(button, next === 'high' ? 'High to low'
            : (next === 'low' ? 'Low to high' : button.dataset.default), next !== '');

          reorder();
          apply();

          return;
        }

        /* ---- a filter header: All → each value present → All ---- */
        var values  = [''].concat(optionsFor(key));
        var current = state[key] || '';
        var step    = values[(values.indexOf(current) + 1) % values.length];

        state[key] = step;

        swap(button, step ? labelFor(key, step) : button.dataset.default, step !== '');
        apply();
      });
    });
  });

  /* ---------- filtering without losing your place ----------
     The column headers on a paged list have to filter on the server: the page
     holds ten of two hundred and fifty rows, so hiding rows in the browser
     would filter what is on screen and quietly misreport the rest.

     That does not mean a full page load. The same URL is fetched and the one
     block that changed is swapped in, which keeps the sidebar, the scroll
     position and the drawer markup exactly where they were. The address bar is
     kept in step so reload, back and bookmarking all still work.

     Every link here is a real href, so with JavaScript off the browser simply
     follows it and the server renders the same thing. Nothing is load-bearing. */
  var liveList = document.querySelector('[data-live-list]');

  if (liveList && window.fetch && window.history && history.pushState) {
    var listSeq = 0;

    var waitTimer = null;

    /* Blocking clicks is instant; fading the list is not. A swap that comes
       back in under a fifth of a second should look like nothing happened at
       all — the fade is for the one that genuinely keeps somebody waiting, and
       showing it either way is what made a quick list look like it washed out
       and came back. */
    function busy(on) {
      liveList.classList.toggle('is-busy', on);

      if (waitTimer) {
        clearTimeout(waitTimer);
        waitTimer = null;
      }

      if (on) {
        waitTimer = setTimeout(function () {
          liveList.classList.add('is-waiting');
        }, 200);
      } else {
        liveList.classList.remove('is-waiting');
      }
    }

    function swapList(url, push) {
      var mine = ++listSeq;

      busy(true);

      fetch(url, { credentials: 'same-origin' })
        .then(function (response) { return response.text(); })
        .then(function (html) {
          /* a slower earlier request must not overwrite a newer answer */
          if (mine !== listSeq) return;

          var fresh = new DOMParser()
            .parseFromString(html, 'text/html')
            .querySelector('[data-live-list]');

          if (!fresh) {
            window.location.href = url;   /* something unexpected — just go */
            return;
          }

          liveList.innerHTML = fresh.innerHTML;
          busy(false);

          /* A list marked data-live-quiet filters without touching the address
             bar: the office asked for the page to sit still. The cost is that
             a reload or a bookmark comes back to the unfiltered list, which is
             why the other lists still keep their URL in step. */
          if (push && !liveList.hasAttribute('data-live-quiet')) {
            history.pushState({ liveList: true }, '', url);
          }
        })
        .catch(function () {
          if (mine !== listSeq) return;

          /* the network said no: fall back to what the link would have done */
          window.location.href = url;
        });
    }

    /* a picker in a header: the same swap a filter link does, only the URL is
       built from what the form holds */
    liveList.addEventListener('change', function (e) {
      var form = e.target.closest('form[data-live-form]');
      if (!form) return;

      var query = [];

      Array.prototype.forEach.call(form.elements, function (field) {
        if (field.name && field.value !== '') {
          query.push(encodeURIComponent(field.name) + '=' + encodeURIComponent(field.value));
        }
      });

      swapList(form.dataset.base + (query.length ? '?' + query.join('&') : ''), true);
    });

    liveList.addEventListener('click', function (e) {
      /* the headers, the pager, and the "show all" an empty result offers —
         every link in here that only changes which rows are listed */
      var link = e.target.closest('a.th-filter, .pager a, .empty a');

      /* let a new tab or a modified click do what it normally would */
      if (!link || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || link.target) return;

      e.preventDefault();
      swapList(link.href, true);
    });

    /* back and forward move through the filters rather than out of the page */
    window.addEventListener('popstate', function (e) {
      if (e.state && e.state.liveList) swapList(window.location.href, false);
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

    /* Clear stays a real link so it still works without this script — with it,
       emptying the box is the whole job and reloading the page for it would
       throw away everything else on screen */
    if (clear) {
      clear.addEventListener('click', function (e) {
        e.preventDefault();

        box.value = '';
        queue();
        box.focus();
      });
    }
  }

  /* ---------- looking at an uploaded file ----------
     A receipt is read next to the decision it is for, so it opens over the page
     rather than in a tab of its own — the drawer, the row and the Accept button
     are all still there behind it when it closes.

     Every link is a real href to file.php, so a middle-click, "open in new tab"
     and a browser without this script all still work; this only takes over the
     plain click. */
  var viewer = null;

  function buildViewer() {
    viewer = document.createElement('div');
    viewer.className = 'viewer';
    viewer.hidden = true;
    viewer.innerHTML =
      '<div class="viewer__backdrop" data-viewer-close></div>' +
      '<figure class="viewer__frame" role="dialog" aria-modal="true" aria-label="Uploaded file">' +
        '<figcaption class="viewer__head">' +
          '<span class="viewer__title"></span>' +
          '<span class="viewer__tools">' +
            '<a class="viewer__open" target="_blank" rel="noopener">Open in a new tab</a>' +
            '<button type="button" class="viewer__close" data-viewer-close aria-label="Close">' +
              '<i class="bi bi-x-lg" aria-hidden="true"></i></button>' +
          '</span>' +
        '</figcaption>' +
        '<div class="viewer__body"></div>' +
      '</figure>';

    document.body.appendChild(viewer);
  }

  function closeViewer() {
    if (!viewer || viewer.hidden) return;

    viewer.hidden = true;
    document.body.classList.remove('has-viewer');

    /* stop a PDF rendering behind everything else */
    viewer.querySelector('.viewer__body').innerHTML = '';
  }

  function openViewer(url, label) {
    if (!viewer) buildViewer();

    /* the file name sits in ?path=, with &dir= able to follow it */
    var isPdf = /\.pdf($|[?&])/i.test(url.split('path=')[1] || url);

    viewer.querySelector('.viewer__title').textContent = label || 'Uploaded file';
    viewer.querySelector('.viewer__open').href = url;
    viewer.querySelector('.viewer__body').innerHTML = isPdf
      ? '<iframe class="viewer__pdf" title="Uploaded file"></iframe>'
      : '<img class="viewer__img" alt="">';

    var media = viewer.querySelector('.viewer__pdf, .viewer__img');
    media.src = url;

    viewer.hidden = false;
    document.body.classList.add('has-viewer');
    viewer.querySelector('.viewer__close').focus();
  }

  document.addEventListener('click', function (e) {
    var close = e.target.closest('[data-viewer-close]');

    if (close) {
      closeViewer();
      return;
    }

    var link = e.target.closest('a[data-viewer]');

    /* a modified click means somebody wants their own tab — let them have it */
    if (!link || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

    e.preventDefault();
    openViewer(link.href, link.dataset.viewer);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeViewer();
  });

  /* ---------- what a stock order will cost ----------
     One order can be for both products, so each line shows its own figure and
     the total is their sum. The figure that counts is still the one the server
     works out — this only saves somebody adding it up before they pay it. */
  var stockRows  = document.querySelectorAll('[data-stock-qty]');
  var stockTotal = document.querySelector('[data-stock-total]');

  if (stockRows.length && stockTotal) {
    var rupees = function (amount) {
      return '₹' + amount.toLocaleString('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });
    };

    /* A quantity is a count of things, so a minus sign has no meaning here.
       The field carries min and max, but those only speak up when the form is
       submitted — and a form submitted from script never asks. Typing one is
       corrected as it happens instead. */
    var clampOrder = function (box) {
      var typed = parseInt(box.value, 10);

      if (isNaN(typed)) return;

      var most = parseInt(box.getAttribute('max'), 10);

      if (typed < 0) box.value = '0';
      else if (!isNaN(most) && typed > most) box.value = String(most);
    };

    var paintOrder = function () {
      var total = 0;

      Array.prototype.forEach.call(stockRows, function (box) {
        clampOrder(box);

        var units = Math.max(0, parseInt(box.value, 10) || 0);
        var line  = units * (parseFloat(box.dataset.price) || 0);

        total += line;

        var sum = box.closest('.order-line');
        sum = sum && sum.querySelector('[data-stock-line]');

        if (sum) sum.textContent = rupees(line);
      });

      stockTotal.textContent = rupees(total);
    };

    Array.prototype.forEach.call(stockRows, function (box) {
      box.addEventListener('input', paintOrder);
      box.addEventListener('change', paintOrder);
    });

    paintOrder();
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

  /* ---------- number fields ----------
     A wheel over a focused number field changes it, so scrolling past a form
     quietly edits a quantity somebody already set. The page scrolls instead. */
  document.addEventListener('wheel', function (e) {
    var field = e.target;

    if (!field.matches || !field.matches('input[type="number"]')) return;
    if (document.activeElement !== field) return;

    field.blur();
  }, { passive: true });

  /* ---------- an email box takes no spaces ----------
     The space key does nothing in an email field, so there is never a space to
     take back out. Paste is cleaned on arrival, since that is the other way
     whitespace gets in. */
  function isEmailBox(el) {
    if (!el || el.tagName !== 'INPUT') return false;

    return el.type === 'email' || /email/i.test((el.getAttribute('name') || '') + ' ' + (el.id || ''));
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === ' ' && isEmailBox(e.target)) e.preventDefault();
  });

  document.addEventListener('input', function (e) {
    if (!isEmailBox(e.target)) return;

    var clean = e.target.value.replace(/\s+/g, '');
    if (clean !== e.target.value) e.target.value = clean;
  });

  /* The box reads lower case as it is typed (CSS), and the value itself is put
     into lower case on the way out of the field - doing it on every keystroke
     would drag the caret to the end of an input[type=email], which cannot be
     put back. */
  document.addEventListener('change', function (e) {
    if (!isEmailBox(e.target)) return;

    e.target.value = e.target.value.toLowerCase();
  });

  /* ---------- a mobile box takes digits ----------
     A letter in a phone number is never meant, so the key does nothing rather
     than being typed and then complained about. Paste is cleaned on arrival. */
  function isPhoneBox(el) {
    if (!el || el.tagName !== 'INPUT') return false;

    return el.type === 'tel'
      || /mobile|phone/i.test((el.getAttribute('name') || '') + ' ' + (el.id || ''));
  }

  document.addEventListener('keydown', function (e) {
    if (!isPhoneBox(e.target)) return;
    /* shortcuts, arrows, backspace and tab all report more than one character */
    if (e.ctrlKey || e.metaKey || e.altKey || e.key.length !== 1) return;

    if (!/[0-9]/.test(e.key)) e.preventDefault();
  });

  document.addEventListener('input', function (e) {
    if (!isPhoneBox(e.target)) return;

    var digitsOnly = e.target.value.replace(/[^0-9]/g, '');
    if (digitsOnly !== e.target.value) e.target.value = digitsOnly;
  });

  /* ---------- a wrong answer is marked on the field ----------
     The browser's own bubble points at one field, disappears on the next click
     and reads like a system message. The same red border and line of text the
     public forms use says it in place, and stays until it is right. */
  function fieldBox(el) {
    return el.closest('.field') || el.closest('.field-consent') || el.closest('.order-line');
  }

  function gradable(el) {
    return el.willValidate && el.type !== 'hidden' && el.type !== 'submit';
  }

  function fieldWhy(el) {
    var v = el.validity;

    if (v.valueMissing) {
      return el.type === 'checkbox' ? 'Please tick this to continue.' : 'This one is needed.';
    }
    if (v.rangeUnderflow || v.rangeOverflow) {
      return el.title || 'That is outside the range we can accept.';
    }
    if (v.typeMismatch || v.patternMismatch || v.tooShort || v.tooLong) {
      return el.title || 'That does not look right yet.';
    }

    return el.validationMessage || 'Please check this one.';
  }

  function gradeField(el) {
    if (!gradable(el)) return true;

    var box = fieldBox(el);
    /* validity.valid, not checkValidity(): the call fires an invalid event of
       its own, and the listener below grades the field again - straight into a
       stack overflow */
    var ok  = el.validity.valid;

    if (box) {
      box.classList.toggle('field--error', !ok);

      var note = box.querySelector('[data-live-error]');

      if (ok) {
        if (note) note.remove();
      } else {
        if (!note) {
          note = document.createElement('span');
          note.className = 'field-error';
          note.setAttribute('data-live-error', '');
          note.setAttribute('role', 'alert');
          note.innerHTML = '<i class="bi bi-exclamation-circle" aria-hidden="true"></i><span></span>';
          box.appendChild(note);
        }

        note.lastChild.textContent = fieldWhy(el);
      }
    }

    ok ? el.removeAttribute('aria-invalid') : el.setAttribute('aria-invalid', 'true');

    return ok;
  }

  /* judged when the field is left - blur does not bubble, so this listens on
     the way down - and re-judged on every keystroke once it has been marked */
  document.addEventListener('blur', function (e) {
    if (!gradable(e.target)) return;

    e.target.dataset.touched = '1';
    gradeField(e.target);
  }, true);

  document.addEventListener('input', function (e) {
    if (!gradable(e.target)) return;

    var full = e.target.maxLength > 0 && String(e.target.value).length >= e.target.maxLength;

    if (e.target.dataset.touched || full) gradeField(e.target);
  });

  document.addEventListener('change', function (e) {
    if (!gradable(e.target)) return;

    e.target.dataset.touched = '1';
    gradeField(e.target);
  });

  /* The browser fires this at every field it will not accept, one after the
     other, and the bubble it wants to show is cancelled here. Works for a form
     that arrived with the page and for one the drawer fetched later. */
  document.addEventListener('invalid', function (e) {
    e.preventDefault();

    var el = e.target;
    el.dataset.touched = '1';
    gradeField(el);

    /* the first one to complain is the one to go to */
    var form = el.form;
    if (form && form.dataset.grading === '1') return;

    if (form) {
      form.dataset.grading = '1';
      setTimeout(function () { delete form.dataset.grading; }, 0);
    }

    el.focus();
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }, true);

})();
