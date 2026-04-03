// Close dropdown when clicking outside (mouse users)
document.addEventListener('click', function(e) {
    if (!e.target.closest('.nav-dropdown-wrap')) {
        document.querySelectorAll('.nav-dropdown').forEach(function(d) {
            d.style.display = 'none';
        });
    }
});
