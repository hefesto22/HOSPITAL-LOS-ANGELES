{{--
    La factura adentro de un modal.

    ─────────────────────────────────────────────────────────────────────
    POR QUÉ UN IFRAME Y NO EL HTML PEGADO ACÁ
    ─────────────────────────────────────────────────────────────────────

    Porque `facturas.imprimir` es un documento completo —con su `@page`,
    su tamaño carta y sus reglas de `@media print`— y esas reglas no
    sobreviven pegadas adentro del panel: el CSS de Filament se les monta
    encima y lo que sale de la impresora deja de ser el papel que el SAR
    y la cajera conocen.

    Adentro del iframe la hoja es la MISMA página que ya se imprimía
    antes en pestaña aparte, con `?incrustada=1` para que no dibuje su
    propia barra de botones.

    ⚠️ Se imprime `contentWindow.print()` y no `window.print()`: el
    segundo mandaría a la impresora el panel entero —menú, tabla, modal—
    con la factura escondida adentro.
--}}
<div
    x-data="{
        imprimir() {
            const marco = this.$refs.papel;

            if (! marco?.contentWindow) {
                return;
            }

            marco.contentWindow.focus();
            marco.contentWindow.print();
        },
    }"
    class="space-y-3"
>
    <div class="flex justify-end">
        <x-filament::button icon="heroicon-o-printer" x-on:click="imprimir()">
            Imprimir
        </x-filament::button>
    </div>

    <iframe
        x-ref="papel"
        src="{{ route('facturas.imprimir', ['factura' => $factura, 'incrustada' => 1]) }}"
        title="Factura {{ $factura->numero }}"
        class="w-full rounded-lg border border-gray-200 bg-white dark:border-white/10"
        style="height: 70vh"
    ></iframe>
</div>
