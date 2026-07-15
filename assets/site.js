/* EthioTractors — public site behavior */
(function () {
  'use strict';

  /* ---------- Mobile nav ---------- */
  var nav = document.getElementById('mainnav');
  var toggle = document.getElementById('menuToggle');
  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      var open = nav.classList.toggle('open');
      toggle.classList.toggle('open', open);
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      document.body.style.overflow = open ? 'hidden' : '';
    });
    nav.querySelectorAll('a').forEach(function (a) {
      a.addEventListener('click', function () {
        nav.classList.remove('open');
        toggle.classList.remove('open');
        document.body.style.overflow = '';
      });
    });
  }

  /* ---------- Header shadow on scroll + back-to-top ---------- */
  var header = document.getElementById('siteHeader');
  var toTop = document.getElementById('toTop');
  window.addEventListener('scroll', function () {
    var y = window.scrollY;
    if (header) header.classList.toggle('scrolled', y > 10);
    if (toTop) toTop.classList.toggle('show', y > 700);
  }, { passive: true });

  /* ---------- Scrollspy: highlight the nav link of the section in view ---------- */
  var navLinks = Array.prototype.slice.call(document.querySelectorAll('nav.main a[href^="#"]'));
  var spyTargets = navLinks
    .map(function (a) { return document.getElementById(a.getAttribute('href').slice(1)); })
    .filter(function (el) { return el && el.tagName !== 'BODY'; });

  function setActiveLink(hash) {
    navLinks.forEach(function (a) {
      a.classList.toggle('active', a.getAttribute('href') === hash);
    });
  }

  function spy() {
    // A section is "current" when its area crosses the line just under the header.
    var line = 120;
    var current = '';
    for (var i = 0; i < spyTargets.length; i++) {
      var r = spyTargets[i].getBoundingClientRect();
      if (r.top <= line && r.bottom > line) { current = '#' + spyTargets[i].id; break; }
    }
    if (!current && window.scrollY < 200) current = '#top';
    setActiveLink(current);
  }
  window.addEventListener('scroll', spy, { passive: true });
  window.addEventListener('resize', spy, { passive: true });
  spy();

  /* ---------- Reveal on scroll ---------- */
  var revealEls = document.querySelectorAll('.reveal');
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) {
          en.target.classList.add('in');
          io.unobserve(en.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
    revealEls.forEach(function (el) { io.observe(el); });
  } else {
    revealEls.forEach(function (el) { el.classList.add('in'); });
  }

  /* ---------- Hero stat counters ---------- */
  function animateCount(el) {
    var target = parseInt(el.getAttribute('data-count'), 10);
    if (isNaN(target)) return;
    var start = null, dur = 1400;
    function tick(ts) {
      if (!start) start = ts;
      var p = Math.min((ts - start) / dur, 1);
      var eased = 1 - Math.pow(1 - p, 3);
      el.textContent = Math.round(eased * target) + (p === 1 && target >= 10 ? '+' : '');
      if (p < 1) requestAnimationFrame(tick);
    }
    requestAnimationFrame(tick);
  }
  document.querySelectorAll('.hero-stat .n[data-count]').forEach(animateCount);

  /* ---------- Product filter + search ---------- */
  var tabs = document.querySelectorAll('.cat-tab');
  var search = document.getElementById('catSearch');
  var cards = Array.prototype.slice.call(document.querySelectorAll('#pgrid .pcard'));
  var countEl = document.getElementById('catCount');
  var emptyEl = document.getElementById('pgridEmpty');
  var activeSector = 'all';

  function applyFilter() {
    var q = (search && search.value ? search.value : '').trim().toLowerCase();
    var shown = 0;
    cards.forEach(function (card) {
      var okSector = activeSector === 'all' || card.getAttribute('data-sector') === activeSector;
      var okSearch = !q || card.getAttribute('data-search').indexOf(q) !== -1;
      var show = okSector && okSearch;
      card.style.display = show ? '' : 'none';
      if (show) shown++;
    });
    if (emptyEl) emptyEl.hidden = shown !== 0;
    if (countEl) {
      var label = activeSector === 'all' ? 'all sectors' : activeSector;
      countEl.textContent = 'Showing ' + shown + ' of ' + cards.length + ' product lines — ' + label + (q ? ' · “' + q + '”' : '');
    }
  }

  function selectSector(sector) {
    activeSector = sector;
    tabs.forEach(function (t) {
      t.classList.toggle('active', t.getAttribute('data-sector') === sector);
    });
    applyFilter();
  }

  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () { selectSector(tab.getAttribute('data-sector')); });
  });
  if (search) search.addEventListener('input', applyFilter);

  /* Industry cards deep-link into a pre-filtered catalog */
  document.querySelectorAll('[data-goto-sector]').forEach(function (link) {
    link.addEventListener('click', function () {
      selectSector(link.getAttribute('data-goto-sector'));
    });
  });

  /* ---------- “Get a quote” prefill ---------- */
  document.querySelectorAll('[data-quote]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (navigator.sendBeacon) {
        var fd = new FormData();
        fd.append('action', 'track');
        fd.append('event', 'quote_click');
        fd.append('label', btn.getAttribute('data-quote') || '');
        navigator.sendBeacon('index.php', fd);
      }
      var interest = document.getElementById('f-interest');
      var industry = document.getElementById('f-industry');
      var message = document.getElementById('f-message');
      if (interest) interest.value = btn.getAttribute('data-quote');
      if (industry) {
        var sectorLabels = {
          agriculture: 'Agriculture',
          construction: 'Construction',
          mining: 'Mining',
          power: 'Power & Logistics'
        };
        var label = sectorLabels[btn.getAttribute('data-sector')] || '';
        for (var i = 0; i < industry.options.length; i++) {
          if (industry.options[i].text === label) { industry.selectedIndex = i; break; }
        }
      }
      var contact = document.getElementById('contact');
      if (contact) contact.scrollIntoView({ behavior: 'smooth' });
      if (message) setTimeout(function () { message.focus({ preventScroll: true }); }, 650);
    });
  });

  /* ---------- Toast (server flash) ---------- */
  var toast = document.getElementById('toast');
  if (toast) {
    requestAnimationFrame(function () { toast.classList.add('show'); });
    setTimeout(function () { toast.classList.remove('show'); }, 6500);
    toast.addEventListener('click', function () { toast.classList.remove('show'); });
  }
})();
