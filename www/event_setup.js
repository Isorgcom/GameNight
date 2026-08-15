/* Shared behaviour for the per-event setup pages (event_blinds.php /
 * event_display.php): the header strip's thumb + cross-page slide.
 *
 * The strip's two segments are different PAGES, so the "swap" is a real
 * navigation. sessionStorage remembers which segment we came from so the
 * arriving page can slide its body in from the direction of travel — the
 * same read as an in-page pk-seg switch. */
(function () {
    var seg = document.getElementById('esSeg');
    if (!seg) return;

    positionSegThumb('esSeg', false);

    var active = seg.querySelector('button.active');
    var cur = active ? active.getAttribute('data-x') : null;
    var prev = null;
    try {
        prev = sessionStorage.getItem('esSegFrom');
        sessionStorage.removeItem('esSegFrom');
    } catch (e) {}
    if (prev && cur && prev !== cur) {
        var body = document.querySelector('.es-wrap');
        if (body) slideViewIn(body, segTravelDirection('esSeg', 'data-x', prev, cur));
    }

    seg.addEventListener('click', function (e) {
        var b = e.target.closest('button[data-href]');
        if (!b || b.classList.contains('active')) return;
        try { sessionStorage.setItem('esSegFrom', cur || ''); } catch (err) {}
        positionSegThumb('esSeg', true);
        location.href = b.getAttribute('data-href');
    });
})();
