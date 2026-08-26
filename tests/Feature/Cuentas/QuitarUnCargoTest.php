<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\EstadoCargo;
use App\Domain\Enums\TipoEncuentro;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaDeCargo;
use App\Models\Cargo;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Expediente;
use App\Models\Item;
use App\Models\Persona;
use App\Models\Sede;
use App\Models\Tarifario;
use App\Models\User;
use App\Services\AbridorDeEncuentro;
use App\Services\AnuladorDeCargo;
use App\Services\RegistradorDeCargo;
use Illuminate\Support\Str;

/**
 * QUITAR UNA LÍNEA: CUÁNDO ALCANZA LA ✕ Y CUÁNDO HAY QUE EXPLICAR.
 *
 * La ✕ se dejó sin motivo a propósito: quitar algo que se tecleó hace
 * diez segundos es corregir un tipeo, y pedir una justificación escrita
 * para eso no produce auditoría, produce «aaaaaaaaaa».
 *
 * Ese argumento vale mientras la línea sea TUYA y de RECIÉN. El caso que
 * abrió esto es el otro: el paciente ve el monto, dice que prefiere pedir
 * traslado, y hay que quitarle dos dosis que cargó el turno anterior. Eso
 * no es un tipeo — y quien las puso merece saber por qué desaparecieron.
 */
function unCargoPuestoPor(?int $usuario, int $minutosAtras = 0): Cargo
{
    $cargo = new Cargo;
    $cargo->created_by = $usuario;
    $cargo->registrado_en = now()->subMinutes($minutosAtras);

    return $cargo;
}

it('lo que acabo de teclear yo se quita sin explicar nada', function (): void {
    expect(unCargoPuestoPor(7)->pideMotivoParaQuitar(7))->toBeFalse();
})->note('Es el caso de cien veces por turno: el doble escaneo, el renglón que se eligió mal. Un formulario ahí solo enseña a escribir cualquier cosa para pasar.');

it('🔴 lo que puso otro turno pide el porque', function (): void {
    expect(unCargoPuestoPor(7)->pideMotivoParaQuitar(9))->toBeTrue();
})->note('🔴 Quien la quita no sabe por qué se puso, y quien la puso no va a saber por qué desapareció. Ese hueco es el que después nadie puede explicar.');

it('🔴 lo mio pero viejo tambien pide el porque', function (): void {
    $viejo = Cargo::MINUTOS_DE_CORRECCION + 1;

    expect(unCargoPuestoPor(7, $viejo)->pideMotivoParaQuitar(7))->toBeTrue()
        ->and(unCargoPuestoPor(7, Cargo::MINUTOS_DE_CORRECCION - 1)->pideMotivoParaQuitar(7))->toBeFalse();
})->note('🔴 El medicamento pudo salir del carro y el paciente pudo recibirlo. Pasada la ventana, «se quitó» y «no se le dio» dejan de ser lo mismo.');

it('🔴 sin usuario en sesion se pide el porque igual', function (): void {
    expect(unCargoPuestoPor(7)->pideMotivoParaQuitar(null))->toBeTrue();
})->note('🔴 Este test encontró el bug. La regla estaba escrita al revés —«¿lo puso otro?»— y con la sesión vencida contestaba «no, otro no fue»: la línea se quitaba sin explicar nada. No hay forma de afirmar que es tuya si no se sabe quién sos.');

it('🔴 un cargo sin autor tambien pide el porque', function (): void {
    expect(unCargoPuestoPor(null)->pideMotivoParaQuitar(7))->toBeTrue();
})->note('🔴 El otro nulo, y tampoco es de laboratorio: un cargo sembrado por un import del catálogo viejo no tiene autor. Preguntado en positivo, no saber es simplemente no constar, y la duda cae sola del lado seguro.');

/*
|--------------------------------------------------------------------------
| La bitácora
|--------------------------------------------------------------------------
*/

function unaCuentaConBitacora(): Cuenta
{
    $sede = Sede::factory()->create();
    $persona = Persona::factory()->create([
        'fecha_nacimiento' => now()->subYears(35)->toDateString(),
    ]);
    $expediente = Expediente::factory()->create([
        'sede_id'    => $sede->id,
        'persona_id' => $persona->id,
    ]);

    return app(AbridorDeEncuentro::class)->abrir(
        persona: $persona,
        expediente: $expediente,
        tipo: TipoEncuentro::Emergencia,
        convenio: Convenio::factory()->contado()->create(),
        sede: $sede,
    );
}

function unaDosisEn(Cuenta $cuenta): Cargo
{
    $item = Item::factory()->create([
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::SinDescuentoLegal,
    ]);

    Tarifario::factory()->delItem($item)->a('120.0000')->create();

    return app(RegistradorDeCargo::class)->registrar($cuenta, new LineaDeCargo(
        item: $item,
        cantidad: Decimal::de('1'),
        claveIdempotencia: (string) Str::uuid(),
    ))->firstOrFail();
}

it('🔴 la bitacora muestra lo que la factura esconde', function (): void {
    $cuenta = unaCuentaConBitacora();
    $cargo = unaDosisEn($cuenta);

    app(AnuladorDeCargo::class)->anular(
        $cargo,
        'El paciente pidió traslado y no se le administró.',
    );

    $estados = $cuenta->refresh()->bitacora()->pluck('estado')->all();

    expect($cuenta->renglonesVivos())->toBeEmpty()
        ->and($estados)->toEqualCanonicalizing([EstadoCargo::Anulado, EstadoCargo::Anulacion]);
})->note('🔴 Es todo el punto. Con solo las líneas vivas, algo que el turno anterior cargó y alguien quitó es una línea que nunca existió — y eso es justo lo que después nadie puede explicar.');

it('la bitacora dice quien quito y por que', function (): void {
    $quienCarga = User::factory()->create();
    $quienQuita = User::factory()->create();

    $this->actingAs($quienCarga);

    $cuenta = unaCuentaConBitacora();
    $cargo = unaDosisEn($cuenta);

    $this->actingAs($quienQuita);

    app(AnuladorDeCargo::class)->anular(
        $cargo,
        'El paciente pidió traslado y no se le administró.',
    );

    $reversa = $cuenta->refresh()->bitacora()
        ->where('estado', EstadoCargo::Anulacion)
        ->firstOrFail();

    expect($reversa->created_by)->toBe($quienQuita->id);
    expect($reversa->motivo_anulacion)->toBe('El paciente pidió traslado y no se le administró.');
    expect($cargo->refresh()->created_by)->toBe($quienCarga->id);
})->note('Los dos nombres, no uno. Sin quién cargó, el cambio de turno no puede preguntar; sin quién quitó y por qué, no puede responder.');

it('la bitacora va en el orden en que se tecleo', function (): void {
    $cuenta = unaCuentaConBitacora();

    $primero = unaDosisEn($cuenta);
    $segundo = unaDosisEn($cuenta);

    app(AnuladorDeCargo::class)->anular($primero, 'Se cargó dos veces por doble escaneo.');

    $ids = $cuenta->refresh()->bitacora()->pluck('id')->all();

    expect(array_slice($ids, 0, 2))->toBe([$primero->id, $segundo->id])
        ->and(count($ids))->toBe(3);
})->note('Por `registrado_en` y no por `ocurrido_en`: la bitácora cuenta lo que pasó con el SISTEMA, y un cargo tardío entra donde alguien lo entró, no donde dice que pasó.');
