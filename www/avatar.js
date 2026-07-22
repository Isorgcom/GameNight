/**
 * Client-side avatar rendering — mirrors avatar_hue()/avatar_html() in db.php
 * so JS-rendered avatars (message threads, etc.) match the server-rendered ones.
 * Keep the hash and markup in sync with the PHP.
 */
(function () {
    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    window.gnAvatarHue = function (name) {
        var s = (name || '').toLowerCase().trim(), h = 0;
        for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
        return s.length ? h % 360 : 210;
    };
    window.gnAvatarHtml = function (username, avatarPath, size) {
        size = size || 32;
        var dim = 'width:' + size + 'px;height:' + size + 'px';
        if (avatarPath && /^\/uploads\/[a-zA-Z0-9._-]+$/.test(avatarPath)) {
            return '<img class="gn-avatar" src="' + esc(avatarPath) + '" alt="' + esc(username) + '" style="' + dim + '">';
        }
        var init = '?', m = (username || '').match(/[a-z0-9]/i);
        if (m) init = m[0].toUpperCase();
        var font = Math.max(10, Math.round(size * 0.45));
        return '<span class="gn-avatar" style="' + dim + ';background:hsl(' + window.gnAvatarHue(username)
             + ',60%,30%);font-size:' + font + 'px" aria-hidden="true">' + esc(init) + '</span>';
    };
})();
