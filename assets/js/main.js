/* ==========================================================================
   Manifold Clean Energy — main.js
   Mobile menu · sticky header · scroll reveal · active section tracking
   ========================================================================== */
(function () {
  'use strict';

  var header = document.getElementById('siteHeader');
  var nav    = document.getElementById('mainNav');
  var toggle = document.getElementById('navToggle');

  /* ---------- Mobile menu ---------- */
  function closeMenu() {
    nav.classList.remove('is-open');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-label', 'Open menu');
    nav.querySelectorAll('.nav-dropdown[open]').forEach(function (details) {
      details.removeAttribute('open');
    });
  }

  if (toggle) {
    toggle.addEventListener('click', function () {
      var open = nav.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', String(open));
      toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    });

    nav.addEventListener('click', function (e) {
      if (e.target.tagName === 'A') closeMenu();
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeMenu();
    });

    window.addEventListener('resize', function () {
      if (window.innerWidth > 991) closeMenu();
    });
  }

  /* ---------- Sticky header state ---------- */
  var toTop = document.getElementById('toTop');

  function onScroll() {
    if (header) header.classList.toggle('is-stuck', window.scrollY > 40);
    if (toTop) toTop.classList.toggle('is-visible', window.scrollY > 600);
  }
  onScroll();
  window.addEventListener('scroll', onScroll, { passive: true });

  if (toTop) {
    toTop.addEventListener('click', function () {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  }

  /* ---------- Scroll reveal ---------- */
  var revealItems = document.querySelectorAll('.reveal');

  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        var el = entry.target;
        var siblings = Array.prototype.slice.call(el.parentElement.children);
        el.style.transitionDelay = Math.min(siblings.indexOf(el), 5) * 90 + 'ms';
        el.classList.add('is-visible');
        io.unobserve(el);
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -60px 0px' });

    revealItems.forEach(function (el) { io.observe(el); });
  } else {
    revealItems.forEach(function (el) { el.classList.add('is-visible'); });
  }

  /* ---------- Form result toast ----------
     admin/submit.php redirects back with ?sent=1 or ?error=1 after a plain
     (non-fetch) form post — the contact form and the footer newsletter box. */
  var params = new URLSearchParams(window.location.search);

  if (params.has('sent') || params.has('error')) {
    var ok = params.has('sent');
    var toast = document.createElement('div');

    toast.className = 'form-toast' + (ok ? '' : ' form-toast--error');
    toast.setAttribute('role', 'status');
    toast.textContent = ok
      ? 'Thank you — we have your details and will be in touch.'
      : 'That did not go through. Please try again or call +91 97251 54186.';

    document.body.appendChild(toast);
    requestAnimationFrame(function () { toast.classList.add('is-visible'); });

    setTimeout(function () { toast.classList.remove('is-visible'); }, 7000);

    if (window.history && window.history.replaceState) {
      params.delete('sent');
      params.delete('error');
      var rest = params.toString();
      window.history.replaceState(null, '', window.location.pathname + (rest ? '?' + rest : ''));
    }
  }

  /* ---------- Our journey: wheel scrolling on mobile ----------
     Rows fade and shrink with their distance from the middle of the strip, so
     the year the reader is on sits proud and the others roll away. */
  var journey = document.querySelector('.timeline-scroll');

  if (journey && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    var wheelItems = Array.prototype.slice.call(journey.querySelectorAll('.timeline-item'));
    var isPhone = window.matchMedia('(max-width: 991px)');
    var ticking = false;

    function paintWheel() {
      ticking = false;

      if (!isPhone.matches) {
        wheelItems.forEach(function (item) {
          item.removeAttribute('data-wheel');
          item.style.removeProperty('--wheel-o');
          item.style.removeProperty('--wheel-s');
        });
        return;
      }

      var box = journey.getBoundingClientRect();
      var middle = box.top + box.height / 2;

      wheelItems.forEach(function (item) {
        var rect = item.getBoundingClientRect();
        var offset = Math.abs(rect.top + rect.height / 2 - middle);
        /* 0 in the middle, 1 at the edge of the strip */
        var away = Math.min(offset / (box.height / 2), 1);

        item.dataset.wheel = '';
        item.style.setProperty('--wheel-o', String(1 - away * 0.72));
        item.style.setProperty('--wheel-s', String(1 - away * 0.12));
      });
    }

    function queueWheel() {
      if (ticking) return;
      ticking = true;
      window.requestAnimationFrame(paintWheel);
    }

    journey.addEventListener('scroll', queueWheel, { passive: true });
    window.addEventListener('resize', queueWheel);
    queueWheel();

    /* start on the newest milestone rather than the oldest */
    if (isPhone.matches) {
      var current = journey.querySelector('.timeline-item--now');
      if (current) {
        journey.scrollTop = current.offsetTop - (journey.clientHeight - current.offsetHeight) / 2;
        queueWheel();
      }
    }
  }

  /* ---------- Referral code from the link ----------
     A shared link looks like apply-stove.html?ref=MFAB3K7P. Drop the code into
     the box so the applicant does not have to type it, and highlight it so the
     discount is visible before they submit. */
  var referralField = document.getElementById('referral_code');
  var sharedCode = (params.get('ref') || '').toUpperCase().replace(/[^A-Z0-9]/g, '');

  if (referralField && sharedCode) {
    referralField.value = sharedCode.slice(0, 20);
    referralField.classList.add('is-prefilled');
  }

  if (referralField) {
    referralField.addEventListener('input', function () {
      referralField.value = referralField.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    });
  }

  /* ---------- Signed-in account menu ----------
     The public pages are static HTML, so the PHP session is invisible to them.
     portal/session.php reports who is signed in and the Login button becomes a
     name with a dropdown. Without JS the button stays as Login, which still
     lands on the right page — portal/index.php forwards a live session to the
     status page. */
  /* /portal/ pages sit one level down; work the root out of a link the page
     already carries rather than guessing */
  var rootLink = document.querySelector('link[href*="assets/vendor/"]');
  var siteRoot = rootLink ? rootLink.getAttribute('href').split('assets/vendor/')[0] : '';

  /* which page this is, used by the apply-form prefill and the promo popup */
  var page = (window.location.pathname.split('/').pop() || 'index.html').toLowerCase();

  /* asked for once and shared — the header menu and the promo popup both
     need to know who is signed in */
  var sessionAsk = fetch(siteRoot + 'portal/session.php', { credentials: 'same-origin' })
    .then(function (response) { return response.ok ? response.json() : null; })
    .catch(function () { return null; });

  var loginLinks = document.querySelectorAll('.nav-login, .nav-login-mobile');

  if (loginLinks.length) {
    sessionAsk
      .then(function (session) {
        if (!session || !session.signedIn) return;

        loginLinks.forEach(function (link, index) {
          var mobile = link.classList.contains('nav-login-mobile');
          var menuId = 'accountMenu' + index;

          var account = document.createElement('div');
          account.className = 'nav-account' + (mobile ? ' nav-account--mobile' : '');
          account.innerHTML =
            '<button type="button" class="nav-account__button" aria-expanded="false" aria-controls="' + menuId + '">' +
              '<span class="nav-account__avatar" aria-hidden="true">' +
                (session.first || '?').charAt(0).toUpperCase() +
              '</span>' +
              '<span class="nav-account__name"></span>' +
              '<i class="bi bi-chevron-down" aria-hidden="true"></i>' +
            '</button>' +
            '<div class="nav-account__menu" id="' + menuId + '" hidden>' +
              '<p class="nav-account__who"><strong></strong><span></span></p>' +
              '<a href="' + siteRoot + 'portal/status.php"><i class="bi bi-clipboard-check" aria-hidden="true"></i> View status</a>' +
              (session.canRefer
                ? '<a href="' + siteRoot + 'portal/status.php#referral"><i class="bi bi-people" aria-hidden="true"></i> Refer someone</a>'
                : '') +
              '<a class="nav-account__out" href="' + siteRoot + 'portal/logout.php"><i class="bi bi-box-arrow-right" aria-hidden="true"></i> Sign out</a>' +
            '</div>';

          /* names go in as text, never as markup */
          account.querySelector('.nav-account__name').textContent = session.first || 'My account';
          account.querySelector('.nav-account__who strong').textContent = session.name || '';
          account.querySelector('.nav-account__who span').textContent = session.email || '';

          var button = account.querySelector('.nav-account__button');
          var menu   = account.querySelector('.nav-account__menu');

          function setOpen(open) {
            menu.hidden = !open;
            account.classList.toggle('is-open', open);
            button.setAttribute('aria-expanded', String(open));
          }

          button.addEventListener('click', function (e) {
            e.stopPropagation();
            setOpen(menu.hidden);
          });

          document.addEventListener('click', function (e) {
            if (!account.contains(e.target)) setOpen(false);
          });

          document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') setOpen(false);
          });

          link.replaceWith(account);
        });
      });
  }

  /* ---------- Copy to clipboard ----------
     Referral code and share links in the portal. Falls back to selecting a
     throwaway input where the async clipboard API is not allowed. */
  document.addEventListener('click', function (e) {
    var trigger = e.target.closest('[data-copy]');
    if (!trigger) return;

    var value = trigger.getAttribute('data-copy');
    var done = function () {
      var original = trigger.innerHTML;
      trigger.classList.add('is-copied');
      trigger.innerHTML = '<i class="bi bi-check-lg" aria-hidden="true"></i> Copied';
      setTimeout(function () {
        trigger.classList.remove('is-copied');
        trigger.innerHTML = original;
      }, 1800);
    };

    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(value).then(done, function () { window.prompt('Copy this:', value); });
      return;
    }

    var scratch = document.createElement('input');
    scratch.value = value;
    scratch.setAttribute('readonly', '');
    scratch.style.position = 'fixed';
    scratch.style.opacity = '0';
    document.body.appendChild(scratch);
    scratch.select();

    try { document.execCommand('copy'); done(); } catch (err) { window.prompt('Copy this:', value); }

    scratch.remove();
  });

  /* ---------- Cookie gate ----------
     The top bar's twin along the bottom edge, over a scrim that holds the page
     until Accept or Decline is chosen — there is deliberately no way to
     dismiss it otherwise. Built here rather than in the markup so every page
     picks it up from one place, and the answer is kept in localStorage so it
     is asked once. */
  var COOKIE_KEY = 'manifold-cookie-consent';
  var consentWaiting = [];

  /* run something now if the choice is already made, otherwise the moment it is */
  function afterConsent(callback) {
    if (storedConsent()) {
      callback();
      return;
    }

    consentWaiting.push(callback);
  }

  function storedConsent() {
    try {
      return window.localStorage.getItem(COOKIE_KEY);
    } catch (err) {
      /* private mode or storage disabled — show the bar, store nothing */
      return null;
    }
  }

  function storeConsent(value) {
    try {
      window.localStorage.setItem(COOKIE_KEY, value);
    } catch (err) { /* nothing we can do, the bar just returns next visit */ }
  }

  if (!storedConsent()) {
    /* /portal/*.php sits one level down, so work the site root out of a link
       the page already carries rather than guessing at the path */
    var vendorLink = document.querySelector('link[href*="assets/vendor/"]');
    var root = vendorLink ? vendorLink.getAttribute('href').split('assets/vendor/')[0] : '';

    var bar = document.createElement('aside');
    bar.className = 'cookie-bar';
    bar.setAttribute('role', 'region');
    bar.setAttribute('aria-label', 'Cookie notice');
    bar.innerHTML =
      '<div class="container-x cookie-bar__inner">' +
        '<p class="cookie-bar__text">' +
          '<svg class="cookie-bar__icon" viewBox="0 -960 960 960" fill="currentColor" aria-hidden="true">' +
            '<path d="M480-80q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-75 29-147t81-128.5q52-56.5 125-91T475-881q21 0 43 2t45 7q-9 45 6 85t45 66.5q30 26.5 71.5 36.5t85.5-5q-26 59 7.5 113t99.5 56q1 11 1.5 20.5t.5 20.5q0 82-31.5 154.5t-85.5 127q-54 54.5-127 86T480-80Zm-60-480q25 0 42.5-17.5T480-620q0-25-17.5-42.5T420-680q-25 0-42.5 17.5T360-620q0 25 17.5 42.5T420-560Zm-80 200q25 0 42.5-17.5T400-420q0-25-17.5-42.5T340-480q-25 0-42.5 17.5T280-420q0 25 17.5 42.5T340-360Zm260 40q17 0 28.5-11.5T640-360q0-17-11.5-28.5T600-400q-17 0-28.5 11.5T560-360q0 17 11.5 28.5T600-320ZM480-160q122 0 216.5-84T800-458q-50-22-78.5-60T683-603q-77-11-132-66t-68-132q-80-2-140.5 29t-101 79.5Q201-644 180.5-587T160-480q0 133 93.5 226.5T480-160Z"/>' +
          '</svg>' +
          'We use cookies to keep the site working and to understand how it is used. ' +
          'Read the <a href="' + root + 'privacy-policy.html">privacy policy</a> for the detail.' +
        '</p>' +
        '<div class="cookie-bar__actions">' +
          '<button type="button" class="cookie-btn cookie-btn--decline" data-consent="declined">Decline</button>' +
          '<button type="button" class="cookie-btn cookie-btn--accept" data-consent="accepted">Accept</button>' +
        '</div>' +
      '</div>';

    var scrim = document.createElement('div');
    scrim.className = 'cookie-scrim';

    document.body.appendChild(scrim);
    document.body.appendChild(bar);
    document.body.classList.add('has-cookie-bar', 'is-cookie-gated');
    document.documentElement.style.setProperty('--cookie-h', bar.offsetHeight + 'px');
    requestAnimationFrame(function () {
      bar.classList.add('is-visible');
      scrim.classList.add('is-visible');
    });

    /* the choice is the only thing on the page that can be reached */
    bar.querySelector('.cookie-btn--accept').focus();

    function trapFocus(e) {
      if (e.key !== 'Tab' || !bar.isConnected) return;

      var buttons = bar.querySelectorAll('.cookie-btn, .cookie-bar__text a');
      var first = buttons[0];
      var last = buttons[buttons.length - 1];

      if (!bar.contains(document.activeElement)) {
        e.preventDefault();
        first.focus();
      } else if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    }

    document.addEventListener('keydown', trapFocus);

    window.addEventListener('resize', function () {
      if (bar.isConnected) {
        document.documentElement.style.setProperty('--cookie-h', bar.offsetHeight + 'px');
      }
    });

    bar.addEventListener('click', function (e) {
      var button = e.target.closest('[data-consent]');
      if (!button) return;

      storeConsent(button.dataset.consent);
      bar.classList.remove('is-visible');
      scrim.classList.remove('is-visible');
      document.body.classList.remove('has-cookie-bar', 'is-cookie-gated');
      document.removeEventListener('keydown', trapFocus);

      setTimeout(function () {
        bar.remove();
        scrim.remove();
      }, 500);

      /* whatever was waiting on an answer — the welcome popup — can go now */
      consentWaiting.splice(0).forEach(function (waiting) { waiting(); });
    });
  }

  /* ---------- Apply form: fill in what we already know ----------
     Somebody applying for a second product has told us their name, address and
     ID once already. The form is static HTML, so portal/prefill.php hands over
     their own details and they are dropped into the empty fields — never over
     anything already typed, and never the referral code. */
  var applyForm = document.getElementById('applyForm');

  if (applyForm && page.indexOf('apply-') === 0) {
    fetch(siteRoot + 'portal/prefill.php', { credentials: 'same-origin' })
      .then(function (response) { return response.ok ? response.json() : null; })
      .then(function (data) {
        if (!data || !data.signedIn || !data.fields) return;

        var filled = [];

        Object.keys(data.fields).forEach(function (name) {
          var field = applyForm.querySelector('[name="' + name + '"]');
          if (!field || field.value !== '') return;

          var value = data.fields[name];

          /* a select can only take a value it actually offers */
          if (field.tagName === 'SELECT') {
            var match = Array.prototype.slice.call(field.options).some(function (option) {
              return option.value === value;
            });
            if (!match) return;
          }

          field.value = value;
          field.classList.add('is-prefilled');
          filled.push(field);
        });

        if (!filled.length) return;

        var note = document.createElement('p');
        note.className = 'form-prefill';
        note.innerHTML =
          '<i class="bi bi-person-check" aria-hidden="true"></i>' +
          '<span></span>' +
          '<button type="button" class="form-prefill__clear">Clear and start blank</button>';
        note.querySelector('span').textContent =
          'Filled in from your application ' + (data.from || '') + '. Check every box before sending — '
          + 'anything that has changed can be edited.';

        note.querySelector('.form-prefill__clear').addEventListener('click', function () {
          filled.forEach(function (field) {
            field.value = '';
            field.classList.remove('is-prefilled');
          });
          note.remove();
        });

        applyForm.insertBefore(note, applyForm.firstChild);
      })
      .catch(function () { /* signed out or offline — the form stays empty */ });
  }

  /* ---------- Welcome popup ----------
     A short introduction to the company and the two products, shown on every
     visit to the home page. Anything with data-promo-open reopens it. */
  function openPromo() {
    if (document.querySelector('.promo')) return;

    var promo = document.createElement('div');
    promo.className = 'promo';
    promo.innerHTML =
      '<div class="promo__backdrop" data-promo-close></div>' +
      '<div class="promo__card" role="dialog" aria-modal="true" aria-labelledby="promoTitle">' +
        '<button type="button" class="promo__close" data-promo-close aria-label="Close">' +
          '<i class="bi bi-x-lg" aria-hidden="true"></i>' +
        '</button>' +
        '<h2 class="promo__title" id="promoTitle">Hydrogen on demand, made in India.</h2>' +
        '<p class="promo__by">Powered by <strong>K7 Technology</strong></p>' +
        '<p class="promo__lead">Manifold Clean Energy Pvt. Ltd. is an Ahmedabad company turning hydrogen from a ' +
          'laboratory promise into products people can actually buy — built for two of the hardest places to ' +
          'decarbonise: the household kitchen and the urban street.</p>' +
        '<ul class="promo__products">' +
          '<li>' +
            '<span class="promo__product-icon"><i class="bi bi-fire" aria-hidden="true"></i></span>' +
            '<span><strong>Kinetic Hydrogen Cooking Stove</strong>' +
              'Generates hydrogen on demand for a clean, powerful flame. No smoke, no soot, ' +
              'and it works with the cookware already in your kitchen.</span>' +
          '</li>' +
          '<li>' +
            '<span class="promo__product-icon"><i class="bi bi-truck-front" aria-hidden="true"></i></span>' +
            '<span><strong>Hydrogen Conversion Kit for TukTuk</strong>' +
              'Converts an existing petrol or CNG auto rickshaw to hydrogen — no scrapping, familiar range ' +
              'and throttle, zero CO₂ at the tailpipe.</span>' +
          '</li>' +
        '</ul>' +
        '<div class="promo__actions">' +
          '<a class="btn-pill btn-pill--accent" href="' + (page === 'index.html' ? '#products' : siteRoot + 'index.html#products') + '">' +
            'See our products <i class="bi bi-arrow-right"></i></a>' +
        '</div>' +
      '</div>';

    var opener = document.activeElement;

    function closePromo() {
      promo.classList.remove('is-open');
      setTimeout(function () { promo.remove(); }, 300);
      if (opener && opener.focus) opener.focus();
    }

    promo.addEventListener('click', function (e) {
      if (e.target.closest('[data-promo-close]')) closePromo();

      /* an in-page jump would leave the modal sitting over the section it
         just scrolled to */
      var action = e.target.closest('.promo__actions a');
      if (action && action.getAttribute('href').charAt(0) === '#') closePromo();
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && promo.isConnected) closePromo();
    });

    document.body.appendChild(promo);
    requestAnimationFrame(function () { promo.classList.add('is-open'); });
    promo.querySelector('.promo__close').focus();
  }

  /* anything on the page can ask for it — a footer link, a button, anywhere */
  document.addEventListener('click', function (e) {
    var trigger = e.target.closest('[data-promo-open]');
    if (!trigger) return;

    e.preventDefault();
    openPromo();
  });

  if (page === 'index.html') {
    /* never on top of the cookie gate — it waits its turn */
    afterConsent(openPromo);
  }

  /* ---------- Blog ----------
     Cards come from blog.php, and Read more slides the whole piece out of the
     right-hand edge. The section stays hidden until there is something live to
     put in it, so an empty blog leaves no hole in the page. */
  var blogGrid = document.getElementById('blogGrid');

  /* the home page is a taste of four; the blog page is the whole thing */
  var blogIsTeaser = page !== 'blog.html';

  if (blogGrid) {
    fetch(siteRoot + 'blog.php?limit=' + (blogIsTeaser ? 4 : 12))
      .then(function (response) { return response.ok ? response.json() : null; })
      .then(function (data) {
        var posts = (data && data.posts) || [];

        if (!posts.length) {
          if (!blogIsTeaser) {
            document.getElementById('blog').hidden = false;
            document.getElementById('blogEmpty').hidden = false;
          }

          return;
        }

        posts.forEach(function (post, index) {
          var card = document.createElement('article');
          card.className = 'blog-card reveal';

          card.innerHTML =
            '<div class="blog-card__media">' +
              (post.image
                ? '<img alt="" loading="lazy" src="' + siteRoot + post.image + '">'
                : '<span class="blog-card__placeholder" aria-hidden="true"><i class="bi bi-droplet-half"></i></span>') +
            '</div>' +
            '<div class="blog-card__body">' +
              '<p class="blog-card__meta"></p>' +
              '<h3 class="blog-card__title"></h3>' +
              '<p class="blog-card__sub"></p>' +
              '<button type="button" class="blog-card__more">Read more <i class="bi bi-arrow-right" aria-hidden="true"></i></button>' +
            '</div>';

          /* everything the office typed goes in as text, never as markup */
          card.querySelector('.blog-card__meta').textContent =
            post.date + ' · ' + post.minutes + ' min read';
          card.querySelector('.blog-card__title').textContent = post.title;
          card.querySelector('.blog-card__sub').textContent = post.subtitle || '';

          card.querySelector('.blog-card__more').addEventListener('click', function () {
            openPost(post, this);
          });

          blogGrid.appendChild(card);

          /* the reveal observer has already run by now, so wake these up */
          setTimeout(function () { card.classList.add('is-visible'); }, 60 * index);
        });

        var section = document.getElementById('blog');
        section.hidden = false;
        section.querySelectorAll('.reveal').forEach(function (el) { el.classList.add('is-visible'); });

        if (blogIsTeaser) {
          var more = document.createElement('div');
          more.className = 'blog-more';
          more.innerHTML =
            '<a class="blog-more__link" href="' + siteRoot + 'blog.html">' +
              'View more <i class="bi bi-arrow-right" aria-hidden="true"></i></a>';

          blogGrid.parentNode.appendChild(more);
        }
      })
      .catch(function () { /* no feed, no section */ });
  }

  /* one blank line, however it is typed, separates two paragraphs */
  var BLANK_LINE = new RegExp("\\n\\s*\\n");

  function openPost(post, opener) {
    var existing = document.querySelector('.post-drawer');
    if (existing) existing.remove();

    var drawer = document.createElement('div');
    drawer.className = 'post-drawer';
    drawer.innerHTML =
      '<div class="post-drawer__backdrop" data-post-close></div>' +
      '<article class="post-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="postTitle" tabindex="-1">' +
        '<button type="button" class="post-drawer__close" data-post-close aria-label="Close">' +
          '<i class="bi bi-x-lg" aria-hidden="true"></i>' +
        '</button>' +
        (post.image ? '<div class="post-drawer__media"><img alt="" src="' + siteRoot + post.image + '"></div>' : '') +
        '<div class="post-drawer__body">' +
          '<p class="post-drawer__meta"></p>' +
          '<h2 class="post-drawer__title" id="postTitle"></h2>' +
          '<p class="post-drawer__sub"></p>' +
          '<div class="post-drawer__text"></div>' +
        '</div>' +
      '</article>';

    drawer.querySelector('.post-drawer__meta').textContent = post.date + ' · ' + post.minutes + ' min read';
    drawer.querySelector('.post-drawer__title').textContent = post.title;

    var sub = drawer.querySelector('.post-drawer__sub');
    if (post.subtitle) { sub.textContent = post.subtitle; } else { sub.remove(); }

    /* a blank line starts a new paragraph; the text itself is never HTML */
    var text = drawer.querySelector('.post-drawer__text');
    String(post.body).split(BLANK_LINE).forEach(function (para) {
      if (!para.trim()) return;
      var p = document.createElement('p');
      p.textContent = para.trim();
      text.appendChild(p);
    });

    function closeDrawer() {
      drawer.classList.remove('is-open');
      document.body.classList.remove('has-drawer');
      setTimeout(function () { drawer.remove(); }, 350);
      if (opener && opener.focus) opener.focus();
    }

    drawer.addEventListener('click', function (e) {
      if (e.target.closest('[data-post-close]')) closeDrawer();
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && drawer.isConnected) closeDrawer();
    });

    document.body.appendChild(drawer);
    document.body.classList.add('has-drawer');
    requestAnimationFrame(function () { drawer.classList.add('is-open'); });
    drawer.querySelector('.post-drawer__panel').focus();
  }

  /* ---------- Gold raffle ----------
     Every 90 days five paid-up applicants win a gram of gold. raffle.php has
     the countdown and the winners of every draw already made public; the list
     the office sees 48 hours early never reaches it. The button is in the markup
     and visible from the start — only an explicit "switched off" from the feed
     takes it away, so turning the raffle off in the admin removes it from every
     page while a slow or failed request leaves it alone. */
  var raffleAsk = null;

  function askRaffle() {
    if (!raffleAsk) {
      raffleAsk = fetch(siteRoot + 'raffle.php', { credentials: 'same-origin' })
        .then(function (response) { return response.ok ? response.json() : null; })
        .catch(function () { return null; });
    }

    return raffleAsk;
  }

  var raffleTriggers = document.querySelectorAll('[data-raffle-open]');

  if (raffleTriggers.length) {
    askRaffle().then(function (data) {
      if (!data || data.enabled !== false) return;

      raffleTriggers.forEach(function (trigger) { trigger.hidden = true; });
    });
  }

  function rupees(currency, value) {
    var whole = Math.round(value);

    try {
      return (currency || '₹') + whole.toLocaleString('en-IN');
    } catch (e) {
      return (currency || '₹') + whole;
    }
  }

  /* d / h / m / s, and never a negative number on screen */
  function raffleParts(ms) {
    var total = Math.max(0, Math.floor(ms / 1000));

    return [
      { value: Math.floor(total / 86400),      label: 'days' },
      { value: Math.floor((total % 86400) / 3600), label: 'hours' },
      { value: Math.floor((total % 3600) / 60),    label: 'minutes' },
      { value: total % 60,                          label: 'seconds' }
    ];
  }

  /* one winner row. Names and numbers arrive already masked from the server,
     and still go in as text rather than markup. */
  function raffleWinnerRow(winner, place) {
    var row = document.createElement('li');
    row.className = 'raffle__winner';

    var badge = document.createElement('span');
    badge.className = 'raffle__place';
    badge.textContent = place;

    var who = document.createElement('span');
    who.className = 'raffle__who';

    var name = document.createElement('strong');
    name.textContent = winner.name;

    var meta = document.createElement('span');
    meta.textContent = [winner.mobile, winner.city].filter(Boolean).join(' · ');

    who.appendChild(name);
    who.appendChild(meta);

    var prize = document.createElement('span');
    prize.className = 'raffle__prize';
    prize.textContent = 'Won';

    row.appendChild(badge);
    row.appendChild(who);
    row.appendChild(prize);

    return row;
  }

  function raffleDrawBlock(draw, open) {
    var block = document.createElement('details');
    block.className = 'raffle__draw';
    block.open = !!open;

    var head = document.createElement('summary');
    head.innerHTML = '<span class="raffle__draw-no"></span><span class="raffle__draw-at"></span>'
      + '<i class="bi bi-chevron-down" aria-hidden="true"></i>';
    head.querySelector('.raffle__draw-no').textContent = 'Draw ' + draw.drawNo;
    head.querySelector('.raffle__draw-at').textContent = draw.label;

    var list = document.createElement('ol');
    list.className = 'raffle__list';

    draw.winners.forEach(function (winner, index) {
      list.appendChild(raffleWinnerRow(winner, index + 1));
    });

    block.appendChild(head);
    block.appendChild(list);

    return block;
  }

  function openRaffle() {
    if (document.querySelector('.raffle')) return;

    var wrap = document.createElement('div');
    wrap.className = 'raffle';
    wrap.innerHTML =
      '<div class="raffle__backdrop" data-raffle-close></div>' +
      '<div class="raffle__card" role="dialog" aria-modal="true" aria-labelledby="raffleTitle">' +
        '<button type="button" class="raffle__close" data-raffle-close aria-label="Close">' +
          '<i class="bi bi-x-lg" aria-hidden="true"></i>' +
        '</button>' +
        '<span class="raffle__eyebrow"><i class="bi bi-ticket-perforated" aria-hidden="true"></i> ' +
          '<span class="raffle__cycle">Gold raffle</span></span>' +
        '<h2 class="raffle__title" id="raffleTitle">Five winners. One gram of pure gold each.</h2>' +
        '<p class="raffle__lead"></p>' +
        '<div class="raffle__clock">' +
          '<img class="raffle__coin" src="' + siteRoot + 'assets/images/gold-coin-320.webp"' +
            ' alt="" width="160" height="160" loading="lazy" decoding="async">' +
          '<div class="raffle__clock-main">' +
            '<span class="raffle__clock-label">Next draw revealed in</span>' +
            '<div class="raffle__units"></div>' +
            '<span class="raffle__at"></span>' +
          '</div>' +
        '</div>' +
        '<ul class="raffle__facts"></ul>' +
        '<div class="raffle__winners"></div>' +
        '<div class="raffle__foot"></div>' +
      '</div>';

    var opener = document.activeElement;
    var timer  = null;

    function closeRaffle() {
      if (timer) clearInterval(timer);
      wrap.classList.remove('is-open');
      setTimeout(function () { wrap.remove(); }, 300);
      if (opener && opener.focus) opener.focus();
    }

    wrap.addEventListener('click', function (e) {
      if (e.target.closest('[data-raffle-close]')) closeRaffle();

      /* an in-page jump would leave the popup over the section it scrolled to */
      var action = e.target.closest('.raffle__foot a');
      if (action && action.getAttribute('href').charAt(0) === '#') closeRaffle();
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && wrap.isConnected) closeRaffle();
    });

    document.body.appendChild(wrap);
    requestAnimationFrame(function () { wrap.classList.add('is-open'); });
    wrap.querySelector('.raffle__close').focus();

    askRaffle().then(function (data) {
      if (!wrap.isConnected) return;

      var card  = wrap.querySelector('.raffle__card');
      var clock = wrap.querySelector('.raffle__clock');
      var units = wrap.querySelector('.raffle__units');
      var lead  = wrap.querySelector('.raffle__lead');
      var facts = wrap.querySelector('.raffle__facts');
      var list  = wrap.querySelector('.raffle__winners');
      var foot  = wrap.querySelector('.raffle__foot');

      if (!data) {
        lead.textContent = 'The raffle details could not be loaded just now. Please try again in a moment.';
        clock.remove();
        return;
      }

      var currency = data.currency || '₹';
      var days     = data.cycleDays || 90;
      var count    = data.winnerCount || 5;
      var grams    = data.goldGrams || 1;
      var gramsSaid = (Math.round(grams * 1000) / 1000) + (grams === 1 ? ' gram' : ' grams');

      wrap.querySelector('.raffle__cycle').textContent = 'Every ' + days + ' days · drawn in public';
      wrap.querySelector('.raffle__title').textContent =
        count + ' winners. ' + gramsSaid + ' of pure gold each.';

      lead.textContent = 'Every ' + days + ' days we draw ' + count + ' winners from everybody who has '
        + 'completed an application and paid in full. The draw is held in front of neutral, independent '
        + 'witnesses so anyone can see the selection is fair.';

      /* the countdown, or an honest note when no date has been set yet */
      if (data.running && data.nextDraw) {
        var target = Date.parse(data.nextDraw.revealAt);

        wrap.querySelector('.raffle__at').textContent =
          'Draw ' + data.nextDraw.drawNo + ' · ' + data.nextDraw.label;

        function tick() {
          if (!wrap.isConnected) {
            clearInterval(timer);
            return;
          }

          var parts = raffleParts(target - Date.now());

          if (!units.children.length) {
            parts.forEach(function () {
              var unit = document.createElement('span');
              unit.className = 'raffle__unit';
              unit.innerHTML = '<strong></strong><span></span>';
              units.appendChild(unit);
            });
          }

          parts.forEach(function (part, index) {
            var unit = units.children[index];
            var digits = String(part.value);

            unit.querySelector('strong').textContent = digits.length < 2 ? '0' + digits : digits;
            unit.querySelector('span').textContent = part.label;
          });
        }

        tick();
        timer = setInterval(tick, 1000);
      } else {
        clock.classList.add('raffle__clock--waiting');
        wrap.querySelector('.raffle__clock-label').textContent = 'Next draw';
        units.remove();
        wrap.querySelector('.raffle__at').textContent =
          'The date of the first draw is announced here as soon as it is set.';
      }

      /* what a winner gets, and the cash alternative */
      var cash = data.cashRange;
      var cashSaid = cash
        ? rupees(currency, cash.low) + '–' + rupees(currency, cash.high)
        : '';
      var band = data.discount
        ? (Math.round(data.discount.min * 100) / 100) + '–' + (Math.round(data.discount.max * 100) / 100) + '%'
        : '5–7%';

      [
        {
          icon: 'bi-coin',
          title: gramsSaid + ' of pure gold',
          text: 'Handed over as a coin. ' + count + ' winners in every draw, ' + days + ' days apart.'
        },
        {
          icon: 'bi-cash-stack',
          title: 'Or the cash value instead',
          text: cashSaid
            ? 'A winner who would rather have money takes ' + band + ' under the market value of '
              + gramsSaid + ' — about ' + cashSaid + ' at today’s ' + rupees(currency, data.goldRate) + ' a gram.'
            : 'A winner who would rather have money takes ' + band + ' under the market value of the gold.'
        },
        {
          icon: 'bi-eye',
          title: 'Drawn in front of witnesses',
          text: 'Neutral, independent people watch every draw, so the selection can be seen to be fair.'
        }
      ].forEach(function (fact) {
        var item = document.createElement('li');
        item.innerHTML = '<span class="raffle__fact-icon"><i class="bi ' + fact.icon + '" aria-hidden="true"></i></span>' +
          '<span><strong></strong><span class="raffle__fact-text"></span></span>';
        item.querySelector('strong').textContent = fact.title;
        item.querySelector('.raffle__fact-text').textContent = fact.text;
        facts.appendChild(item);
      });

      /* winners, newest draw open and the rest folded away */
      var heading = document.createElement('h3');
      heading.className = 'raffle__winners-title';
      heading.textContent = 'Winners so far';
      list.appendChild(heading);

      if (!data.draws || !data.draws.length) {
        var none = document.createElement('p');
        none.className = 'raffle__none';
        none.textContent = data.running
          ? 'No draw has been held yet. The first ' + count + ' winners appear here the moment they are drawn.'
          : 'Winners appear here after the first draw.';
        list.appendChild(none);
      } else {
        data.draws.forEach(function (draw, index) {
          list.appendChild(raffleDrawBlock(draw, index === 0));
        });
      }

      /* how many are in the hat, and the way in for anybody who is not */
      var applyHref = page === 'index.html' ? '#products' : siteRoot + 'index.html#products';

      foot.innerHTML = '<p class="raffle__pool"></p>' +
        '<a class="btn-pill btn-pill--accent" href="' + applyHref + '">Apply and be entered ' +
        '<i class="bi bi-arrow-right"></i></a>';

      foot.querySelector('.raffle__pool').textContent = data.poolSize
        ? data.poolSize + (data.poolSize === 1 ? ' applicant is' : ' applicants are')
          + ' in the next draw. Every completed, fully paid application is one entry, '
          + 'and a past winner does not go back in.'
        : 'Every completed, fully paid application is one entry in the next draw.';

      /* the popup grew while it was open — keep the top in view */
      card.scrollTop = 0;
    });
  }

  document.addEventListener('click', function (e) {
    var trigger = e.target.closest('[data-raffle-open]');
    if (!trigger) return;

    e.preventDefault();

    /* the drawer copy of the button: shut the menu, or it is still open behind
       the popup once that closes */
    if (nav && toggle && nav.contains(trigger)) closeMenu();

    openRaffle();
  });

  /* ---------- Active nav link ---------- */
  var sections = document.querySelectorAll('main section[id]');
  var links    = nav ? nav.querySelectorAll('a[href^="#"]') : [];

  function setActive() {
    var pos = window.scrollY + 140;
    var current = '';

    sections.forEach(function (sec) {
      if (pos >= sec.offsetTop) current = sec.id;
    });

    links.forEach(function (link) {
      link.classList.toggle('is-active', link.getAttribute('href') === '#' + current);
    });
  }
  setActive();
  window.addEventListener('scroll', setActive, { passive: true });

  /* ---------- Dates the pages count towards ----------
     Two of them: 1 January 2027, when the first units ship, and 15 September
     2026, the last day to apply for loan finance. Both are quoted in Ahmedabad
     time, and both counts are written here rather than into the HTML so a page
     can never serve a number that went stale on the server. */
  var IST_OFFSET = 5.5 * 60 * 60 * 1000;

  /* whole days from today to that date, negative once it has passed */
  function daysUntil(year, month, day) {
    var istNow   = new Date(Date.now() + IST_OFFSET);
    var istToday = Date.UTC(istNow.getUTCFullYear(), istNow.getUTCMonth(), istNow.getUTCDate());

    return Math.round((Date.UTC(year, month, day) - istToday) / 86400000);
  }

  var deliveryCount = document.querySelector('[data-delivery-count]');

  if (deliveryCount) {
    var toShipping = daysUntil(2027, 0, 1);

    if (toShipping > 0) {
      var number = deliveryCount.querySelector('[data-delivery-days]');
      var label  = deliveryCount.querySelector('.delivery__count-label');

      number.textContent = toShipping.toLocaleString('en-IN');

      if (label && toShipping === 1) {
        label.textContent = 'day until the first units ship';
      }

      deliveryCount.hidden = false;
    }
  }

  /* ---------- Loan offer: first 1,00,000 customers, closes 15 Sep 2026 ----------
     The pricing sections carry the terms in the markup and only swap to the
     closed wording here. The home page bar is the opposite: hidden until this
     confirms the offer is still open and the visitor has not dismissed it. */
  var offers = document.querySelectorAll('[data-offer]');

  if (offers.length) {
    var toClosing = daysUntil(2026, 8, 15);   /* the 15th itself still counts */
    var isOpen    = toClosing >= 0;
    var dismissed = false;

    try {
      dismissed = window.localStorage.getItem('manifold.loanOffer.hidden') === '2026-09-15';
    } catch (e) {
      /* private browsing: treat it as never dismissed */
    }

    Array.prototype.forEach.call(offers, function (offer) {
      var left   = offer.querySelector('[data-offer-left]');
      var open   = offer.querySelector('[data-offer-open]');
      var closed = offer.querySelector('[data-offer-closed]');

      if (isOpen && left) {
        var prefix = left.getAttribute('data-offer-prefix') || '';

        left.textContent = prefix + (toClosing === 0
          ? 'last day'
          : toClosing.toLocaleString('en-IN') + ' days left');
        left.hidden = false;
      }

      if (open)   { open.hidden   = !isOpen; }
      if (closed) { closed.hidden = isOpen; }

      /* the bar is a promotion, not a term: it goes once the offer closes */
      if (offer.classList.contains('offer-flash')) {
        offer.hidden = !isOpen || dismissed;
      }
    });

    var dismiss = document.querySelector('[data-offer-dismiss]');

    if (dismiss) {
      dismiss.addEventListener('click', function () {
        var bar = dismiss.closest('.offer-flash');

        if (bar) bar.hidden = true;

        try {
          window.localStorage.setItem('manifold.loanOffer.hidden', '2026-09-15');
        } catch (e) {
          /* nothing to remember it with — the bar is back next visit */
        }
      });
    }
  }

  /* ---------- Consent gate ----------
     Any form carrying required checkboxes (declaration, terms, contact
     consent) keeps its submit button locked until every one of them is
     ticked. JS-only, so the form still submits normally without scripts. */
  function lockUntilAccepted(form) {
    var boxes = form.querySelectorAll('input[type="checkbox"][required]');
    if (!boxes.length) return;

    var buttons = form.querySelectorAll(
      'button[type="submit"], input[type="submit"], button:not([type])'
    );
    if (!buttons.length) return;

    function sync() {
      var ready = Array.prototype.every.call(boxes, function (box) {
        return box.checked;
      });

      Array.prototype.forEach.call(buttons, function (button) {
        button.disabled = !ready;
        button.classList.toggle('is-locked', !ready);
        if (ready) {
          button.removeAttribute('title');
        } else {
          button.setAttribute('title', 'Accept the required terms to continue');
        }
      });
    }

    Array.prototype.forEach.call(boxes, function (box) {
      box.addEventListener('change', sync);
    });

    sync();
  }

  Array.prototype.forEach.call(document.querySelectorAll('form'), lockUntilAccepted);
})();
