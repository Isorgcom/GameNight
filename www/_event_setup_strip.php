<?php
/**
 * Shared header strip for the per-event tournament setup pages
 * (event_blinds.php / event_display.php): back link, event title, and the
 * segmented control that swaps between the two pages. House switcher style —
 * pk-seg with the sliding thumb; event_setup.js positions the thumb and
 * slides the page body in from the direction of travel.
 *
 * Caller sets: $event_id (int), $event_title (string), $es_active ('blinds'|'display').
 */
?>
<div class="es-head">
    <a class="es-back" href="/event.php?id=<?= (int)$event_id ?>" title="Back to the event">&larr;</a>
    <h1 class="es-title"><?= htmlspecialchars($event_title, ENT_QUOTES | ENT_SUBSTITUTE) ?></h1>
    <div class="pk-seg" id="esSeg">
        <span class="pk-seg-thumb"></span>
        <button type="button" data-x="blinds" data-href="/event_blinds.php?event_id=<?= (int)$event_id ?>"
                class="<?= $es_active === 'blinds' ? 'active' : '' ?>">Blind Levels</button>
        <button type="button" data-x="display" data-href="/event_display.php?event_id=<?= (int)$event_id ?>"
                class="<?= $es_active === 'display' ? 'active' : '' ?>">Timer Display</button>
    </div>
</div>
