/* TNG Extra Shortcodes — bouton « Afficher tout / Réduire la liste » (sans jQuery) */
document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.tng-events-toggle') : null;
    if (!btn) { return; }

    e.preventDefault();

    var box = document.getElementById(btn.getAttribute('data-target'));
    if (!box) { return; }

    var expanded = btn.getAttribute('aria-expanded') === 'true';
    var extras = box.querySelectorAll('.tng-event-extra');

    for (var i = 0; i < extras.length; i++) {
        if (expanded) {
            extras[i].classList.add('tng-hidden');
        } else {
            extras[i].classList.remove('tng-hidden');
        }
    }

    btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
    btn.textContent = expanded ? btn.getAttribute('data-more') : btn.getAttribute('data-less');
});
