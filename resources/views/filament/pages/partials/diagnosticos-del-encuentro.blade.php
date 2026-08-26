{{--
    Lo que ya está escrito en el expediente de esta atención.

    ─────────────────────────────────────────────────────────────────────
    LOS TACHADOS TAMBIÉN SE MUESTRAN
    ─────────────────────────────────────────────────────────────────────

    Un diagnóstico corregido o retractado NO desaparece (ADR-0004). Lo
    que un perito busca no es el diagnóstico final: es qué se pensó,
    cuándo se cambió de idea y quién la cambió. Se ve tachado, con su
    motivo, y eso es exactamente lo que tiene que verse.
--}}
@php($diagnosticos = $diagnosticos ?? collect())

<div class="sihla-dx">
    @if ($diagnosticos->isEmpty())
        <p class="sihla-dx-vacio">
            Todavía no tiene diagnóstico. Sin él, esta cuenta no se le puede reclamar a una aseguradora.
        </p>
    @else
        @foreach ($diagnosticos as $dx)
            <div @class(['sihla-dx-fila', 'sihla-dx-tachado' => ! $dx->estado->esVigente()])>
                <div class="sihla-dx-encabezado">
                    <x-filament::badge :color="$dx->tipo->color()" size="sm">
                        {{ $dx->tipo->etiqueta() }}
                    </x-filament::badge>

                    <span class="sihla-dx-momento">{{ $dx->momento->etiqueta() }}</span>

                    @unless ($dx->confirmado)
                        <span class="sihla-dx-presuntivo">presuntivo</span>
                    @endunless

                    @if ($dx->esNotificable())
                        {{--
                            🔴 De acá sale el reporte del Art. 180. La marca
                            vive en el CATÁLOGO, no en este diagnóstico: que
                            la obligación legal dependa de que alguien se
                            acuerde de tildar una casilla es lo mismo que no
                            tenerla.
                        --}}
                        <x-filament::badge color="warning" size="sm">
                            Notificable a SESAL
                        </x-filament::badge>
                    @endif

                    @unless ($dx->estado->esVigente())
                        <x-filament::badge :color="$dx->estado->color()" size="sm">
                            {{ $dx->estado->etiqueta() }}
                        </x-filament::badge>
                    @endunless
                </div>

                <p class="sihla-dx-texto">{{ $dx->cie10?->etiqueta() }}</p>

                @if (filled($dx->observacion))
                    <p class="sihla-dx-nota">{{ $dx->observacion }}</p>
                @endif

                @if (filled($dx->motivo_correccion))
                    <p class="sihla-dx-nota">Motivo: {{ $dx->motivo_correccion }}</p>
                @endif

                <p class="sihla-dx-firma">
                    {{ $dx->medico?->name ?? 'Sin autor' }}
                    · {{ $dx->diagnosticado_en?->format('d/m/Y H:i') }}
                </p>
            </div>
        @endforeach
    @endif
</div>

<style>
    .sihla-dx { display: flex; flex-direction: column; gap: .5rem; margin-top: .75rem; }

    .sihla-dx-vacio {
        padding: .9rem; border-radius: .6rem; border: 1px dashed rgb(212 212 216);
        font-size: .85rem; color: rgb(113 113 122); text-align: center;
    }

    .sihla-dx-fila {
        display: flex; flex-direction: column; gap: .3rem;
        padding: .65rem .8rem; border-radius: .6rem;
        border: 1px solid rgb(228 228 231); background: rgb(250 250 250);
    }
    .sihla-dx-tachado { opacity: .55; }
    .sihla-dx-tachado .sihla-dx-texto { text-decoration: line-through; }

    .sihla-dx-encabezado { display: flex; align-items: center; gap: .4rem; flex-wrap: wrap; }
    .sihla-dx-momento { font-size: .75rem; color: rgb(113 113 122); }
    .sihla-dx-presuntivo {
        font-size: .7rem; font-style: italic; color: rgb(161 98 7);
    }

    .sihla-dx-texto { font-size: .9rem; font-weight: 500; color: rgb(24 24 27); margin: 0; }
    .sihla-dx-nota { font-size: .8rem; color: rgb(82 82 91); margin: 0; }
    .sihla-dx-firma { font-size: .72rem; color: rgb(113 113 122); margin: 0; }

    .dark .sihla-dx-fila { border-color: rgb(63 63 70); background: rgb(39 39 42); }
    .dark .sihla-dx-vacio { border-color: rgb(63 63 70); color: rgb(161 161 170); }
    .dark .sihla-dx-texto { color: rgb(244 244 245); }
    .dark .sihla-dx-nota { color: rgb(212 212 216); }
</style>
