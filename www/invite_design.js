/**
 * Invite design editor (invite_design.php).
 *
 * The page's data is window.IDZ: the event id, the CSRF token, the stored
 * design, the ready-made looks and each background's light or dark mode.
 *
 * The preview is the real invite page in a same-origin frame. Its look is four
 * attributes on <body> (and data-theme on <html>), the ones the server writes,
 * so a pick here is shown by setting the same attribute there: no reload, and
 * no second copy of the look to drift from the real one.
 *
 * Every pick is saved as it is made. Saves are queued, never overlapped: a
 * pick during a save is sent when that save returns, so the last one wins.
 * The controls call the idz* functions through pk-dispatch.js (data-act).
 */
(function () {
    'use strict';

    var IDZ = window.IDZ;
    if (!IDZ) return;

    var d      = { theme: IDZ.design.theme, accent: IDZ.design.accent, font: IDZ.design.font, effect: IDZ.design.effect,
                   cover: IDZ.design.cover || '', cover_w: IDZ.design.cover_w || 0, cover_h: IDZ.design.cover_h || 0 };
    var frame  = document.getElementById('idzFrame');
    var status = document.getElementById('idzStatus');
    var saving = false, again = false, timer = 0, busy = false;
    var COVER_MAX_SIDE = 1600;

    // ── Status line ───────────────────────────────────────────────────────
    function say(text, cls, aloud) {
        status.textContent = text;
        status.className = 'idz-status' + (cls ? ' ' + cls : '');
        if (aloud && typeof window.pkAnnounce === 'function') window.pkAnnounce(text);
    }

    // ── Preview ───────────────────────────────────────────────────────────
    function frameDoc() {
        try { var doc = frame.contentDocument; return (doc && doc.body) ? doc : null; } catch (e) { return null; }
    }

    function paint() {
        var doc = frameDoc();
        if (!doc) return;
        var b = doc.body;
        // Not the invite page (a sign-in redirect, an error): leave it alone.
        if (!b.hasAttribute('data-ivd-theme')) return;
        b.setAttribute('data-ivd-theme', d.theme);
        b.setAttribute('data-ivd-accent', d.accent);
        b.setAttribute('data-ivd-font', d.font);
        b.setAttribute('data-ivd-effect', d.effect);
        var mode = IDZ.modes[d.theme] || '';
        if (mode) doc.documentElement.setAttribute('data-theme', mode);
        else doc.documentElement.removeAttribute('data-theme');
        var box = doc.querySelector('.ivd-cover');
        if (box) {
            var img = box.querySelector('img');
            if (d.cover) {
                if (img.getAttribute('src') !== d.cover) img.setAttribute('src', d.cover);
                if (d.cover_w && d.cover_h) { img.setAttribute('width', d.cover_w); img.setAttribute('height', d.cover_h); }
                box.hidden = false;
            } else {
                box.hidden = true;
                img.removeAttribute('src');
            }
        }
    }

    function playEffect() {
        var w = null;
        try { w = frame.contentWindow; } catch (e) {}
        if (!w || !w.ivdFx) return;
        if (d.effect === 'none') w.ivdFx.stop(); else w.ivdFx.play(d.effect);
    }

    frame.addEventListener('load', paint);

    // ── The controls' pressed state ───────────────────────────────────────
    function lookFor() {
        for (var k in IDZ.looks) {
            var l = IDZ.looks[k];
            if (l.theme === d.theme && l.accent === d.accent && l.font === d.font && l.effect === d.effect) return k;
        }
        return '';
    }

    function mark(effectAnimate) {
        var picks = document.querySelectorAll('.idz-pick[data-kind]');
        for (var i = 0; i < picks.length; i++) {
            var p = picks[i];
            p.setAttribute('aria-pressed', d[p.getAttribute('data-kind')] === p.getAttribute('data-key') ? 'true' : 'false');
        }
        var look = lookFor(), looks = document.querySelectorAll('.idz-look');
        for (var j = 0; j < looks.length; j++) {
            looks[j].setAttribute('aria-pressed', looks[j].getAttribute('data-look') === look ? 'true' : 'false');
        }
        var fx = document.querySelectorAll('#idzFxSeg button');
        for (var n = 0; n < fx.length; n++) {
            var on = fx[n].getAttribute('data-fx') === d.effect;
            fx[n].classList.toggle('active', on);
            fx[n].setAttribute('aria-pressed', on ? 'true' : 'false');
        }
        if (typeof window.positionSegThumb === 'function') window.positionSegThumb('idzFxSeg', !!effectAnimate);
    }

    // ── Saving ────────────────────────────────────────────────────────────
    function post(fields, file) {
        var fd = new FormData();
        fd.append('csrf_token', IDZ.csrf);
        fd.append('event_id', IDZ.eid);
        for (var k in fields) fd.append(k, fields[k]);
        if (file) fd.append('cover', file.blob, file.name);
        return fetch('/invite_design_dl.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'The server did not answer. Try again.' }; }); });
    }

    function save() {
        if (saving) { again = true; return; }
        saving = true; again = false;
        say('Saving…', '');
        post({ action: 'save', theme: d.theme, accent: d.accent, font: d.font, effect: d.effect }).then(function (j) {
            saving = false;
            if (!j || !j.ok) { say((j && j.error) || 'Could not save. Try again.', 'bad', true); return; }
            if (again) { save(); return; }
            say('Saved ✓', 'ok', true);
        }).catch(function () {
            saving = false;
            say('Could not save. Check your connection and try again.', 'bad', true);
        });
    }

    function saveSoon() {
        clearTimeout(timer);
        timer = setTimeout(save, 250);
    }

    // ── Picks ─────────────────────────────────────────────────────────────
    window.idzPick = function (kind, key) {
        if (!Object.prototype.hasOwnProperty.call(d, kind) || kind.indexOf('cover') === 0) return;
        var same = d[kind] === key;
        d[kind] = key;
        mark(kind === 'effect' && !same);
        paint();
        // Picking the effect already chosen plays it again: the only way to
        // see it twice, since it stops by itself.
        if (kind === 'effect') playEffect();
        if (!same) saveSoon();
    };

    window.idzLook = function (key) {
        var l = IDZ.looks[key];
        if (!l) return;
        var fxChanged = d.effect !== l.effect;
        d.theme = l.theme; d.accent = l.accent; d.font = l.font; d.effect = l.effect;
        mark(fxChanged);
        paint();
        playEffect();
        saveSoon();
    };

    window.idzReset = function () {
        var go = function () { window.idzLook('classic'); };
        if (typeof window.pkConfirm !== 'function') { go(); return; }
        window.pkConfirm('Go back to the standard look? The background, accent colour, title font and effect are reset. Your cover picture stays.',
                         { okLabel: 'Reset' }).then(function (ok) { if (ok) go(); });
    };

    // ── Cover ─────────────────────────────────────────────────────────────
    var coverErr = document.getElementById('idzCoverErr');
    var coverBtn = document.getElementById('idzCoverBtn');
    var coverRm  = document.getElementById('idzCoverRm');
    var thumb    = document.getElementById('idzCoverThumb');

    function coverError(msg) {
        coverErr.textContent = msg || '';
        coverErr.hidden = !msg;
    }

    function coverShow() {
        while (thumb.firstChild) thumb.removeChild(thumb.firstChild);
        if (d.cover) {
            var img = document.createElement('img');
            img.src = d.cover; img.alt = 'Current cover';
            thumb.appendChild(img);
        } else {
            thumb.appendChild(document.createTextNode('No cover'));
        }
        thumb.classList.toggle('has', !!d.cover);
        coverBtn.textContent = d.cover ? 'Replace picture' : 'Upload a picture';
        coverRm.hidden = !d.cover;
        paint();
    }

    function coverBusy(on) {
        busy = on;
        coverBtn.disabled = on; coverRm.disabled = on;
    }

    function coverTake(j, doneMsg) {
        coverBusy(false);
        if (!j || !j.ok) { coverError((j && j.error) || 'That did not work. Try again.'); say('', ''); return; }
        d.cover = j.design.cover || ''; d.cover_w = j.design.cover_w || 0; d.cover_h = j.design.cover_h || 0;
        coverShow();
        say(doneMsg, 'ok', true);
    }

    // A picture, decoded. createImageBitmap reads the file directly; the
    // fallback goes through a data: URL because the CSP has no blob: images.
    function decode(file) {
        var viaImg = function () {
            return new Promise(function (res, rej) {
                var fr = new FileReader();
                fr.onerror = function () { rej(new Error('read')); };
                fr.onload = function () {
                    var im = new Image();
                    im.onload = function () { res(im); };
                    im.onerror = function () { rej(new Error('decode')); };
                    im.src = fr.result;
                };
                fr.readAsDataURL(file);
            });
        };
        if (typeof window.createImageBitmap !== 'function') return viaImg();
        return window.createImageBitmap(file).catch(viaImg);
    }

    // What goes up: a GIF as it is (an animated one keeps moving), anything
    // else scaled to fit COVER_MAX_SIDE and written as JPEG, which every mail
    // client and every link preview can show.
    function prepare(file) {
        if (file.type === 'image/gif') {
            if (file.size > IDZ.maxBytes) return Promise.reject(new Error('That GIF is too large (4 MB at most).'));
            return Promise.resolve({ blob: file, name: 'cover.gif' });
        }
        return decode(file).then(function (img) {
            var w = img.width, h = img.height;
            if (!w || !h) throw new Error('decode');
            var k = Math.min(1, COVER_MAX_SIDE / Math.max(w, h));
            var cw = Math.max(1, Math.round(w * k)), ch = Math.max(1, Math.round(h * k));
            var c = document.createElement('canvas');
            c.width = cw; c.height = ch;
            var g = c.getContext('2d');
            g.fillStyle = '#ffffff';            // JPEG has no transparency
            g.fillRect(0, 0, cw, ch);
            g.drawImage(img, 0, 0, cw, ch);
            if (img.close) img.close();
            return new Promise(function (res, rej) {
                c.toBlob(function (b) {
                    if (!b) { rej(new Error('encode')); return; }
                    res({ blob: b, name: 'cover.jpg' });
                }, 'image/jpeg', 0.88);
            });
        }, function () {
            throw new Error('That file could not be read as a picture. Try a JPEG or PNG.');
        });
    }

    window.idzCoverPick = function () {
        if (!busy) document.getElementById('idzCoverFile').click();
    };

    window.idzCoverChosen = function (input) {
        var file = input.files && input.files[0];
        input.value = '';
        if (!file || busy) return;
        coverError('');
        if (!/^image\//.test(file.type)) { coverError('Choose a picture file.'); return; }
        coverBusy(true);
        say('Uploading the cover…', '');
        prepare(file).then(function (up) {
            if (up.blob.size > IDZ.maxBytes) throw new Error('That picture is too large (4 MB at most).');
            return post({ action: 'cover_upload' }, up);
        }).then(function (j) {
            coverTake(j, 'Cover saved ✓');
        }).catch(function (e) {
            coverBusy(false);
            say('', '');
            coverError(e && e.message && e.message.length > 12 ? e.message : 'That picture could not be used. Try another.');
        });
    };

    window.idzCoverRemove = function () {
        if (busy || !d.cover) return;
        var go = function () {
            coverError('');
            coverBusy(true);
            say('Removing the cover…', '');
            post({ action: 'cover_remove' }).then(function (j) { coverTake(j, 'Cover removed ✓'); })
                .catch(function () { coverBusy(false); say('', ''); coverError('Could not remove it. Try again.'); });
        };
        if (typeof window.pkConfirm !== 'function') { go(); return; }
        window.pkConfirm('Remove the cover picture from this invite?', { okLabel: 'Remove', danger: true })
              .then(function (ok) { if (ok) go(); });
    };

    // The site nav is sticky, so the preview sticks below it: its height goes
    // to the stylesheet as --idz-top.
    function navTop() {
        var nav = document.querySelector('nav');
        var h = nav ? Math.round(nav.getBoundingClientRect().height) : 0;
        document.documentElement.style.setProperty('--idz-top', h + 'px');
    }
    navTop();
    window.addEventListener('resize', navTop);
    window.addEventListener('load', navTop);

    // A fresh render: the thumb goes to the active segment without sliding.
    if (typeof window.positionSegThumb === 'function') window.positionSegThumb('idzFxSeg', false);
    paint();
})();
