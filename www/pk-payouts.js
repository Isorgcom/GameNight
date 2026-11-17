/* pk-payouts.js — what each finishing place is paid, in cents.
 *
 * One function, used by every screen that turns a payout percentage into
 * dollars (Manage Game, the Table Manager, both timers), so they cannot
 * disagree with each other or with what the server records.
 *
 * It is the twin of pk_payout_amounts() in _poker_helpers.php and must stay
 * identical to it. With a rounding step (POOL.payout_round, cents: 500 is the
 * nearest $5) every place but the first is rounded to the nearest step and
 * first place takes what is left of the money the percentages assigned, so the
 * prizes add up to exactly what they did unrounded.
 *
 *   pkPayoutAmounts(poolCents, rows, stepCents)  ->  { place: cents }
 *   rows: payout rows with .place and .percentage
 *
 * Loaded WITHOUT defer: the pages' inline scripts render payouts as they run.
 */
(function () {
    'use strict';
    var STEPS = [0, 500, 1000, 2000];

    window.pkPayoutAmounts = function (poolCents, rows, stepCents) {
        var pool = parseInt(poolCents, 10) || 0, raw = {}, places = [], i;
        rows = rows || [];
        for (i = 0; i < rows.length; i++) {
            var place = parseInt(rows[i].place, 10) || 0, pct = parseFloat(rows[i].percentage) || 0;
            if (place > 0) { raw[place] = pct > 0 ? Math.round(pool * pct / 100) : 0; places.push(place); }
        }
        var step = parseInt(stepCents, 10) || 0;
        if (STEPS.indexOf(step) === -1) step = 0;
        if (step <= 0 || pool <= 0) return raw;
        places.sort(function (a, b) { return a - b; });
        var first = null, total = 0;
        for (i = 0; i < places.length; i++) {
            total += raw[places[i]];
            if (first === null && raw[places[i]] > 0) first = places[i];
        }
        if (first === null) return raw;
        var out = {}, others = 0;
        for (i = 0; i < places.length; i++) {
            var p = places[i];
            if (p === first || raw[p] <= 0) { out[p] = raw[p]; continue; }
            out[p] = Math.floor((raw[p] + Math.floor(step / 2)) / step) * step;
            others += out[p];
        }
        out[first] = total - others;
        // A pool too small to round is paid exactly instead.
        for (i = 0; i < places.length; i++) {
            if (places[i] !== first && out[places[i]] > out[first]) return raw;
        }
        return out[first] > 0 ? out : raw;
    };
})();
