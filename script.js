(function () {
    const menuBtn = document.getElementById('pixelMenuBtn');
    const nav = document.getElementById('pixelNav');
    const form = document.getElementById('pixelForm');

    if (menuBtn && nav) {
        menuBtn.addEventListener('click', function () {
            const isOpen = nav.classList.toggle('is-open');
            menuBtn.setAttribute('aria-expanded', String(isOpen));
        });

        nav.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                nav.classList.remove('is-open');
                menuBtn.setAttribute('aria-expanded', 'false');
            });
        });
    }

    const revealItems = document.querySelectorAll('.reveal-up, .reveal-right');

    const revealObserver = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');
                revealObserver.unobserve(entry.target);
            }
        });
    }, { threshold: 0.15, rootMargin: '0px 0px -35px 0px' });

    revealItems.forEach(function (item) {
        revealObserver.observe(item);
    });

    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            const nombre = form.nombre.value.trim();
            const telefono = form.telefono.value.trim();
            const interes = form.interes.value.trim();

            if (!nombre || !telefono || !interes) {
                form.reportValidity();
                return;
            }

            const mensaje = [
                'Hola Cultura Pixel, quiero informacion para inscribirme.',
                '',
                'Nombre: ' + nombre,
                'Telefono: ' + telefono,
                'Programa de interes: ' + interes
            ].join('\n');

            const waUrl = 'https://wa.me/5491100000000?text=' + encodeURIComponent(mensaje);
            window.open(waUrl, '_blank', 'noopener,noreferrer');
        });
    }
})();
