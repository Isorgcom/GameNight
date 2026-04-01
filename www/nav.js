document.addEventListener('click', function(e) {
    if (!e.target.closest('.nav-dropdown-wrap')) {
        document.querySelectorAll('.nav-dropdown.open').forEach(function(d) {
            d.classList.remove('open');
        });
    }
});
