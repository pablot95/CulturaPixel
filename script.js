(function () {
    const menuBtn = document.getElementById('pixelMenuBtn');
    const nav = document.getElementById('pixelNav');
    const form = document.getElementById('pixelForm');

    // Mostrar logo del header recién al pasar el hero
    const headerLogo = document.getElementById('headerLogo');
    const heroSection = document.querySelector('.pixel-hero');

    function updateHeaderLogo() {
        if (!headerLogo || !heroSection) return;
        const heroBottom = heroSection.getBoundingClientRect().bottom;
        if (heroBottom <= 0) {
            headerLogo.classList.remove('pixel-brand__logo--hidden');
        } else {
            headerLogo.classList.add('pixel-brand__logo--hidden');
        }
    }

    window.addEventListener('scroll', updateHeaderLogo, { passive: true });
    updateHeaderLogo();

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
            const apellido = form.apellido.value.trim();
            const dni = form.dni.value.trim();
            const fecha = form.fecha.value.trim();
            const telefono = form.telefono.value.trim();
            const interes = form.interes.value.trim();

            if (!nombre || !apellido || !dni || !fecha || !telefono || !interes) {
                form.reportValidity();
                return;
            }

            const mensaje = [
                'Hola, quiero inscribirme.',
                '',
                'Nombre: ' + nombre,
                'Apellido: ' + apellido,
                'DNI: ' + dni,
                'Fecha de nacimiento: ' + fecha,
                'Telefono: ' + telefono,
                'Curso(s) de interes: ' + interes
            ].join('\n');

            const waUrl = 'https://wa.me/5492615547922?text=' + encodeURIComponent(mensaje);
            window.open(waUrl, '_blank', 'noopener,noreferrer');
        });
    }

    // Precio vigente de los ebooks (se edita desde el panel). En las páginas de cada
    // ebook lo actualiza /ebooks/ebooks.js.
    const preciosEbook = document.querySelectorAll('[data-precio-ebook]');
    if (preciosEbook.length && !document.body.classList.contains('ebook-page') && window.fetch) {
        fetch('/api/ebooks/catalogo.php', { headers: { Accept: 'application/json' } })
            .then(function (respuesta) { return respuesta.ok ? respuesta.json() : null; })
            .then(function (datos) {
                ((datos && datos.ebooks) || []).forEach(function (ebook) {
                    if (!(ebook.precio > 0)) return;
                    preciosEbook.forEach(function (elemento) {
                        if (elemento.getAttribute('data-precio-ebook') === ebook.id) {
                            elemento.textContent = '$' + new Intl.NumberFormat('es-AR').format(ebook.precio);
                        }
                    });
                });
            })
            .catch(function () {});
    }

    // Abrir detalles al hacer clic en el header de la card
    const cursoCards = document.querySelectorAll('.curso-card');
    
    cursoCards.forEach(function (card) {
        const header = card.querySelector('.curso-card__header');
        const details = card.querySelector('.curso-card__details');
        
        if (header && details) {
            header.style.cursor = 'pointer';
            
            header.addEventListener('click', function (e) {
                // No abrir si se hace clic en un enlace
                if (e.target.tagName === 'A' || e.target.closest('a')) {
                    return;
                }
                
                // Toggle del details
                details.open = !details.open;
            });
        }
    });
})();
