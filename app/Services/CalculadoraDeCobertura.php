<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\ValueObjects\CoberturaAplicada;
use App\Domain\ValueObjects\Decimal;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Tarifario;

/**
 * Cuánto pone el pagador en esta línea — decidido AHORA, no al cerrar.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ ESTO NO PUEDE SER UN TRABAJO DE FIN DE MES
 * ─────────────────────────────────────────────────────────────────────
 *
 * §8.6.3: «La división paciente/aseguradora se calcula en el momento del
 * cargo, no al cierre. Calcularlo al final significa que nunca se supo
 * cuánto debía el paciente mientras estaba internado — y ya se fue.»
 *
 * Y hay una razón técnica encima de la operativa: si la división se
 * calculara al cerrar, habría que EDITAR cargos ya asentados, y eso el
 * §9.0.3 no lo permite. Calcularla al momento es lo único compatible con
 * una tabla append-only.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA ESCALERA DE ELEGIBILIDAD
 * ─────────────────────────────────────────────────────────────────────
 *
 *   1. ¿El pagador es un tercero? Contado no cubre nada, por definición.
 *   2. ¿Hay fila de tarifario firmada con este pagador para este ítem?
 *      Entonces manda su bandera `elegible` — es donde se declaran las
 *      exclusiones de la póliza (§8.5-6).
 *   3. Si no hay fila propia, manda `cubre_por_defecto` del convenio.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ LO QUE ESTA CLASE NO HACE TODAVÍA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Deducible anual, precertificación, carencias y máximo vitalicio son del
 * bloque 4b, porque necesitan acumuladores por persona y año póliza
 * (§8.6.5). Lo que sí hace es dejar el snapshot completo en cada cargo,
 * así que cuando lleguen, no habrá que recalcular nada hacia atrás.
 */
final class CalculadoraDeCobertura
{
    public function para(Cuenta $cuenta, ?Tarifario $fila): CoberturaAplicada
    {
        $convenio = $cuenta->convenio;

        if (! $convenio->tipo->pagaUnTercero()) {
            return CoberturaAplicada::ninguna(
                'Paga el paciente: '.$convenio->nombre.' no es un pagador tercero.'
            );
        }

        if (! $this->esElegible($convenio, $fila)) {
            return CoberturaAplicada::ninguna(
                $convenio->nombre.' no cubre este ítem: se cobra completo al paciente.'
            );
        }

        $fraccion = Decimal::de((string) $convenio->cobertura_fraccion);

        if ($fraccion->esCero()) {
            return CoberturaAplicada::ninguna(
                'El convenio '.$convenio->nombre.' no tiene porcentaje de cobertura cargado.'
            );
        }

        $disponible = $cuenta->disponibleDelTope();

        /*
         * ⚠️ Con el tope agotado NO se devuelve «ninguna».
         *
         * Sería lo intuitivo y sería un error: el cargo quedaría
         * congelado con `elegible = false` y cobertura cero, o sea
         * indistinguible de un ítem que la póliza EXCLUYE. En el bloque
         * 12 esas dos cosas se negocian distinto —lo excluido no se
         * reclama, lo topado sí se discute— y para entonces el dato ya no
         * se puede reconstruir.
         *
         * Se conserva la elegibilidad y el porcentaje pactado, y el tope
         * hace su trabajo dejando la porción de la aseguradora en cero.
         * «Topado» se lee como `cobertura_fraccion > 0` con
         * `porcion_aseguradora = 0`.
         */
        return CoberturaAplicada::segunElConvenio($convenio, $fraccion, $disponible);
    }

    /**
     * La fila firmada gana; si no la hay, la política general del
     * convenio. Nunca un `if` con el nombre de una aseguradora adentro
     * (§1.1).
     */
    private function esElegible(Convenio $convenio, ?Tarifario $fila): bool
    {
        if ($fila instanceof Tarifario && $fila->convenio_id !== null) {
            return (bool) $fila->elegible;
        }

        return (bool) $convenio->cubre_por_defecto;
    }
}
