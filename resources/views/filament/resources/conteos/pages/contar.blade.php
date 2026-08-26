{{--
    La pantalla que se usa de pie frente al estante.

    Dos bloques y nada más: el de capturar arriba, la lista de lo que
    falta abajo. Todo lo que se pudiera agregar acá —resúmenes, gráficas,
    el valor del inventario— sería algo que alguien tiene que saltear
    mientras cuenta.

    El botón dice el número de la línea que se está por registrar, no
    «Guardar»: a las once de la noche, un botón que dice qué va a hacer
    vale más que uno bonito.
--}}
<x-filament-panels::page>
    <form wire:submit="registrar" class="space-y-4">
        {{ $this->form }}

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                El conteo es a ciegas: esta pantalla nunca muestra lo que el sistema espera.
                Contá lo que ves.
            </p>

            <x-filament::button
                type="submit"
                size="lg"
                icon="heroicon-o-check"
                wire:loading.attr="disabled"
                wire:target="registrar"
            >
                <span wire:loading.remove wire:target="registrar">Registrar lo contado</span>
                <span wire:loading wire:target="registrar">Registrando…</span>
            </x-filament::button>
        </div>
    </form>

    <div class="mt-6">
        {{ $this->table }}
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
