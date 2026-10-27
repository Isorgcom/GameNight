/* Pictures on a comment (v1.13.0). Every comment form on the event page, a
 * league page or the home feed is a plain multipart <form class="cm-form">
 * holding a <div class="cm-compose"> with the <textarea class="cm-body"> and
 * a <div class="cm-thumbs">, a hidden multiple <input type="file"
 * class="cm-file" name="image[]"> and a 📷 <button class="cm-pick">. These
 * delegated listeners serve all of them: the 📷 opens the picker, a paste into
 * the box adds the pasted picture, every chosen picture shows as a thumbnail
 * INSIDE the box with its own × (up to six), the file input is kept equal to
 * that list (a DataTransfer per form) so the plain form carries exactly what
 * is shown, and the form refuses to post with neither words nor a picture.
 * The checks mirror comment.php's so a refusal is instant; the server decides
 * for real. The previews are data: URLs (the CSP allows those for images, and
 * not blob:).
 */
(function () {
    'use strict';
    var OK_TYPE = /^image\/(jpeg|png|gif|webp)$/;
    var MAX_BYTES = 8 * 1024 * 1024;
    var MAX_PICS = 6;

    function formOf(el) { return el && el.closest ? el.closest('.cm-form') : null; }
    function say(msg) { if (typeof window.pkAlert === 'function') window.pkAlert(msg); else window.alert(msg); }
    function listOf(form) { if (!form._cmFiles) form._cmFiles = []; return form._cmFiles; }

    // The file input mirrors the list; a browser without DataTransfer keeps
    // whatever the picker chose and cannot take a paste.
    function syncInput(form) {
        var inp = form.querySelector('.cm-file'); if (!inp) return;
        try { var dt = new DataTransfer(); listOf(form).forEach(function (f) { dt.items.add(f); }); inp.files = dt.files; } catch (e) {}
    }
    function render(form) {
        var box = form.querySelector('.cm-thumbs'); if (!box) return;
        var files = listOf(form);
        box.innerHTML = '';
        box.hidden = files.length === 0;
        files.forEach(function (file, i) {
            var t = document.createElement('div'); t.className = 'cm-thumb';
            var img = document.createElement('img'); img.alt = file.name || 'Pasted picture'; img.title = img.alt;
            var r = new FileReader(); r.onload = function () { img.src = String(r.result); }; r.readAsDataURL(file);
            var x = document.createElement('button'); x.type = 'button'; x.className = 'cm-thumb-x';
            x.setAttribute('aria-label', 'Remove ' + img.alt); x.setAttribute('data-cm-remove', String(i)); x.textContent = '×';
            t.appendChild(img); t.appendChild(x); box.appendChild(t);
        });
    }
    function add(form, fileList) {
        var files = listOf(form);
        for (var i = 0; i < fileList.length; i++) {
            var f = fileList[i];
            if (!OK_TYPE.test(f.type || '')) { say('Only JPEG, PNG, GIF or WebP pictures can be attached.'); continue; }
            if (f.size > MAX_BYTES) { say('A picture is too large (8 MB each at most).'); continue; }
            if (files.length >= MAX_PICS) { say('At most ' + MAX_PICS + ' pictures in one comment.'); break; }
            files.push(f);
        }
        syncInput(form); render(form);
    }
    function removeAt(form, i) { listOf(form).splice(i, 1); syncInput(form); render(form); }

    document.addEventListener('click', function (e) {
        if (!(e.target instanceof Element)) return;
        var pick = e.target.closest('.cm-pick');
        if (pick) { var f = formOf(pick); var inp = f && f.querySelector('.cm-file'); if (inp) inp.click(); return; }
        var x = e.target.closest('[data-cm-remove]');
        if (x) { var f2 = formOf(x); if (f2) removeAt(f2, parseInt(x.getAttribute('data-cm-remove'), 10)); }
    });
    document.addEventListener('change', function (e) {
        var inp = e.target;
        if (!(inp instanceof Element) || !inp.classList.contains('cm-file')) return;
        var f = formOf(inp);
        if (f && inp.files && inp.files.length) add(f, Array.prototype.slice.call(inp.files));
    });
    document.addEventListener('paste', function (e) {
        var ta = e.target;
        if (!(ta instanceof Element) || !ta.classList.contains('cm-body')) return;
        var files = e.clipboardData && e.clipboardData.files;
        if (files && files.length && /^image\//.test(files[0].type || '')) { var f = formOf(ta); if (f) { e.preventDefault(); add(f, files); } }
    });
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!(f instanceof Element) || !f.classList.contains('cm-form')) return;
        var ta = f.querySelector('.cm-body');
        var hasText = ta && ta.value.trim() !== '', hasFile = listOf(f).length > 0 || (function () { var i = f.querySelector('.cm-file'); return !!(i && i.files && i.files.length); })();
        if (!hasText && !hasFile) { e.preventDefault(); say('Write something or attach a picture first.'); }
    }, true);
})();
