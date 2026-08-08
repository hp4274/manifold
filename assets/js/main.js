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
    header.classList.toggle('is-stuck', window.scrollY > 40);
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
})();
