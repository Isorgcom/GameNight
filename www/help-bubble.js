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
 * together at the same time. A tip with no idx is its own step. Back/Next move
 * between steps ("Step X of N"); Next on the last step soft-closes the tour
 * (reappears next page load). Only the X records a server-side dismissal.
 *
 * Anchoring is visibility-aware:
 *   - selector matches a visible element  -> bubble points at it
 *   - selector matches nothing            -> bubble floats in the corner stack,
 *     and moves onto the element if one appears later (the check-in dashboard
 *     draws its buttons from script after the page loads)
 *   - selector matches a HIDDEN element   -> bubble waits, then appears anchored
 *     when the element becomes visible (e.g. its modal opens) and hides again
 *     when it disappears. If waiting would leave the current step with no
 *     bubbles at all, the waiting tips show in the corner meanwhile so the
 *     tour never presents an empty step.
 *   - a modal is open (.pk-modal-overlay.open, <dialog open>) -> every tip
 *     not anchored inside it waits until the modal closes. One thing to read
 *     at a time: the one-time timer prompt on Manage Game used to come up
 *     with the first tip half under it.
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
  var shown = false;         // is the tour currently displayed (vs pill mode)
  var live = [];             // anchored bubbles on screen: {bubble, anchor, tip}
  var corner = [];           // corner bubbles on screen: {bubble, tip, demoted}
  var waiting = [];          // current-step tips whose anchor exists but is hidden
  var watchTimer = null;


  function el(tag, cls) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    return n;
  }

  function findAnchor(tip) {
    if (!tip.anchor_selector) return null;
    try { return document.querySelector(tip.anchor_selector); } catch (e) { return null; }
  }

  function isVisible(node) {
    if (!node || !node.isConnected) return false;
    var r = node.getBoundingClientRect();
    if (r.width <= 0 || r.height <= 0) return false;
    return window.getComputedStyle(node).visibility !== 'hidden';
  }

  // The modal that owns the screen right now, if any. Closed overlays are
  // display:none, so visibility is the test; pk-dialogs' confirms use the
  // same overlay class and count too.
  function openModal() {
    var els = document.querySelectorAll('.pk-modal-overlay, dialog[open]');
    for (var i = 0; i < els.length; i++) if (isVisible(els[i])) return els[i];
    return null;
  }
  var modalWas = false;      // modal state at the last render, so the watcher sees it change

  // On screen, and not behind an open modal.
  function anchorReady(a, modal) {
    return !!a && isVisible(a) && (!modal || modal.contains(a));
  }

  function buildBubble(tip) {
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
    corner = [];
    waiting = [];
    var all = document.querySelectorAll('.help-bubble');
    Array.prototype.slice.call(all).forEach(function (n) {
      if (n.parentNode) n.parentNode.removeChild(n);
    });
  }

  function addBubble(tip, anchor, demoted) {
    var b = buildBubble(tip);
    if (anchor) {
      b.classList.add('help-bubble--anchored');
      b._tail.style.display = '';
      document.body.appendChild(b);
      placeByAnchor(b, anchor);
      live.push({ bubble: b, anchor: anchor, tip: tip });
    } else {
      stack.appendChild(b);
      corner.push({ bubble: b, tip: tip, demoted: !!demoted });
    }
    requestAnimationFrame(function () { b.classList.add('help-bubble--in'); });
  }

  function render() {
    ensureStack();
    clearBubbles();
    var step = steps[stepIdx] || [];
    var modal = openModal();
    modalWas = !!modal;
    step.forEach(function (tip) {
      var anchor = findAnchor(tip);
      if (modal && !(anchor && modal.contains(anchor))) { waiting.push(tip); return; } // behind the modal: wait for it to close
      if (anchor && !isVisible(anchor)) { waiting.push(tip); return; } // wait for it
      // A selector that matches nothing yet starts in the corner demoted, so
      // the watcher moves it onto the element the moment one appears. A plain
      // corner bubble was never re-checked, which left the check-in tips in
      // the corner for good: #setupBtn does not exist until the dashboard
      // has loaded.
      addBubble(tip, anchor, !anchor && !!tip.anchor_selector);
    });
    // Never present an empty step: surface waiting tips in the corner meanwhile.
    // They stay in `waiting` so the watcher upgrades them once the anchor shows.
    // Not while a modal is up, though: an empty step is the point of waiting then.
    if (!modal && !live.length && !corner.length && waiting.length) {
      waiting.forEach(function (tip) { addBubble(tip, null, true); });
    }
    startWatch();
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

  // ─── Visibility watcher ─────────────────────────────────────────────
  // While the tour is shown and the screen has anchored tips, poll for state
  // changes: a waiting/demoted tip whose anchor became visible, or a live
  // anchored bubble whose anchor vanished. Any change re-renders the step.
  // Also keeps anchored bubbles pinned through layout shifts/animations.
  function checkAnchors() {
    if (!shown) return;
    var changed = false;
    var modal = openModal();
    if (!!modal !== modalWas) changed = true;       // a modal opened or closed
    waiting.forEach(function (tip) {
      var a = findAnchor(tip);
      if (modal) { if (anchorReady(a, modal)) changed = true; return; } // only something inside the modal can come ready
      if (!a || isVisible(a)) changed = true;       // appeared, or removed from DOM
    });
    corner.forEach(function (o) {
      if (!o.demoted) return;
      if (anchorReady(findAnchor(o.tip), modal)) changed = true;   // can upgrade to anchored now
    });
    live.forEach(function (o) {
      if (!anchorReady(o.anchor, modal)) changed = true;   // anchor hid, or a modal came up over it
    });
    if (changed) { render(); return; }
    live.forEach(function (o) { placeByAnchor(o.bubble, o.anchor); });
  }

  // Always on while the tour shows, anchors or not: a corner-only tour still
  // has to step aside for a modal and come back when it closes.
  function startWatch() {
    if (watchTimer) return;
    watchTimer = setInterval(checkAnchors, 350);
  }

  function stopWatch() {
    if (watchTimer) { clearInterval(watchTimer); watchTimer = null; }
  }

  // Modals open from clicks; re-check shortly after any click for a snappy
  // response instead of waiting for the next interval tick.
  document.addEventListener('click', function (e) {
    if (!shown) return;
    if (e.target.closest && e.target.closest('.help-bubble, .help-pill')) return;
    setTimeout(checkAnchors, 60);
  }, true);

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
    shown = true;
    if (pill) pill.style.display = 'none';
    render();
  }

  function hide() {
    shown = false;
    stopWatch();
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
