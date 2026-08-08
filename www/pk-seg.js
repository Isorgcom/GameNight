/* pk-seg.js — the segmented control + slide-in engine.
 *
 * The house switcher: when a user picks between sibling views, panes or
 * filters, use a .pk-seg pill with a sliding thumb and slide the new content
 * in from the side the thumb travelled. Prefer this over tab strips, underlined
 * tabs, radio groups or plain button rows. Styles live in style.css so any page
 * gets them; this file is loaded by _footer.php, so any page gets the behaviour.
 *
 *   positionSegThumb(segId, animate)          move the thumb to the active button
 *   positionAllSegThumbs(animate)             re-measure every .pk-seg on the page
 *   segTravelDirection(segId, attr, from, to) 1 = travelled right, -1 = left
 *   slideViewIn(el, dir)                      slide freshly-shown content in
 *
 * Markup contract:
 *   <div class="pk-seg" id="mySeg">
 *     <span class="pk-seg-thumb"></span>
 *     <button data-x="a" class="active">A</button>
 *     <button data-x="b">B</button>
 *   </div>
 *
 * Loaded WITHOUT defer: pages that put their own inline <script> after the
 * footer (checkin.php does) must see these defined already.
 */
(function () {
    'use strict';

    // Move a control's thumb under its active button. animate=false snaps
    // without a transition — use it on a fresh render, where nothing moved;
    // animate=true for a user's click, where the movement is the point.
    window.positionSegThumb = function (segId, animate) {
        var seg = document.getElementById(segId);
        if (!seg) return;
        // A hidden control measures zero, and writing that would park the thumb
        // at zero width in the corner for whenever it is shown again. Position
        // AFTER a control becomes visible, never before.
        if (!seg.offsetParent) return;
        var thumb = seg.querySelector('.pk-seg-thumb');
        var active = seg.querySelector('button.active');
        if (!thumb || !active) return;
        if (!animate) {
            thumb.style.transition = 'none';
            thumb.style.width = active.offsetWidth + 'px';
            thumb.style.transform = 'translateX(' + active.offsetLeft + 'px)';
            void thumb.offsetWidth;   // reflow so the next change can transition
            thumb.style.transition = '';
        } else {
            thumb.style.width = active.offsetWidth + 'px';
            thumb.style.transform = 'translateX(' + active.offsetLeft + 'px)';
        }
    };

    // Re-measure every control on the page. Needed after a full re-render or a
    // resize, and any time a segment is shown, hidden, enabled or disabled —
    // those change the buttons' widths even when the selection has not changed.
    window.positionAllSegThumbs = function (animate) {
        var segs = document.querySelectorAll('.pk-seg[id]');
        for (var i = 0; i < segs.length; i++) positionSegThumb(segs[i].id, animate);
    };

    // Which way the thumb travels between two values, so content can enter from
    // the same side. Reads the LIVE button order rather than a hardcoded list,
    // so it stays correct when a segment is conditionally absent.
    window.segTravelDirection = function (segId, attr, from, to) {
        var seg = document.getElementById(segId);
        if (!seg) return 1;
        var order = [].map.call(seg.querySelectorAll('button'), function (b) {
            return b.getAttribute(attr);
        });
        var a = order.indexOf(from), b = order.indexOf(to);
        if (a < 0 || b < 0) return 1;
        return b >= a ? 1 : -1;
    };

    // Slide freshly-shown content in from `dir` (1 = from the right).
    window.slideViewIn = function (el, dir) {
        if (!el) return;
        el.classList.remove('pk-view-in-right', 'pk-view-in-left');
        void el.offsetWidth;   // reflow, so re-picking the same class restarts it
        el.classList.add('pk-view-animating', dir < 0 ? 'pk-view-in-left' : 'pk-view-in-right');
        var done = function () {
            el.classList.remove('pk-view-animating', 'pk-view-in-right', 'pk-view-in-left');
            el.removeEventListener('animationend', done);
        };
        el.addEventListener('animationend', done);
        // animationend never fires under prefers-reduced-motion (animation:none),
        // so drop the clip guard on a timer regardless.
        setTimeout(done, 400);
    };

    // A resize changes button widths, so every control needs re-measuring. Free
    // for any page that uses the control — no per-page wiring.
    window.addEventListener('resize', function () { positionAllSegThumbs(false); });
})();
