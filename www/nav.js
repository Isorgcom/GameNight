document.addEventListener('DOMContentLoaded', function() {
    var btn = document.querySelector('.nav-hamburger');
    if (btn) {
        function toggleMenu(e) {
            e.preventDefault();
            btn.nextElementSibling.classList.toggle('open');
        }
        btn.addEventListener('touchend', toggleMenu);
        btn.addEventListener('click', function(e) {
            // touchend already handled it; skip the synthetic click that follows
            if (e.sourceCapabilities && !e.sourceCapabilities.firesTouchEvents) {
                toggleMenu(e);
            } else if (!('ontouchstart' in window)) {
                toggleMenu(e);
            }
        });
    }

    // Close dropdown when tapping/clicking outside
    function closeDropdowns(e) {
        if (!e.target.closest('.nav-dropdown-wrap')) {
            document.querySelectorAll('.nav-dropdown.open').forEach(function(d) {
                d.classList.remove('open');
            });
        }
    }
    document.addEventListener('click', closeDropdowns);
    document.addEventListener('touchend', closeDropdowns);
});
