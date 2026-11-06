/* pk-qr.js — one QR drawer for the site (v1.19.5).
 *
 * Draws a code onto a canvas and hands the result to an <img> as a PNG data
 * URL. One image, no wrapper markup, so it drops into any container the
 * pages already size (a layout cell, a TV panel, a 220px modal).
 *
 * With /vendor/qr-code-styling.min.js loaded (the entrypoint downloads it;
 * load it before this file) the code is drawn in the site's style: rounded
 * modules in the site's dark colour, the three finder corners in the accent
 * colour, and the site's icon on a white disc over the middle. Without it
 * the plain square code is drawn with /vendor/qrcode.min.js and the icon is
 * composited the same way, so a missing download costs the style and
 * nothing else. A code that carries a logo is made at error-correction
 * level H, which can rebuild up to 30% of its codewords; the disc spans 30%
 * of the width, about 7% of the area. Without a logo the level stays at M.
 *
 *   pkQr.into(img, text, {logo, margin, alt})  draws into an existing <img>:
 *       the code without the icon first, then again with the icon as soon
 *       as it has loaded (just once when it is already cached).
 *   pkQr.image(text, opts)                     a new <img> drawn the same way,
 *                                              or null when neither QR
 *                                              library is loaded.
 *
 * `logo` is a same-origin URL (the site icon, Admin → Settings → Site Icon);
 * a cross-origin picture would taint the canvas and is skipped. A missing or
 * broken icon leaves the code without one. `margin` is the quiet zone in
 * modules (2 by default). The colours come from style.css's --dark and
 * --accent when the page loads it, else the same values built in here. The
 * canvas is drawn at about 680px so the icon stays sharp on a TV; module
 * edges are integer pixels.
 */
(function () {
    'use strict';

    var PLATE_PCT  = 0.30;  // the disc's diameter as a share of the code's width, quiet zone included
    var ICON_PCT   = 0.82;  // the icon's box as a share of the disc; the icon is clipped to the disc
    var TARGET_PX  = 680;   // canvas side; the cell size follows the module count
    var DARK       = '#0f172a';  // style.css --dark
    var ACCENT     = '#2563eb';  // style.css --accent
    var EC_SHARE   = { L: 0.07, M: 0.15, Q: 0.25, H: 0.30 };  // qr-code-styling's cover share per level
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

    // The site's two colours, from the stylesheet when the page has it.
    function siteColours() {
        var dark = '', accent = '';
        try {
            var cs = getComputedStyle(document.documentElement);
            dark = (cs.getPropertyValue('--dark') || '').trim();
            accent = (cs.getPropertyValue('--accent') || '').trim();
        } catch (e) {}
        var hex = /^#[0-9a-f]{6}$/i;
        return { dark: hex.test(dark) ? dark : DARK, accent: hex.test(accent) ? accent : ACCENT };
    }

    // The icon on a white disc `px` wide, as a canvas: drawn over the plain
    // code, or handed to qr-code-styling as its image.
    function plate(icon, px) {
        var c = document.createElement('canvas');
        c.width = px; c.height = px;
        var ctx = c.getContext('2d');
        var mid = px / 2;
        ctx.fillStyle = '#fff';
        ctx.beginPath();
        ctx.arc(mid, mid, mid, 0, Math.PI * 2);
        ctx.fill();
        var box = Math.round(px * ICON_PCT);                 // the icon's room inside the disc
        var iw = icon.naturalWidth, ih = icon.naturalHeight;
        var s = Math.min(box / iw, box / ih);
        var dw = Math.round(iw * s), dh = Math.round(ih * s);
        ctx.save();
        ctx.beginPath();
        ctx.arc(mid, mid, mid - 1, 0, Math.PI * 2);          // a hair inside the white edge
        ctx.clip();
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(icon, Math.round(mid - dw / 2), Math.round(mid - dh / 2), dw, dh);
        ctx.restore();
        return c;
    }

    // The plain code (qrcode.min.js) as a PNG data URL; `icon` is a loaded Image or null.
    function drawPlain(text, level, margin, icon) {
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
            var px = Math.round(side * PLATE_PCT);
            var at = Math.round((side - px) / 2);
            ctx.drawImage(plate(icon, px), at, at);
        }
        return c.toDataURL('image/png');
    }

    // How many modules the code will have: qrcode.min.js when it is loaded,
    // else a guess that is right for a share or walk-up link at level H.
    function moduleCount(text, level) {
        if (typeof qrcode === 'function') {
            try {
                var q = qrcode(0, level);
                q.addData(text);
                q.make();
                return q.getModuleCount();
            } catch (e) {}
        }
        return 45;
    }

    // The styled code (qr-code-styling) as a PNG data URL through `cb`, or
    // null when the library fails. `icon` is a loaded Image or null.
    function drawStyled(text, level, margin, icon, cb) {
        var n = moduleCount(text, level);
        var total = n + 2 * margin;
        var cell = Math.max(4, Math.round(TARGET_PX / total));
        var side = total * cell;
        var col = siteColours();
        var o = {
            width: side, height: side, type: 'canvas', data: text, margin: margin * cell,
            qrOptions: { errorCorrectionLevel: level },
            dotsOptions: { type: 'rounded', color: col.dark },
            cornersSquareOptions: { type: 'extra-rounded', color: col.accent },
            cornersDotOptions: { type: 'dot', color: col.accent },
            backgroundOptions: { color: '#fff' }
        };
        if (icon) {
            // The library sizes its image by the share of modules it may cover
            // (imageSize × the level's share, floor(sqrt) modules on a side,
            // made odd, at most n − 14). Work back from the disc wanted, so it
            // comes out PLATE_PCT of the width as on the plain code.
            var d = Math.round(side * PLATE_PCT / cell);
            if (d % 2 === 0) d--;
            d = Math.max(1, Math.min(d, n - 14));
            o.image = plate(icon, d * cell).toDataURL('image/png');
            o.imageOptions = {
                hideBackgroundDots: false,   // the opaque disc covers the modules, as on the plain code
                imageSize: Math.pow(d + 0.5, 2) / (EC_SHARE[level] * n * n),
                margin: 0,
                // The plate is already a data URL, so the library must not
                // fetch() it to embed it: the site's CSP has no data: on
                // connect-src, and the library's loader never settles when
                // that fetch is refused.
                saveAsBlob: false
            };
        }
        // One answer, whatever happens: the library's draw can hang (an image
        // it cannot load), so a timer hands the caller null and the plain code.
        var done = false;
        var finish = function (url) {
            if (done) return;
            done = true;
            clearTimeout(timer);
            cb(url);
        };
        var timer = setTimeout(function () { finish(null); }, 4000);
        var ready;
        try { ready = new QRCodeStyling(o).getRawData('png'); }
        catch (e) { finish(null); return; }
        ready.then(function (blob) {
            if (!blob) { finish(null); return; }
            var fr = new FileReader();
            fr.onload = function () { finish(fr.result); };
            fr.onerror = function () { finish(null); };
            fr.readAsDataURL(blob);
        }, function () { finish(null); });
    }

    // Paint `img` with the code for `text`; `icon` null draws without one.
    // Later draws win: each takes a number, and a result lands only while
    // its number is still the newest for that image.
    function paint(img, text, level, margin, icon) {
        var seq = (parseInt(img.dataset.pkQrSeq || '0', 10) + 1) % 1e9;
        img.dataset.pkQrSeq = String(seq);
        var current = function () { return img.dataset.pkQrSeq === String(seq); };
        var fallback = function () {
            if (typeof qrcode !== 'function' || !current()) return;
            try { img.src = drawPlain(text, level, margin, icon); } catch (e) {}
        };
        if (typeof QRCodeStyling !== 'function') { fallback(); return; }
        drawStyled(text, level, margin, icon, function (url) {
            if (!current()) return;
            if (url) img.src = url; else fallback();
        });
    }

    function into(img, text, opts) {
        if (!img || (typeof QRCodeStyling !== 'function' && typeof qrcode !== 'function')) return img;
        opts = opts || {};
        var margin = opts.margin == null ? 2 : opts.margin;
        var logo = opts.logo && sameOrigin(opts.logo) ? opts.logo : null;
        var level = logo ? 'H' : 'M';
        if (opts.alt) img.alt = opts.alt;
        var rec = logo ? icons[logo] : null;
        if (rec && rec.ok !== null) {
            paint(img, text, level, margin, rec.ok ? rec.img : null);   // the icon is already known
        } else {
            paint(img, text, level, margin, null);
            if (logo) loadIcon(logo, function (icon) { if (icon) paint(img, text, level, margin, icon); });
        }
        return img;
    }

    function image(text, opts) {
        if (typeof QRCodeStyling !== 'function' && typeof qrcode !== 'function') return null;
        var img = document.createElement('img');
        img.alt = (opts && opts.alt) || 'QR code';
        return into(img, text, opts);
    }

    window.pkQr = { into: into, image: image };
})();
