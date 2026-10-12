{{--
    Un recibo en la lista de «Caja».

    Recibe `$abono` (con medios y cuenta.encuentro.persona cargados) y
    `$anulado`. El nombre del paciente va primero porque es lo que la
    cajera reconoce; el número de cuenta va debajo, para buscarlo.

    El motivo de anulación se pide en una fila propia, debajo del recibo,
    y vive en el estado de Livewire: un modal con argumentos se cierra
    solo en cada re-render (lección del desglose del paquete).
--}}
@php($persona = $abono->cuenta?->encuentro?->persona)
@php($medios = $abono->medios)
@php($pidiendoMotivo = ! $anulado && $this->abonoAAnular === $abono->id)

<tr wire:key="abono-{{ $abono->id }}" @class(['sihla-caja-fila-anulada' => $anulado])>
    <td class="sihla-caja-hora">
        {{ $abono->recibido_en->format('H:i') }}
        @unless ($abono->recibido_en->isToday())
            <span>{{ $abono->recibido_en->format('d/m/Y') }}</span>
        @endunless
    </td>

    <td class="sihla-caja-recibo">{{ $abono->numero }}</td>

    <td class="sihla-caja-paciente">
        <span class="sihla-caja-paciente-nombre">{{ $persona?->nombreCompleto() ?? 'Paciente sin nombre' }}</span>
        <span class="sihla-caja-sub">
            {{ $abono->cuenta?->numero }}
            @if (filled($abono->entregado_por))
                · lo dejó {{ $abono->entregado_por }}
            @endif
        </span>

        @if ($anulado && filled($abono->motivo_anulacion))
            <span class="sihla-caja-motivo-anulado">Motivo: {{ $abono->motivo_anulacion }}</span>
        @endif
    </td>

    <td class="sihla-caja-medios">
        @foreach ($medios as $medio)
            <span>
                {{ $medio->descripcion() }}
                {{-- Con un solo medio el monto ya está en «Monto»: repetirlo es ruido. --}}
                @if ($medios->count() > 1)
                    <em>L {{ number_format((float) $medio->monto, 2) }}</em>
                @endif
            </span>
        @endforeach
    </td>

    <td class="sihla-caja-num sihla-caja-monto">L {{ number_format((float) $abono->total, 2) }}</td>

    <td class="sihla-caja-acc">
        @if ($anulado)
            <x-filament::badge color="danger" size="sm">Anulado</x-filament::badge>
        @elseif (! $pidiendoMotivo)
            <x-filament::link tag="button" color="gray" size="sm" wire:click="pedirElMotivo({{ $abono->id }})">
                Anular
            </x-filament::link>
        @endif
    </td>
</tr>

@if ($pidiendoMotivo)
    <tr wire:key="motivo-{{ $abono->id }}" class="sihla-caja-fila-motivo">
        <td colspan="6">
            <label for="sihla-caja-motivo-campo">
                ¿Por qué se anula el recibo {{ $abono->numero }} de L {{ number_format((float) $abono->total, 2) }}?
            </label>

            <div class="sihla-caja-motivo-fila">
                <x-filament::input.wrapper class="sihla-caja-motivo-campo">
                    <x-filament::input
                        id="sihla-caja-motivo-campo"
                        type="text"
                        wire:model="motivoDeAnular"
                        wire:keydown.enter="anularElAbono"
                        style="text-transform: uppercase"
                        placeholder="Se digitó el monto equivocado…"
                        autofocus
                    />
                </x-filament::input.wrapper>

                <x-filament::button color="danger" size="sm" wire:click="anularElAbono">
                    Anular el recibo
                </x-filament::button>

                <x-filament::button color="gray" size="sm" outlined wire:click="cancelarAnulacion">
                    Cancelar
                </x-filament::button>
            </div>

            <p class="sihla-caja-nota">
                Diez caracteres mínimo. El recibo no se borra: queda anulado con tu nombre y la hora,
                y sale del saldo de la cuenta y de la gaveta.
            </p>
        </td>
    </tr>
@endif
