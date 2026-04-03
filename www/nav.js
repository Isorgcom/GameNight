(function() {
    var btn = document.querySelector('.nav-hamburger');
    if (btn) {
        function toggleMenu(e) {
            e.preventDefault();
            e.stopPropagation();
            btn.nextElementSibling.classList.toggle('open');
        }
        btn.addEventListener('touchend', toggleMenu);
        btn.addEventListener('click', function(e) {
            if (!e.isTrusted) return;
            // Only handle real mouse clicks; touch devices fire touchend above
            if (!('ontouchstart' in window)) {
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
})();
