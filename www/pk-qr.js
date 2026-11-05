/* pk-qr.js — one QR drawer for the site (v1.19.2).
 *
 * Draws a code with /vendor/qrcode.min.js (load it first) onto a canvas and
 * composites the site's icon over the middle on a white plate, then hands the
 * result to an <img> as a PNG data URL. One image, no wrapper markup, so it
 * drops into any container the pages already size (a layout cell, a TV panel,
 * a 220px modal). A code that carries a logo is made at error-correction
 * level H, which can rebuild up to 30% of its codewords; the plate covers 27%
 * of the width, about 7% of the area. Without a logo the level stays at M.
 *
 *   pkQr.into(img, text, {logo, margin, alt})  draws into an existing <img>:
 *       the plain code at once, then again with the icon composited as soon
 *       as the icon has loaded (at once when it is already cached).
 *   pkQr.image(text, opts)                     a new <img> drawn the same way,
 *                                              or null if qrcode.min.js is
 *                                              not loaded.
 *
 * `logo` is a same-origin URL (the site icon, Admin → Settings → Site Icon);
 * a cross-origin picture would taint the canvas and is skipped. A missing or
 * broken icon leaves the plain code. The canvas is drawn at about 680px so the
 * icon stays sharp on a TV; module edges are integer pixels, as before.
 */
(function () {
    'use strict';

    var PLATE_PCT  = 0.27;  // of the code's width, quiet zone included
    var TARGET_PX  = 680;   // canvas side; the cell size follows the module count
    var icons = {};         // url -> { img: Image, ok: bool|null, waiting: [fn] }

    function sameOrigin(url) {
        try { return new URL(url, location.href).origin === location.origin; }
        catch (e) { return false; }
    }

    function loadIcon(url, cb) {
        var rec = icons[url];
        if (rec) {
            if (rec.ok !== null) { cb(rec.ok ? rec.img : null); return; }
            rec.waiting.push(cb); return;
        }
        rec = icons[url] = { img: new Image(), ok: null, waiting: [cb] };
        rec.img.decoding = 'async';
        rec.img.onload = function () {
            rec.ok = rec.img.naturalWidth > 0;
            rec.waiting.splice(0).forEach(function (f) { f(rec.ok ? rec.img : null); });
        };
        rec.img.onerror = function () {
            rec.ok = false;
            rec.waiting.splice(0).forEach(function (f) { f(null); });
        };
        rec.img.src = url;
    }

    function roundRect(ctx, x, y, w, h, r) {
        ctx.beginPath();
        if (ctx.roundRect) { ctx.roundRect(x, y, w, h, r); }
        else {
            ctx.moveTo(x + r, y);
            ctx.arcTo(x + w, y, x + w, y + h, r);
            ctx.arcTo(x + w, y + h, x, y + h, r);
            ctx.arcTo(x, y + h, x, y, r);
            ctx.arcTo(x, y, x + w, y, r);
            ctx.closePath();
        }
    }

    // The code as a PNG data URL; `icon` is a loaded Image or null.
    function draw(text, level, margin, icon) {
        var qr = qrcode(0, level);
        qr.addData(text);
        qr.make();
        var n = qr.getModuleCount();
        var total = n + 2 * margin;
        var cell = Math.max(4, Math.round(TARGET_PX / total));
        var side = total * cell;
        var c = document.createElement('canvas');
        c.width = side; c.height = side;
        var ctx = c.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, side, side);
        ctx.fillStyle = '#000';
        for (var r = 0; r < n; r++) {
            for (var col = 0; col < n; col++) {
                if (qr.isDark(r, col)) ctx.fillRect((col + margin) * cell, (r + margin) * cell, cell, cell);
            }
        }
        if (icon) {
            var plate = Math.round(side * PLATE_PCT);
            var p0 = Math.round((side - plate) / 2);
            ctx.fillStyle = '#fff';
            roundRect(ctx, p0, p0, plate, plate, Math.round(plate * 0.18));
            ctx.fill();
            var box = Math.round(plate * 0.86);               // the icon's room inside the plate
            var iw = icon.naturalWidth, ih = icon.naturalHeight;
            var s = Math.min(box / iw, box / ih);
            var dw = Math.round(iw * s), dh = Math.round(ih * s);
            ctx.imageSmoothingEnabled = true;
            ctx.imageSmoothingQuality = 'high';
            ctx.drawImage(icon, Math.round((side - dw) / 2), Math.round((side - dh) / 2), dw, dh);
        }
        return c.toDataURL('image/png');
    }

    function into(img, text, opts) {
        if (!img || typeof qrcode !== 'function') return img;
        opts = opts || {};
        var margin = opts.margin == null ? 2 : opts.margin;
        var logo = opts.logo && sameOrigin(opts.logo) ? opts.logo : null;
        var level = logo ? 'H' : 'M';
        if (opts.alt) img.alt = opts.alt;
        try { img.src = draw(text, level, margin, null); } catch (e) { return img; }
        if (logo) {
            // A later draw into the same image wins: the icon callback checks
            // that the image still wants this code before painting it.
            img.dataset.pkQrText = text;
            loadIcon(logo, function (icon) {
                if (!icon || img.dataset.pkQrText !== text) return;
                try { img.src = draw(text, level, margin, icon); } catch (e) {}
            });
        }
        return img;
    }

    function image(text, opts) {
        if (typeof qrcode !== 'function') return null;
        var img = document.createElement('img');
        img.alt = (opts && opts.alt) || 'QR code';
        return into(img, text, opts);
    }

    window.pkQr = { into: into, image: image };
})();
