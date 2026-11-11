{{--
    La pestaña «Historial» de Ver cuenta: el mismo historial del modal de
    cargar, abierto de entrada. `$getRecord()` es la cuenta de la ficha.
--}}
@php($cuentaDeLaFicha = $getRecord())

@if ($cuentaDeLaFicha instanceof \App\Models\Cuenta)
    @include('filament.pages.partials.historial-de-la-cuenta', [
        'cuenta'  => $cuentaDeLaFicha,
        'abierto' => true,
        'suelto'  => true,
    ])
@endif
