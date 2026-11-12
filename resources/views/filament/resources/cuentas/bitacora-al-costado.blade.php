{{--
    La bitácora de una cuenta, al costado de «Todas las cuentas» (Mauricio,
    11-nov-2026: «que tenga un acceso a ver bitácora»). Arriba, en qué
    etapa va y cuánto falta; debajo, todo lo que pasó, con quién, cuándo y
    por qué; al final, el enlace a la ficha entera.

    Recibe `$cuenta`. Abrirla queda en la bitácora de lectura: lo hace la
    acción al montarse (`CuentasTable::registrarQueSeAbrioLaBitacora()`).

    ⚠️ Lo de PHP va en un bloque, nunca en una línea suelta (§9.M18).
--}}
@php
    $total = \App\Domain\ValueObjects\Decimal::de($cuenta->total);
    $abonado = $cuenta->abonado();
    $falta = \App\Support\FichaDeLaCuenta::loQueFalta($cuenta->estado, $total, $abonado) ?? '—';
    $colorDeLaFalta = \App\Support\FichaDeLaCuenta::colorDeLoQueFalta($cuenta->estado, $total, $abonado);
    $ficha = \App\Filament\Resources\Cuentas\CuentaResource::getUrl('view', ['record' => $cuenta]);
@endphp

<div class="sihla-costado">
    <div class="sihla-costado-resumen">
        <div class="sihla-costado-sellos">
            <x-filament::badge :color="$cuenta->estado->color()" :icon="\App\Support\FichaDeLaCuenta::iconoDelEstado($cuenta->estado)">
                {{ \App\Support\FichaDeLaCuenta::estadoEnPalabras($cuenta->estado) }}
            </x-filament::badge>

            <x-filament::badge :color="$cuenta->convenio->tipo->color()">
                {{ $cuenta->convenio->nombre }}
            </x-filament::badge>
        </div>

        <dl class="sihla-costado-cifras">
            <div>
                <dt>Total</dt>
                <dd>{{ $cuenta->saldo()->formateado() }}</dd>
            </div>
            <div>
                <dt>Abonado</dt>
                <dd>{{ $abonado->esCero() ? '—' : \App\Domain\ValueObjects\Monto::de($abonado)->formateado() }}</dd>
            </div>
            <div class="sihla-costado-falta sihla-costado-falta-{{ $colorDeLaFalta }}">
                <dt>Falta</dt>
                <dd>{{ $falta }}</dd>
            </div>
        </dl>
    </div>

    @include('filament.pages.partials.historial-de-la-cuenta', [
        'cuenta'   => $cuenta,
        'abierto'  => true,
        'suelto'   => true,
        'visibles' => 30,
    ])

    <a href="{{ $ficha }}" class="sihla-costado-ficha">
        Ver la ficha completa, con los cargos uno por uno
        <x-filament::icon icon="heroicon-m-arrow-right" class="sihla-costado-flecha" />
    </a>
</div>

<style>
    .sihla-costado { display: flex; flex-direction: column; gap: .9rem; }

    .sihla-costado-resumen {
        display: flex; flex-direction: column; gap: .7rem;
        padding: .85rem 1rem; border-radius: .75rem;
        border: 1px solid rgb(0 0 0 / .08); background: var(--gray-50);
    }
    .dark .sihla-costado-resumen { border-color: rgb(255 255 255 / .08); background: rgb(255 255 255 / .03); }

    .sihla-costado-sellos { display: flex; flex-wrap: wrap; gap: .4rem; }

    .sihla-costado-cifras { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .75rem; margin: 0; }
    .sihla-costado-cifras dt { font-size: .7rem; font-weight: 600; color: var(--gray-500); }
    .dark .sihla-costado-cifras dt { color: var(--gray-400); }
    .sihla-costado-cifras dd {
        margin: .1rem 0 0; font-size: .95rem; font-weight: 700;
        font-variant-numeric: tabular-nums; white-space: nowrap;
    }
    .sihla-costado-falta-warning dd { color: var(--warning-600); }
    .sihla-costado-falta-success dd { color: var(--success-600); }
    .sihla-costado-falta-info dd { color: var(--info-600); }
    .dark .sihla-costado-falta-warning dd { color: var(--warning-400); }
    .dark .sihla-costado-falta-success dd { color: var(--success-400); }
    .dark .sihla-costado-falta-info dd { color: var(--info-400); }

    .sihla-costado-ficha {
        display: inline-flex; align-items: center; gap: .35rem; align-self: flex-start;
        font-size: .8rem; font-weight: 600; color: var(--primary-600);
    }
    .dark .sihla-costado-ficha { color: var(--primary-400); }
    .sihla-costado-ficha:hover { text-decoration: underline; }
    .sihla-costado-flecha { width: 1rem; height: 1rem; }
</style>
