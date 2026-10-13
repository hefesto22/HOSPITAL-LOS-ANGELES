<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoEncuentro;
use App\Domain\Enums\TipoItem;
use App\Domain\ValueObjects\ClienteDeFactura;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaDeCargo;
use App\Domain\ValueObjects\MedioDePago;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Expediente;
use App\Models\Factura;
use App\Models\Item;
use App\Models\Persona;
use App\Models\RangoCai;
use App\Models\Sede;
use App\Models\Tarifario;
use App\Models\TurnoDeCaja;
use App\Models\User;
use App\Services\AbridorDeEncuentro;
use App\Services\EmisorDeFactura;
use App\Services\ReceptorDeAbono;
use App\Services\RegistradorDeCargo;
use App\Support\AtencionesDelPaciente;
use Illuminate\Support\Str;

/*
 * ─────────────────────────────────────────────────────────────────────
 * LAS ATENCIONES EN EL EXPEDIENTE (12-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «que se vaya guardando cada que se le da el alta»; «al
 * facturar, y si se anula se quita del expediente»; sin montos. Lo que
 * este archivo cuida:
 *
 *   · una cuenta sin facturar NO está en el expediente;
 *   · al facturar, entra, con la descripción de a qué vino y qué se le
 *     hizo, y los repetidos juntos («×2»);
 *   · si la factura se anula, sale;
 *   · nada de plata en lo que se muestra.
 *
 * ⚠️ Ayudantes con nombre propio: con `--parallel` dos funciones globales
 * iguales en archivos distintos chocan.
 */

/**
 * @return array{cuenta: Cuenta, persona: Persona, cajera: User}
 */
function unaAtencionParaElExpediente(): array
{
    $contado = Convenio::query()->where('codigo', Convenio::CODIGO_CONTADO)->first()
        ?? Convenio::factory()->contado()->create();

    $sede = Sede::factory()->create();
    $persona = Persona::factory()->create(['fecha_nacimiento' => now()->subYears(40)->toDateString()]);
    $expediente = Expediente::factory()->create(['sede_id' => $sede->id, 'persona_id' => $persona->id]);

    $cuenta = app(AbridorDeEncuentro::class)->abrir(
        persona: $persona,
        expediente: $expediente,
        tipo: TipoEncuentro::Emergencia,
        convenio: $contado,
        sede: $sede,
        motivo: 'DOLOR ABDOMINAL',
    );

    $cajera = User::factory()->create();
    TurnoDeCaja::factory()->create(['sede_id' => $cuenta->sede_id, 'usuario_id' => $cajera->id]);

    return ['cuenta' => $cuenta, 'persona' => $persona, 'cajera' => $cajera];
}

/**
 * @param numeric-string $precio
 */
function unItemDelExpediente(string $nombre, TipoItem $tipo, string $precio): Item
{
    $item = Item::factory()->create([
        'nombre'                    => $nombre,
        'tipo'                      => $tipo,
        'regimen_isv'               => RegimenIsv::Exento,
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::SinDescuentoLegal,
        'se_almacena'               => false,
    ]);

    Tarifario::factory()->delItem($item)->a($precio)->create();

    return $item;
}

function cargarAlExpediente(Cuenta $cuenta, Item $item): void
{
    app(RegistradorDeCargo::class)->registrar($cuenta, new LineaDeCargo(
        item: $item,
        cantidad: Decimal::de('1'),
        claveIdempotencia: (string) Str::uuid(),
    ));
}

/**
 * Cobra lo que debe y factura.
 */
function facturarParaElExpediente(Cuenta $cuenta, User $cajera): Factura
{
    $cuenta->refresh();

    app(ReceptorDeAbono::class)->recibir(
        cuenta: $cuenta,
        medios: [new MedioDePago(FormaDePago::Efectivo, Decimal::de($cuenta->total))],
        quienRecibe: $cajera,
    );

    RangoCai::factory()->create(['sede_id' => $cuenta->sede_id]);

    return app(EmisorDeFactura::class)->emitir(
        cuenta: $cuenta->refresh(),
        cliente: ClienteDeFactura::consumidorFinal(),
        quien: $cajera->id,
    );
}

it('una cuenta sin facturar todavía no está en el expediente', function (): void {
    ['cuenta' => $cuenta, 'persona' => $persona] = unaAtencionParaElExpediente();
    cargarAlExpediente($cuenta, unItemDelExpediente('HEMOGRAMA', TipoItem::EstudioLaboratorio, '100.0000'));

    expect(app(AtencionesDelPaciente::class)->de($persona))->toBe([]);
});

it('🔴 al facturar la atención entra al expediente con a qué vino y qué se le hizo', function (): void {
    ['cuenta' => $cuenta, 'persona' => $persona, 'cajera' => $cajera] = unaAtencionParaElExpediente();

    $hemograma = unItemDelExpediente('HEMOGRAMA', TipoItem::EstudioLaboratorio, '100.0000');
    cargarAlExpediente($cuenta, $hemograma);
    cargarAlExpediente($cuenta, $hemograma);
    cargarAlExpediente($cuenta, unItemDelExpediente('CURACION SIMPLE', TipoItem::Procedimiento, '250.0000'));

    facturarParaElExpediente($cuenta, $cajera);

    $atenciones = app(AtencionesDelPaciente::class)->de($persona);

    expect($atenciones)->toHaveCount(1);

    $atencion = $atenciones[0];
    $grupos = array_column($atencion->grupos, 'items', 'titulo');

    expect($atencion->tipo)->toBe(TipoEncuentro::Emergencia)
        ->and($atencion->motivo)->toBe('DOLOR ABDOMINAL')
        ->and($atencion->descripcion)->toContain('Vino por: dolor abdominal.')
        ->and($atencion->descripcion)->toContain('Sin diagnóstico registrado.')
        ->and($atencion->descripcion)->toContain('curacion simple')
        ->and($atencion->descripcion)->toContain('1 examen de laboratorio')
        ->and($grupos['Laboratorio'] ?? [])->toBe(['HEMOGRAMA ×2'])
        ->and($grupos['Procedimientos'] ?? [])->toBe(['CURACION SIMPLE']);
})->note('El hemograma dos veces es un renglón «×2», y en el párrafo cuenta como un examen: lo que se lee es qué se le hizo, no cuántas filas tiene la cuenta.');

it('🔴 si la factura se anula, la atención sale del expediente', function (): void {
    ['cuenta' => $cuenta, 'persona' => $persona, 'cajera' => $cajera] = unaAtencionParaElExpediente();
    cargarAlExpediente($cuenta, unItemDelExpediente('HEMOGRAMA', TipoItem::EstudioLaboratorio, '100.0000'));

    $factura = facturarParaElExpediente($cuenta, $cajera);

    expect(app(AtencionesDelPaciente::class)->de($persona))->toHaveCount(1);

    app(EmisorDeFactura::class)->anular($factura, 'Salió con el nombre equivocado', $cajera->id);

    expect(app(AtencionesDelPaciente::class)->de($persona))->toBe([]);
})->note('No se borra nada: la factura anulada sigue en la base. La atención simplemente deja de tener una factura vigente, y el expediente muestra solo esas.');

it('lo que se muestra no lleva plata', function (): void {
    ['cuenta' => $cuenta, 'persona' => $persona, 'cajera' => $cajera] = unaAtencionParaElExpediente();
    cargarAlExpediente($cuenta, unItemDelExpediente('HEMOGRAMA', TipoItem::EstudioLaboratorio, '100.0000'));
    facturarParaElExpediente($cuenta, $cajera);

    $atencion = app(AtencionesDelPaciente::class)->de($persona)[0];
    $todo = $atencion->descripcion.' '.json_encode($atencion->grupos, JSON_UNESCAPED_UNICODE);

    expect($todo)->not->toContain('L.')
        ->and($todo)->not->toContain('100.00');
})->note('El expediente lo lee el médico, y el médico no ve costos (§1.4). Lo que costó queda en la factura.');
