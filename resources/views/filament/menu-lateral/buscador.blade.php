{{--
    El buscador del menú lateral. Lo explica App\Filament\Navegacion\MenuLateral.

    wire:ignore: el menú es un componente Livewire y, si se repintara,
    el buscador seguiría con lo tecleado en vez de volver vacío.

    El script corre ANTES que Filament lea los grupos recogidos: por eso
    la primera visita con una VERSION nueva puede dejarlos recogidos.
--}}
<div class="sihla-menu-buscar" wire:ignore x-data x-show="$store.sidebar.isOpen">
    <label class="sihla-menu-buscar-caja">
        <svg aria-hidden="true" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11ZM2 9a7 7 0 1 1 12.452 4.391l3.328 3.329a.75.75 0 1 1-1.06 1.06l-3.329-3.328A7 7 0 0 1 2 9Z" clip-rule="evenodd" />
        </svg>
        <input
            type="text"
            id="sihla-menu-buscar"
            placeholder="Buscar en el menú"
            aria-label="Buscar en el menú"
            autocomplete="off"
            spellcheck="false"
        >
        <kbd title="Desde cualquier pantalla: tecla /">/</kbd>
    </label>
    <p class="sihla-menu-nada" hidden></p>

    <script @if (filled(\Illuminate\Support\Facades\Vite::cspNonce())) nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" @endif>
        (() => {
            const entrada = document.getElementById('sihla-menu-buscar');

            if (! entrada || entrada.dataset.listo === '1') {
                return;
            }

            entrada.dataset.listo = '1';

            const palabras = @js($palabras);
            const recogidos = @js($recogidos);
            const version = @js($version);

            try {
                if (localStorage.getItem('sihla-menu') !== version) {
                    localStorage.setItem('collapsedGroups', JSON.stringify(recogidos));
                    localStorage.setItem('sihla-menu', version);
                }
            } catch (error) {
                // Sin localStorage el menú arranca como Filament lo dejaría.
            }

            const nav = entrada.closest('.fi-sidebar-nav');
            const nada = nav.querySelector('.sihla-menu-nada');

            const normal = (texto) => (texto || '')
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .toLowerCase()
                .replace(/\s+/g, ' ')
                .trim();

            const etiquetaDe = (renglon) => {
                const etiqueta = renglon.querySelector(':scope > .fi-sidebar-item-btn .fi-sidebar-item-label');

                return etiqueta ? etiqueta.textContent.trim() : '';
            };

            let visibles = [];
            let elegido = 0;

            const marcar = () => {
                visibles.forEach((renglon, indice) => renglon.classList.toggle('sihla-elegido', indice === elegido));
            };

            // Primero se busca en el nombre de la opción y en sus palabras; el
            // nombre del grupo cuenta solo si así no aparece nada. Si no, «caja»
            // traería el grupo «Cuentas y caja» entero en vez de Caja.
            const filtrar = () => {
                const fichas = normal(entrada.value).split(' ').filter(Boolean);
                const filtrando = fichas.length > 0;
                const sirve = (pajar) => fichas.every((ficha) => pajar.includes(ficha));

                const renglones = [...nav.querySelectorAll('.fi-sidebar-nav-groups .fi-sidebar-item')].map((renglon) => {
                    const etiqueta = etiquetaDe(renglon);
                    const grupo = renglon.closest('.fi-sidebar-group');

                    return {
                        renglon,
                        grupo,
                        etiqueta: normal(etiqueta),
                        propio: normal(`${etiqueta} ${palabras[etiqueta] || ''}`),
                        delGrupo: normal(grupo ? grupo.dataset.groupLabel : ''),
                    };
                });

                let quedan = renglones.filter((r) => sirve(r.propio));

                if (quedan.length === 0) {
                    quedan = renglones.filter((r) => sirve(`${r.propio} ${r.delGrupo}`));
                }

                nav.classList.toggle('sihla-filtrando', filtrando);

                renglones.forEach((r) => r.renglon.classList.toggle('sihla-oculto', filtrando && ! quedan.includes(r)));
                quedan.forEach((r) => r.renglon.parentElement.closest('.fi-sidebar-item')?.classList.remove('sihla-oculto'));

                nav.querySelectorAll('.fi-sidebar-nav-groups > .fi-sidebar-group').forEach((grupo) => {
                    grupo.classList.toggle('sihla-oculto', filtrando && ! quedan.some((r) => r.grupo === grupo));
                });

                // Queda elegida la que EMPIEZA con lo tecleado: «caja» elige Caja.
                visibles = filtrando ? quedan.map((r) => r.renglon) : [];
                elegido = Math.max(0, quedan.findIndex((r) => r.etiqueta.startsWith(fichas[0] || '')));
                marcar();

                nada.hidden = ! filtrando || visibles.length > 0;
                nada.textContent = `Nada en el menú con «${entrada.value.trim()}».`;
            };

            entrada.addEventListener('input', filtrar);

            entrada.addEventListener('keydown', (evento) => {
                if (evento.key === 'Escape') {
                    entrada.value = '';
                    filtrar();
                    entrada.blur();

                    return;
                }

                if (visibles.length === 0) {
                    return;
                }

                if (evento.key === 'ArrowDown' || evento.key === 'ArrowUp') {
                    evento.preventDefault();
                    elegido = (elegido + (evento.key === 'ArrowDown' ? 1 : -1) + visibles.length) % visibles.length;
                    marcar();
                    visibles[elegido].scrollIntoView({ block: 'nearest' });
                }

                if (evento.key === 'Enter') {
                    evento.preventDefault();
                    visibles[elegido].querySelector(':scope > .fi-sidebar-item-btn')?.click();
                }
            });

            document.addEventListener('keydown', (evento) => {
                if (evento.key !== '/' || evento.ctrlKey || evento.metaKey || evento.altKey) {
                    return;
                }

                const donde = evento.target;

                if (donde instanceof Element && (donde.isContentEditable || donde.closest('input, textarea, select'))) {
                    return;
                }

                evento.preventDefault();

                const barra = window.Alpine?.store('sidebar');

                if (barra && ! barra.isOpen) {
                    barra.open();
                }

                requestAnimationFrame(() => {
                    entrada.focus();
                    entrada.select();
                });
            });

            // El grupo de la pantalla en que se está nunca queda recogido.
            document.addEventListener('alpine:initialized', () => {
                const barra = window.Alpine?.store('sidebar');
                const activo = nav.querySelector('.fi-sidebar-nav-groups > .fi-sidebar-group.fi-active');
                const nombre = activo?.dataset.groupLabel;

                if (barra && nombre && Array.isArray(barra.collapsedGroups) && barra.groupIsCollapsed(nombre)) {
                    barra.toggleCollapsedGroup(nombre);
                }
            });
        })();
    </script>
</div>
