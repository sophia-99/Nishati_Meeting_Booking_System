function toggleMenu() {
    document.getElementById('navLinks').classList.toggle('open');
    document.getElementById('menuToggle').classList.toggle('open');
}

function togglePassword(inputId, btn) {
    const input = document.getElementById(inputId);
    if (input.type === 'password') {
        input.type = 'text';
        btn.textContent = 'Hide';
    } else {
        input.type = 'password';
        btn.textContent = 'Show';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.nav-dropdown-btn').forEach(function (btn) {
        btn.addEventListener('click', function (event) {
            event.stopPropagation();
            var group = btn.closest('.nav-dropdown');
            var wasOpen = group.classList.contains('open');
            document.querySelectorAll('.nav-dropdown.open').forEach(function (item) {
                item.classList.remove('open');
                var toggle = item.querySelector('.nav-dropdown-btn');
                if (toggle) toggle.setAttribute('aria-expanded', 'false');
            });
            if (!wasOpen) {
                group.classList.add('open');
                btn.setAttribute('aria-expanded', 'true');
            }
        });
    });

    document.addEventListener('click', function () {
        document.querySelectorAll('.nav-dropdown.open').forEach(function (item) {
            item.classList.remove('open');
            var toggle = item.querySelector('.nav-dropdown-btn');
            if (toggle) toggle.setAttribute('aria-expanded', 'false');
        });
    });

    document.querySelectorAll('.nav-dropdown-menu').forEach(function (menu) {
        menu.addEventListener('click', function (event) {
            event.stopPropagation();
        });
    });
});
