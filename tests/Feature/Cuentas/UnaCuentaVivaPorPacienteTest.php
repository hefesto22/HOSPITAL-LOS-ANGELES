<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoCuenta;
use App\Domain\Enums\TipoEncuentro;
use App\Domain\Exceptions\CuentaException;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Expediente;
use App\Models\Persona;
use App\Models\Sede;
use App\Services\AbridorDeEncuentro;

/**
 * 🔴 UN PACIENTE, UNA CUENTA VIVA (ADR-0007).
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ NO ES UNA MOLESTIA DE PANTALLA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Dos cuentas abiertas del mismo paciente terminan en dos facturas, y una
 * atención asegurada se cubre por UNA sola: si se presentan dos
 * documentos, la aseguradora procesa uno y rechaza el otro, o paga los
 * dos parcialmente. La diferencia la absorbe el hospital, y aparece
 * semanas después. Con seguro externo pasa igual, solo que lo sufre el
 * paciente con la factura en la mano.
 */
function abridor(): AbridorDeEncuentro
{
    return app(AbridorDeEncuentro::class);
}

/**
 * @return array{Persona, Expediente, Convenio, Sede}
 */
function unPacienteListo(): array
{
    $sede = Sede::factory()->create();
    $persona = Persona::factory()->create();
    $expediente = Expediente::factory()->for($persona)->for($sede)->create();
    $convenio = Convenio::factory()->contado()->create();

    return [$persona, $expediente, $convenio, $sede];
}

it('🔴 no deja abrir una segunda cuenta si el paciente ya tiene una viva', function (): void {
    [$persona, $expediente, $convenio, $sede] = unPacienteListo();

    abridor()->abrir(
        persona: $persona,
        expediente: $expediente,
        tipo: TipoEncuentro::Ambulatorio,
        convenio: $convenio,
        sede: $sede,
    );

    abridor()->abrir(
        persona: $persona,
        expediente: $expediente,
        tipo: TipoEncuentro::Ambulatorio,
        convenio: $convenio,
        sede: $sede,
    );
})->throws(CuentaException::class)
    ->note('🔴 El filtro que ya existía solo miraba la CAMA, y solo cuando el ingreso nuevo la ocupaba. Un paciente internado al que le abrían una consulta externa pasaba limpio y terminaba con dos cuentas vivas — dos facturas para una misma estadía.');

it('el aviso dice a qué cuenta ir, no solo que no se puede', function (): void {
    [$persona, $expediente, $convenio, $sede] = unPacienteListo();

    $primera = abridor()->abrir(
        persona: $persona,
        expediente: $expediente,
        tipo: TipoEncuentro::Ambulatorio,
        convenio: $convenio,
        sede: $sede,
    );

    try {
        abridor()->abrir(
            persona: $persona,
            expediente: $expediente,
            tipo: TipoEncuentro::Ambulatorio,
            convenio: $convenio,
            sede: $sede,
        );
    } catch (CuentaException $e) {
        expect($e->getMessage())->toContain($primera->numero);

        return;
    }

    $this->fail('Se abrió la segunda cuenta.');
})->note('Quien está en el mostrador no necesita que le nieguen algo: necesita que le digan dónde cargarlo. El número de la cuenta va en el mensaje.');

it('con la anterior cerrada sí se puede abrir otra', function (): void {
    [$persona, $expediente, $convenio, $sede] = unPacienteListo();

    $primera = abridor()->abrir(
        persona: $persona,
        expediente: $expediente,
        tipo: TipoEncuentro::Ambulatorio,
        convenio: $convenio,
        sede: $sede,
    );

    $primera->forceFill([
        'estado'      => EstadoCuenta::Cerrada,
        'cerrada_en'  => now(),
        'cerrada_por' => null,
    ])->saveQuietly();

    $segunda = abridor()->abrir(
        persona: $persona,
        expediente: $expediente,
        tipo: TipoEncuentro::Ambulatorio,
        convenio: $convenio,
        sede: $sede,
    );

    expect($segunda)->toBeInstanceOf(Cuenta::class)
        ->and($segunda->id)->not->toBe($primera->id);
})->note('La regla es sobre cuentas VIVAS. Un paciente que vuelve la semana que viene abre la suya sin discusión: lo que no puede es tener dos a la vez.');
