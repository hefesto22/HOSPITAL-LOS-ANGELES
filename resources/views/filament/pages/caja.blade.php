{{--
    La gaveta de quien está cobrando.

    Un solo turno —el suyo— y tres números que tiene que poder leer sin
    pensar: con cuánto abrió, cuánto entró en efectivo y cuánto debería
    haber. Los turnos de todos, con sus diferencias, están en «Turnos de
    caja»: esa es la pregunta de dirección, no la del mostrador.

    El CSS de Filament viene precompilado, así que lo que no usa el panel
    no existe acá (§9.A7): de ahí el `<style>` propio.
--}}
<x-filament-panels::page>
    @php($turno = $this->turno())

    @if ($turno === null)
        <div class="sihla-caja-vacia">
            <x-filament::icon icon="heroicon-o-lock-closed" class="sihla-caja-vacia-icono" />

            <p class="sihla-caja-vacia-titulo">No tenés un turno abierto.</p>

            <p class="sihla-caja-vacia-texto">
                Sin turno no se puede recibir plata: el efectivo entra a una gaveta que alguien cuenta al final,
                y un abono sin turno es plata que nadie cuadra contra billetes.
            </p>

            {{ $this->abrirTurnoAction }}
        </div>
    @else
        @php($porForma = $this->porFormaDePago())
        @php($abonos = $this->abonosDelTurno())

        <div class="sihla-caja">
            <div class="sihla-caja-cabecera">
                <div>
                    <p class="sihla-caja-numero">{{ $turno->etiqueta() }}</p>
                    <p class="sihla-caja-desde">
                        Abierto por {{ $turno->usuario?->name ?? 'vos' }} ·
                        {{ $turno->abierto_en->format('d/m/Y H:i') }}
                    </p>
                </div>

                {{ $this->cerrarTurnoAction }}
            </div>

            <div class="sihla-caja-numeros">
                <div class="sihla-caja-dato">
                    <span>Fondo inicial</span>
                    <strong>L {{ number_format((float) $turno->fondo_inicial, 2) }}</strong>
                </div>

                @foreach (\App\Domain\Enums\FormaDePago::cases() as $forma)
                    <div class="sihla-caja-dato">
                        <span>{{ $forma->etiqueta() }}</span>
                        <strong>L {{ number_format((float) $porForma[$forma->value]->redondeado(2), 2) }}</strong>
                    </div>
                @endforeach

                {{--
                    🔴 El número del arqueo. Es efectivo y nada más: lo de
                    tarjeta lo liquida el POS y lo de transferencia el
                    banco, así que contarlos acá daría un sobrante que no
                    existe todas las noches.
                --}}
                <div class="sihla-caja-dato sihla-caja-esperado">
                    <span>Debería haber en efectivo</span>
                    <strong>L {{ number_format((float) $turno->efectivoEsperado()->redondeado(2), 2) }}</strong>
                </div>
            </div>

            <div class="sihla-caja-lista">
                <p class="sihla-caja-titulo">
                    RECIBOS DE ESTE TURNO · {{ $abonos->count() }}
                </p>

                @if ($abonos->isEmpty())
                    <p class="sihla-caja-nada">
                        Todavía no entró nada. Los abonos se reciben desde la cuenta del paciente,
                        en «Cuentas abiertas».
                    </p>
                @else
                    <table class="sihla-caja-tabla">
                        <tbody>
                            @foreach ($abonos as $abono)
                                <tr wire:key="abono-{{ $abono->id }}" @class(['sihla-caja-anulado' => ! $abono->estado->bajaElSaldo()])>
                                    <td class="sihla-caja-recibo">
                                        {{ $abono->numero }}
                                        <span class="sihla-caja-hora">{{ $abono->recibido_en->format('H:i') }}</span>
                                    </td>

                                    <td>
                                        {{ $abono->cuenta?->numero }}
                                        @if ($abono->entregado_por)
                                            <span class="sihla-caja-quien">lo dejó {{ $abono->entregado_por }}</span>
                                        @endif
                                    </td>

                                    <td class="sihla-caja-medios">{{ $abono->resumenDeMedios() }}</td>

                                    <td class="sihla-caja-monto">
                                        L {{ number_format((float) $abono->total, 2) }}
                                    </td>

                                    <td class="sihla-caja-acc">
                                        @if ($abono->estado->bajaElSaldo())
                                            <button
                                                type="button"
                                                wire:click="pedirElMotivo({{ $abono->id }})"
                                                class="sihla-caja-boton"
                                            >Anular</button>
                                        @else
                                            <span class="sihla-caja-sello">ANULADO</span>
                                        @endif
                                    </td>
                                </tr>

                                {{--
                                    El motivo se pide en la misma fila y
                                    vive en el estado de Livewire: un
                                    modal con argumentos se cierra solo en
                                    cada re-render (lección del desglose
                                    del paquete).
                                --}}
                                @if ($this->abonoAAnular === $abono->id)
                                    <tr wire:key="motivo-{{ $abono->id }}">
                                        <td colspan="5" class="sihla-caja-motivo">
                                            <label for="sihla-caja-motivo-campo">
                                                ¿Por qué se anula el recibo {{ $abono->numero }}?
                                            </label>

                                            <div class="sihla-caja-motivo-fila">
                                                <input
                                                    id="sihla-caja-motivo-campo"
                                                    type="text"
                                                    wire:model="motivoDeAnular"
                                                    wire:keydown.enter="anularElAbono"
                                                    class="sihla-caja-campo"
                                                    placeholder="Se digitó el monto equivocado…"
                                                    autofocus
                                                >
                                                <button type="button" wire:click="anularElAbono" class="sihla-caja-boton sihla-caja-peligro">
                                                    Anular
                                                </button>
                                                <button type="button" wire:click="cancelarAnulacion" class="sihla-caja-boton">
                                                    Cancelar
                                                </button>
                                            </div>

                                            <p class="sihla-caja-ayuda">
                                                Diez caracteres mínimo. El recibo no se borra: queda anulado, con tu nombre y la hora.
                                            </p>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    @endif

    <style>
        .sihla-caja { display: flex; flex-direction: column; gap: 1rem; }

        .sihla-caja-cabecera {
            display: flex; align-items: center; justify-content: space-between;
            gap: 1rem; flex-wrap: wrap;
            padding: 1rem 1.25rem;
            border: 1px solid rgb(var(--gray-200)); border-radius: .75rem;
            background: rgb(var(--gray-50));
        }
        .dark .sihla-caja-cabecera { border-color: rgb(var(--gray-700)); background: rgb(var(--gray-900)); }

        .sihla-caja-numero { font-weight: 700; font-size: 1.05rem; }
        .sihla-caja-desde { font-size: .8rem; color: rgb(var(--gray-500)); }

        .sihla-caja-numeros {
            display: grid; gap: .75rem;
            grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
        }

        .sihla-caja-dato {
            display: flex; flex-direction: column; gap: .15rem;
            padding: .75rem 1rem;
            border: 1px solid rgb(var(--gray-200)); border-radius: .75rem;
        }
        .dark .sihla-caja-dato { border-color: rgb(var(--gray-700)); }
        .sihla-caja-dato span { font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; color: rgb(var(--gray-500)); }
        .sihla-caja-dato strong { font-size: 1.1rem; font-variant-numeric: tabular-nums; }

        .sihla-caja-esperado { border-color: rgb(var(--warning-400)); }
        .sihla-caja-esperado strong { color: rgb(var(--warning-600)); }

        .sihla-caja-lista {
            border: 1px solid rgb(var(--gray-200)); border-radius: .75rem; overflow: hidden;
        }
        .dark .sihla-caja-lista { border-color: rgb(var(--gray-700)); }

        .sihla-caja-titulo {
            padding: .65rem 1rem; font-size: .72rem; letter-spacing: .06em;
            color: rgb(var(--gray-500)); background: rgb(var(--gray-50));
        }
        .dark .sihla-caja-titulo { background: rgb(var(--gray-900)); }

        .sihla-caja-nada { padding: 1.25rem 1rem; font-size: .85rem; color: rgb(var(--gray-500)); }

        .sihla-caja-tabla { width: 100%; border-collapse: collapse; font-size: .85rem; }
        .sihla-caja-tabla td { padding: .55rem 1rem; border-top: 1px solid rgb(var(--gray-100)); vertical-align: middle; }
        .dark .sihla-caja-tabla td { border-color: rgb(var(--gray-800)); }

        .sihla-caja-recibo { font-weight: 600; white-space: nowrap; }
        .sihla-caja-hora { display: block; font-weight: 400; font-size: .72rem; color: rgb(var(--gray-500)); }
        .sihla-caja-quien { display: block; font-size: .72rem; color: rgb(var(--gray-500)); }
        .sihla-caja-medios { color: rgb(var(--gray-500)); }
        .sihla-caja-monto { text-align: right; font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .sihla-caja-acc { text-align: right; white-space: nowrap; }

        .sihla-caja-anulado td { opacity: .5; text-decoration: line-through; }
        .sihla-caja-sello { font-size: .7rem; letter-spacing: .06em; color: rgb(var(--danger-500)); }

        .sihla-caja-boton {
            padding: .2rem .6rem; font-size: .75rem; border-radius: .375rem;
            border: 1px solid rgb(var(--gray-300)); background: transparent;
        }
        .dark .sihla-caja-boton { border-color: rgb(var(--gray-600)); }
        .sihla-caja-boton:hover { background: rgb(var(--gray-100)); }
        .dark .sihla-caja-boton:hover { background: rgb(var(--gray-800)); }
        .sihla-caja-peligro { border-color: rgb(var(--danger-400)); color: rgb(var(--danger-600)); }

        .sihla-caja-motivo { background: rgb(var(--gray-50)); }
        .dark .sihla-caja-motivo { background: rgb(var(--gray-900)); }
        .sihla-caja-motivo label { display: block; font-size: .8rem; margin-bottom: .35rem; }
        .sihla-caja-motivo-fila { display: flex; gap: .5rem; align-items: center; }
        .sihla-caja-campo {
            flex: 1; padding: .3rem .6rem; font-size: .85rem; border-radius: .375rem;
            border: 1px solid rgb(var(--gray-300)); background: transparent;
        }
        .dark .sihla-caja-campo { border-color: rgb(var(--gray-600)); }
        .sihla-caja-ayuda { margin-top: .35rem; font-size: .72rem; color: rgb(var(--gray-500)); }

        .sihla-caja-vacia {
            display: flex; flex-direction: column; align-items: center; gap: .75rem;
            padding: 3rem 1.5rem; text-align: center;
            border: 1px dashed rgb(var(--gray-300)); border-radius: .75rem;
        }
        .dark .sihla-caja-vacia { border-color: rgb(var(--gray-700)); }
        .sihla-caja-vacia-icono { width: 2rem; height: 2rem; color: rgb(var(--gray-400)); }
        .sihla-caja-vacia-titulo { font-weight: 600; }
        .sihla-caja-vacia-texto { max-width: 34rem; font-size: .85rem; color: rgb(var(--gray-500)); }
    </style>
</x-filament-panels::page>
