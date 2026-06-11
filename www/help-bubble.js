/* In-app help bubbles ("ghost bubble" hints).
 *
 * Reads window.__help = {
 *   screen:    'calendar',
 *   tips:      [{id, title, body, anchor_selector, idx}, ...],
 *   dismissed: false,        // server-side dismissal state for this user+screen
 *   csrf:      '...',        // token for the dismiss POST
 *   preview:   false         // when true (admin preview) the X just hides, no POST
 * }
 *
 * Tips are grouped into STEPS by `idx`: tips that share the same idx number show
 * together at the same time (anchored ones at their elements, corner ones stacked
 * bottom-right). A tip with no idx is its own step. Back/Next move between steps;
 * the counter reads "Step X of N". Bubbles never grab focus. Closing dismisses the
 * screen (server-side, unless preview) and leaves a small "?" pill to reopen.
 */
(function () {
  var cfg = window.__help;
  if (!cfg || !cfg.tips || !cfg.tips.length) return;

  // Group tips into steps. Tips arrive pre-ordered (sort_order, id); grouping by
  // first occurrence keeps step order stable. Same idx => same step.
  function buildSteps(tips) {
    var out = [], byIdx = {};
    tips.forEach(function (t) {
      var has = t.idx !== null && t.idx !== undefined && t.idx !== '';
      if (has) {
        var k = 'i' + t.idx;
        if (!byIdx[k]) { byIdx[k] = []; out.push(byIdx[k]); }
        byIdx[k].push(t);
      } else {
        out.push([t]);
      }
    });
    return out;
  }

  var steps = buildSteps(cfg.tips);
  if (!steps.length) return;

  var stepIdx = 0;
  var stack = null;          // fixed bottom-right container for corner bubbles
  var pill = null;
  var live = [];             // currently-shown anchored bubbles: {bubble, anchor}

  function el(tag, cls) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    return n;
  }

  function buildBubble(tip, primary) {
    var b = el('div', 'help-bubble');
    b.setAttribute('role', 'note');

    var close = el('button', 'help-bubble__close');
    close.type = 'button';
    close.setAttribute('aria-label', 'Dismiss help');
    close.innerHTML = '&times;';
    close.addEventListener('click', dismiss);
    b.appendChild(close);

    if (tip.title) {
      var title = el('div', 'help-bubble__title');
      title.textContent = tip.title;
      b.appendChild(title);
    }

    var body = el('div', 'help-bubble__body');
    String(tip.body || '').split('\n').forEach(function (line, i) {
      if (i) body.appendChild(document.createElement('br'));
      body.appendChild(document.createTextNode(line));
    });
    b.appendChild(body);

    // Every bubble in a multi-step tour carries its own Back/Next nav.
    if (steps.length > 1) {
      var nav = el('div', 'help-bubble__nav');
      var prev = el('button', 'help-bubble__arrow');
      prev.type = 'button';
      prev.textContent = 'Back';
      prev.setAttribute('aria-label', 'Previous step');
      prev.disabled = stepIdx === 0;
      if (prev.disabled) { prev.style.opacity = '.45'; prev.style.cursor = 'default'; }
      prev.addEventListener('click', function () { go(stepIdx - 1); });
      var counter = el('span', 'help-bubble__counter');
      counter.textContent = 'Step ' + (stepIdx + 1) + ' of ' + steps.length;
      var next = el('button', 'help-bubble__arrow');
      next.type = 'button';
      next.textContent = 'Next';
      next.setAttribute('aria-label', 'Next step');
      next.addEventListener('click', function () { go(stepIdx + 1); });
      nav.appendChild(prev);
      nav.appendChild(counter);
      nav.appendChild(next);
      b.appendChild(nav);
    }

    var tail = el('div', 'help-bubble__tail');
    tail.style.display = 'none';
    b.appendChild(tail);
    b._tail = tail;
    return b;
  }

  function buildPill() {
    pill = el('button', 'help-pill');
    pill.type = 'button';
    pill.title = 'Show help for this page';
    pill.setAttribute('aria-label', 'Show help for this page');
    pill.textContent = '?';
    pill.addEventListener('click', function () { show(); });
    document.body.appendChild(pill);
  }

  function ensureStack() {
    if (!stack) { stack = el('div', 'help-stack'); document.body.appendChild(stack); }
  }

  function clearBubbles() {
    live = [];
    var all = document.querySelectorAll('.help-bubble');
    Array.prototype.slice.call(all).forEach(function (n) {
      if (n.parentNode) n.parentNode.removeChild(n);
    });
  }

  function render() {
    ensureStack();
    clearBubbles();
    var step = steps[stepIdx] || [];
    step.forEach(function (tip, i) {
      var b = buildBubble(tip, i === 0);
      var anchor = null;
      if (tip.anchor_selector) {
        try { anchor = document.querySelector(tip.anchor_selector); } catch (e) { anchor = null; }
      }
      if (anchor) {
        b.classList.add('help-bubble--anchored');
        b._tail.style.display = '';
        document.body.appendChild(b);
        placeByAnchor(b, anchor);
        live.push({ bubble: b, anchor: anchor });
      } else {
        stack.appendChild(b);   // corner bubble: flows in the bottom-right stack
      }
      requestAnimationFrame(function () { b.classList.add('help-bubble--in'); });
    });
  }

  function placeByAnchor(b, anchor) {
    var r = anchor.getBoundingClientRect();
    var bw = b.offsetWidth || 280;
    var bh = b.offsetHeight || 120;
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

    b.style.left = left + 'px';
    b.style.top = Math.max(8, top) + 'px';

    // Point the tail at the anchor's horizontal center.
    var tailX = r.left + r.width / 2 - left;
    tailX = Math.max(14, Math.min(tailX, bw - 14));
    b._tail.style.left = tailX + 'px';
    b._tail.classList.toggle('help-bubble__tail--up', below);
    b._tail.classList.toggle('help-bubble__tail--down', !below);
  }

  function go(n) {
    if (n < 0) return;                                // no stepping back past the first step
    if (n >= steps.length) { softClose(); return; }   // Next on the last step ends the tour
    stepIdx = n;
    render();
  }

  // End the tour for this page view only: no dismiss POST, so the tour
  // auto-shows again on the next load. Reopening starts back at step 1.
  // Only the X (dismiss) hides the tour permanently.
  function softClose() {
    stepIdx = 0;
    hide();
  }

  function show() {
    if (pill) pill.style.display = 'none';
    render();
  }

  function hide() {
    clearBubbles();
    if (!pill) buildPill();
    pill.style.display = '';
  }

  function dismiss() {
    hide();
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

  function reflow() {
    live.forEach(function (o) { placeByAnchor(o.bubble, o.anchor); });
  }
  window.addEventListener('resize', reflow);
  window.addEventListener('scroll', reflow, { passive: true });

  if (cfg.dismissed) {
    buildPill();
  } else {
    show();
  }
})();
