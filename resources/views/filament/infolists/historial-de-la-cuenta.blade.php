{{--
    La bitácora en «Ver cuenta», debajo de la ficha: el mismo historial
    del modal de cargar, abierto de entrada y con más renglones a la
    vista. `$getRecord()` es la cuenta de la ficha.
--}}
@php
    $cuentaDeLaFicha = $getRecord();
@endphp

@if ($cuentaDeLaFicha instanceof \App\Models\Cuenta)
    @include('filament.pages.partials.historial-de-la-cuenta', [
        'cuenta'   => $cuentaDeLaFicha,
        'abierto'  => true,
        'suelto'   => true,
        'visibles' => 15,
    ])
@endif
