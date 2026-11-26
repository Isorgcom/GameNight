/**
 * Invite effects: a short flourish over an invitation when it opens.
 *
 * The page's <body data-ivd-effect> names one (confetti, cards, chips,
 * sparkle); the host picks it on invite_design.php. Loaded only on a page
 * that has one, or in the editor's preview, which calls ivdFx.play() itself.
 *
 * Two rules it keeps:
 *   - It ends by itself inside five seconds. Movement that starts on its own
 *     and runs longer needs a pause control (WCAG 2.2.2), and a page that
 *     animates for ever also drains a phone.
 *   - It does not run at all for a visitor who asked for reduced motion.
 * The canvas takes no pointer events (.ivd-fx in invite_look.css), so nothing
 * under it is ever out of reach.
 */
(function () {
    'use strict';

    var DURATION = 5000, FADE = 700;
    var canvas = null, ctx = null, raf = 0, parts = [], kind = '', t0 = 0, last = 0, W = 0, H = 0;

    function rnd(a, b) { return a + Math.random() * (b - a); }
    function pick(list) { return list[Math.floor(Math.random() * list.length)]; }
    function reduced() { return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches); }

    function size() {
        if (!canvas) return;
        var dpr = Math.min(window.devicePixelRatio || 1, 2);
        W = window.innerWidth; H = window.innerHeight;
        canvas.width = Math.round(W * dpr); canvas.height = Math.round(H * dpr);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }

    // Things that fall share one mover: start above the top edge, staggered,
    // drift down with a sway and a spin.
    function faller(extra) {
        var p = { x: rnd(0, W), y: rnd(-H * 0.7, -20), vx: rnd(-25, 25), vy: rnd(120, 230),
                  rot: rnd(0, Math.PI * 2), vr: rnd(-3, 3), ph: rnd(0, Math.PI * 2), sway: rnd(8, 26) };
        for (var k in extra) p[k] = extra[k];
        return p;
    }
    function fall(p, t, dt) {
        p.y += p.vy * dt;
        p.x += (p.vx + Math.sin(p.ph + t / 420) * p.sway) * dt;
        p.rot += p.vr * dt;
    }

    var KINDS = {
        confetti: {
            init: function () {
                var n = Math.max(60, Math.min(160, Math.round(W / 6)));
                var cols = ['#f43f5e', '#f59e0b', '#22c55e', '#3b82f6', '#a855f7', '#ec4899', '#14b8a6', '#fde047'];
                for (var i = 0; i < n; i++) {
                    parts.push(faller({ w: rnd(6, 11), h: rnd(8, 15), col: pick(cols), round: Math.random() < 0.25,
                                        vy: rnd(150, 300), flip: rnd(0, Math.PI * 2), vf: rnd(4, 9) }));
                }
            },
            draw: function (p, t, dt) {
                fall(p, t, dt); p.flip += p.vf * dt;
                ctx.save(); ctx.translate(p.x, p.y); ctx.rotate(p.rot);
                ctx.scale(1, Math.cos(p.flip));          // a piece turning over in the air
                ctx.fillStyle = p.col;
                if (p.round) { ctx.beginPath(); ctx.arc(0, 0, p.w / 2, 0, Math.PI * 2); ctx.fill(); }
                else ctx.fillRect(-p.w / 2, -p.h / 2, p.w, p.h);
                ctx.restore();
            }
        },

        // Small playing cards, so they read on any background.
        cards: {
            init: function () {
                var n = Math.max(12, Math.min(26, Math.round(W / 34)));
                var suits = [['♠', '#0f172a'], ['♥', '#dc2626'], ['♦', '#dc2626'], ['♣', '#0f172a']];
                for (var i = 0; i < n; i++) {
                    var s = pick(suits);
                    parts.push(faller({ s: rnd(0.8, 1.25), glyph: s[0], col: s[1], vy: rnd(110, 200), vr: rnd(-2.2, 2.2) }));
                }
            },
            draw: function (p, t, dt) {
                fall(p, t, dt);
                var w = 26 * p.s, h = 36 * p.s, r = 4 * p.s;
                ctx.save(); ctx.translate(p.x, p.y); ctx.rotate(p.rot);
                ctx.beginPath();
                ctx.moveTo(-w / 2 + r, -h / 2); ctx.arcTo(w / 2, -h / 2, w / 2, h / 2, r); ctx.arcTo(w / 2, h / 2, -w / 2, h / 2, r);
                ctx.arcTo(-w / 2, h / 2, -w / 2, -h / 2, r); ctx.arcTo(-w / 2, -h / 2, w / 2, -h / 2, r); ctx.closePath();
                ctx.fillStyle = '#ffffff'; ctx.shadowColor = 'rgba(0,0,0,.28)'; ctx.shadowBlur = 6; ctx.shadowOffsetY = 2; ctx.fill();
                ctx.shadowColor = 'transparent'; ctx.strokeStyle = 'rgba(15,23,42,.18)'; ctx.lineWidth = 1; ctx.stroke();
                ctx.fillStyle = p.col; ctx.font = (20 * p.s) + 'px Georgia, "Times New Roman", serif';
                ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillText(p.glyph, 0, 1);
                ctx.restore();
            }
        },

        // Poker chips: a coloured disc, six white edge marks, an inner ring.
        chips: {
            init: function () {
                var n = Math.max(14, Math.min(28, Math.round(W / 30)));
                var cols = ['#dc2626', '#2563eb', '#16a34a', '#1f2937', '#7c3aed'];
                for (var i = 0; i < n; i++) {
                    parts.push(faller({ r: rnd(11, 17), col: pick(cols), vy: rnd(130, 240), vr: rnd(-4, 4) }));
                }
            },
            draw: function (p, t, dt) {
                fall(p, t, dt);
                ctx.save(); ctx.translate(p.x, p.y); ctx.rotate(p.rot);
                ctx.beginPath(); ctx.arc(0, 0, p.r, 0, Math.PI * 2);
                ctx.fillStyle = p.col; ctx.shadowColor = 'rgba(0,0,0,.3)'; ctx.shadowBlur = 5; ctx.shadowOffsetY = 2; ctx.fill();
                ctx.shadowColor = 'transparent';
                ctx.fillStyle = '#ffffff';
                for (var i = 0; i < 6; i++) {
                    ctx.rotate(Math.PI / 3);
                    ctx.fillRect(-p.r * 0.16, -p.r, p.r * 0.32, p.r * 0.3);
                }
                ctx.beginPath(); ctx.arc(0, 0, p.r * 0.56, 0, Math.PI * 2);
                ctx.strokeStyle = 'rgba(255,255,255,.85)'; ctx.lineWidth = Math.max(1, p.r * 0.1); ctx.stroke();
                ctx.restore();
            }
        },

        // Stars that stay put and twinkle. The gold glow keeps a white star
        // visible on a white card.
        sparkle: {
            init: function () {
                var n = Math.max(24, Math.min(46, Math.round((W * H) / 22000)));
                var cols = ['#ffffff', '#fde68a', '#fbbf24'];
                for (var i = 0; i < n; i++) {
                    parts.push({ x: rnd(0, W), y: rnd(0, H), r: rnd(5, 13), col: pick(cols),
                                 start: rnd(0, DURATION - 1400), life: rnd(900, 1700), rot: rnd(0, Math.PI / 4) });
                }
            },
            draw: function (p, t) {
                var u = (t - p.start) / p.life;
                if (u <= 0 || u >= 1) return;
                var a = Math.sin(u * Math.PI), r = p.r * (0.4 + 0.6 * a);
                ctx.save(); ctx.translate(p.x, p.y); ctx.rotate(p.rot + u * 0.6);
                ctx.globalAlpha *= a; ctx.fillStyle = p.col;
                ctx.shadowColor = 'rgba(251,191,36,.95)'; ctx.shadowBlur = 10;
                ctx.beginPath();
                for (var i = 0; i < 8; i++) {            // four long points, four short
                    var rr = (i % 2 === 0) ? r : r * 0.22, an = i * Math.PI / 4;
                    ctx.lineTo(Math.cos(an) * rr, Math.sin(an) * rr);
                }
                ctx.closePath(); ctx.fill();
                ctx.restore();
            }
        }
    };

    function stop() {
        if (raf) cancelAnimationFrame(raf);
        raf = 0; parts = [];
        window.removeEventListener('resize', size);
        if (canvas && canvas.parentNode) canvas.parentNode.removeChild(canvas);
        canvas = null; ctx = null;
    }

    function frame(now) {
        var t = now - t0, dt = Math.min(50, now - last) / 1000;
        last = now;
        if (t >= DURATION || !ctx) { stop(); return; }
        ctx.clearRect(0, 0, W, H);
        var fade = t > DURATION - FADE ? (DURATION - t) / FADE : 1;
        var k = KINDS[kind];
        for (var i = 0; i < parts.length; i++) { ctx.globalAlpha = fade; k.draw(parts[i], t, dt); }
        raf = requestAnimationFrame(frame);
    }

    function play(name) {
        stop();
        if (!Object.prototype.hasOwnProperty.call(KINDS, name) || reduced()) return false;
        canvas = document.createElement('canvas');
        canvas.className = 'ivd-fx';
        canvas.setAttribute('aria-hidden', 'true');
        ctx = canvas.getContext('2d');
        if (!ctx) { canvas = null; return false; }
        document.body.appendChild(canvas);
        size();
        window.addEventListener('resize', size);
        kind = name; parts = [];
        KINDS[name].init();
        t0 = last = performance.now();
        raf = requestAnimationFrame(frame);
        return true;
    }

    window.ivdFx = { play: play, stop: stop };

    function auto() { play(document.body.getAttribute('data-ivd-effect') || ''); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', auto);
    else auto();
})();
