{{--
    La gaveta está abierta en un turno que no es de quien mira
    (13-oct-2026).

    No es un error ni un bloqueo del sistema: es la respuesta a «¿por qué
    no me aparece Abrir turno?». Dice en qué turno está la gaveta, quién
    la abrió y quiénes la pueden cerrar, para que la cajera del turno B
    sepa a quién buscar en vez de pedir la clave de otro.

    Quien la abrió ya no está en la lista de los que cierran (Mauricio,
    31-oct-2026): si es de otro turno, haberla abierto no la hace suya.
--}}
<div class="tc">
    <div class="tc-franja">
        <x-filament::icon icon="heroicon-o-lock-closed" class="tc-franja-icono" />
        <div>
            Lo abrió <strong>{{ $abrio }}</strong> {{ $desde }} <span class="tc-tenue">({{ $hace }})</span>.
            La gaveta es una sola: hasta que se cierre el {{ $nombre }} no se abre otro turno.
        </div>
    </div>

    <div style="display: flex; flex-direction: column; gap: .5rem;">
        <span class="tc-titulo">Cobran y cierran el {{ $nombre }}</span>
        <ul class="tc-lista">
            <li>
                <x-filament::icon icon="heroicon-m-check-circle" />
                <span>
                    @if ($asignados === [])
                        Quien tenga el {{ $nombre }} asignado <span class="tc-tenue">(hoy nadie lo tiene)</span>
                    @else
                        Los del {{ $nombre }}: <strong>{{ implode(', ', $asignados) }}</strong>
                    @endif
                </span>
            </li>
            <li>
                <x-filament::icon icon="heroicon-m-check-circle" />
                <span>La administración</span>
            </li>
        </ul>
    </div>

    <p class="tc-chico tc-tenue">
        @if ($mio === null)
            No tenés un turno asignado en tu usuario.
        @else
            Tu turno es el <strong>{{ $mio }}</strong>: lo abrís cuando cierren este.
        @endif
        Si hoy cubrís el {{ $nombre }}, pedí que te lo asignen en tu usuario.
    </p>
</div>

@include('filament.forms.partials.estilos-turno')
