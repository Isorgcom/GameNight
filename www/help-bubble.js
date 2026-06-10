/* In-app help bubbles ("ghost bubble" hints).
 *
 * Reads window.__help = {
 *   screen:    'calendar',
 *   tips:      [{id, title, body, anchor_selector}, ...],
 *   dismissed: false,        // server-side dismissal state for this user+screen
 *   csrf:      '...',        // token for the dismiss POST
 *   preview:   false         // when true (admin preview) the X just hides, no POST
 * }
 *
 * Behaviour: a single rounded chat bubble cycles through the screen's tips
 * (Prev/Next, "N of M"). It never grabs focus. Closing it dismisses the screen
 * (server-side, unless preview) and leaves a small "?" pill to reopen.
 */
(function () {
  var cfg = window.__help;
  if (!cfg || !cfg.tips || !cfg.tips.length) return;

  var idx = 0;
  var bubble, pill;
  var anchorEl = null; // element the current tip is anchored to, if any

  function el(tag, cls) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    return n;
  }

  function buildBubble() {
    bubble = el('div', 'help-bubble');
    bubble.setAttribute('role', 'note');

    var close = el('button', 'help-bubble__close');
    close.type = 'button';
    close.setAttribute('aria-label', 'Dismiss help');
    close.innerHTML = '&times;';
    close.addEventListener('click', dismiss);

    var title = el('div', 'help-bubble__title');
    var body = el('div', 'help-bubble__body');

    var nav = el('div', 'help-bubble__nav');
    var prev = el('button', 'help-bubble__arrow');
    prev.type = 'button';
    prev.innerHTML = '&#8249;';
    prev.setAttribute('aria-label', 'Previous tip');
    prev.addEventListener('click', function () { go(idx - 1); });
    var counter = el('span', 'help-bubble__counter');
    var next = el('button', 'help-bubble__arrow');
    next.type = 'button';
    next.innerHTML = '&#8250;';
    next.setAttribute('aria-label', 'Next tip');
    next.addEventListener('click', function () { go(idx + 1); });
    nav.appendChild(prev);
    nav.appendChild(counter);
    nav.appendChild(next);

    bubble.appendChild(close);
    bubble.appendChild(title);
    bubble.appendChild(body);
    bubble.appendChild(nav);
    document.body.appendChild(bubble);

    bubble._title = title;
    bubble._body = body;
    bubble._counter = counter;
    bubble._nav = nav;
    bubble._tail = el('div', 'help-bubble__tail');
    bubble.appendChild(bubble._tail);
  }

  function buildPill() {
    pill = el('button', 'help-pill');
    pill.type = 'button';
    pill.title = 'Show help for this page';
    pill.setAttribute('aria-label', 'Show help for this page');
    pill.textContent = '?';
    pill.addEventListener('click', function () { showBubble(); });
    document.body.appendChild(pill);
  }

  function render() {
    var tip = cfg.tips[idx];
    if (tip.title) {
      bubble._title.textContent = tip.title;
      bubble._title.style.display = '';
    } else {
      bubble._title.style.display = 'none';
    }
    // Body is plain text; preserve author line breaks.
    bubble._body.textContent = '';
    String(tip.body || '').split('\n').forEach(function (line, i) {
      if (i) bubble._body.appendChild(document.createElement('br'));
      bubble._body.appendChild(document.createTextNode(line));
    });
    if (cfg.tips.length > 1) {
      bubble._nav.style.display = '';
      bubble._counter.textContent = (idx + 1) + ' of ' + cfg.tips.length;
    } else {
      bubble._nav.style.display = 'none';
    }
    position(tip);
  }

  function position(tip) {
    anchorEl = null;
    bubble.classList.remove('help-bubble--anchored');
    bubble._tail.style.display = 'none';
    // Reset to floating-corner defaults.
    bubble.style.top = '';
    bubble.style.left = '';
    bubble.style.right = '';
    bubble.style.bottom = '';

    if (tip.anchor_selector) {
      try { anchorEl = document.querySelector(tip.anchor_selector); } catch (e) { anchorEl = null; }
    }
    if (anchorEl) {
      bubble.classList.add('help-bubble--anchored');
      bubble._tail.style.display = '';
      placeByAnchor();
    }
  }

  function placeByAnchor() {
    if (!anchorEl) return;
    var r = anchorEl.getBoundingClientRect();
    var bw = bubble.offsetWidth || 280;
    var bh = bubble.offsetHeight || 120;
    var gap = 12;
    var vw = document.documentElement.clientWidth;
    var vh = document.documentElement.clientHeight;

    // Prefer below the anchor; flip above if it would overflow the viewport.
    var top = r.bottom + gap;
    var below = true;
    if (top + bh > vh - 8 && r.top - gap - bh > 8) {
      top = r.top - gap - bh;
      below = false;
    }
    // Horizontally center on the anchor, clamped to the viewport.
    var left = r.left + r.width / 2 - bw / 2;
    left = Math.max(8, Math.min(left, vw - bw - 8));

    bubble.style.left = left + 'px';
    bubble.style.top = Math.max(8, top) + 'px';

    // Point the tail at the anchor's horizontal center.
    var tailX = r.left + r.width / 2 - left;
    tailX = Math.max(14, Math.min(tailX, bw - 14));
    bubble._tail.style.left = tailX + 'px';
    bubble._tail.classList.toggle('help-bubble__tail--up', below);
    bubble._tail.classList.toggle('help-bubble__tail--down', !below);
  }

  function go(n) {
    var len = cfg.tips.length;
    idx = (n % len + len) % len;
    render();
  }

  function showBubble() {
    if (!bubble) buildBubble();
    bubble.style.display = '';
    if (pill) pill.style.display = 'none';
    render();
    requestAnimationFrame(function () { bubble.classList.add('help-bubble--in'); });
  }

  function hideBubble() {
    if (bubble) bubble.style.display = 'none';
    if (!pill) buildPill();
    pill.style.display = '';
  }

  function dismiss() {
    hideBubble();
    if (cfg.preview) return;
    try {
      var data = new URLSearchParams();
      data.set('action', 'dismiss');
      data.set('screen', cfg.screen);
      data.set('csrf_token', cfg.csrf || '');
      fetch('/help_dl.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: data.toString(),
        credentials: 'same-origin'
      });
    } catch (e) { /* dismissal is best-effort */ }
  }

  window.addEventListener('resize', function () {
    if (bubble && bubble.style.display !== 'none' && anchorEl) placeByAnchor();
  });

  // Reposition anchored bubble on scroll (fixed bubble, moving anchor).
  window.addEventListener('scroll', function () {
    if (bubble && bubble.style.display !== 'none' && anchorEl) placeByAnchor();
  }, { passive: true });

  if (cfg.dismissed) {
    buildPill();
  } else {
    showBubble();
  }
})();
