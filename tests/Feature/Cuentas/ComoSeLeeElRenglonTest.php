<?php

declare(strict_types=1);

use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaDeCargo;
use App\Domain\ValueObjects\RenglonDeCuenta;
use App\Models\Cargo;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\ItemPresentacion;
use App\Models\Unidad;
use App\Services\RegistradorDeCargo;
use Illuminate\Support\Str;

/**
 * CÓMO SE LEE EL RENGLÓN DE LA CUENTA.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 LO QUE ESTE ARCHIVO EXISTE PARA IMPEDIR
 * ─────────────────────────────────────────────────────────────────────
 *
 * Que el paciente reciba una factura donde no reconoce ni la cantidad ni
 * el precio.
 *
 * Un frasco de 60 ml salía impreso como «60 × L 61.11». Los dos números
 * son correctos y la lectura está mal: nadie entregó sesenta de nada, y
 * L 61.11 no es un precio de este hospital —es el frasco dividido entre
 * sus mililitros—.
 *
 * ⚠️ Y el kardex NO cambia: `cantidad` sigue en 60 ML porque eso es lo
 * que salió del estante. Son dos lecturas del mismo hecho, y las dos
 * tienen que convivir. Eso también se prueba acá.
 */
/**
 * ⚠️ `unFrascoDeJarabe` y no `unFrascoDe`: ese nombre ya lo usa
 * `PrecioAlRecibirTest` con otra firma, y los ayudantes de Pest son
 * funciones GLOBALES —dos con el mismo nombre chocan en cuanto los dos
 * archivos caen en el mismo proceso.
 *
 * Un frasco de 60 ml cuyo contenido vale L 100 el mililitro: el frasco
 * entero sale L 6,000 y los números del test se leen sin calculadora.
 *
 * ⚠️ `unidad_id` de la presentación es la unidad del ENVASE —FRASCO—, no
 * la de su contenido. El contenido lo dice `unidades_por_presentacion`.
 */
function unFrascoDeJarabe(string $mililitros): ItemPresentacion
{
    $envase = Unidad::factory()->create(['codigo' => 'FRASCO', 'nombre' => 'FRASCO']);

    return ItemPresentacion::factory()
        ->conContenido($mililitros, 'FRASCO '.$mililitros.' ML')
        ->create([
            'item_id'   => unServicioDe('100.0000')->id,
            'unidad_id' => $envase->id,
        ]);
}

function cargarEnvases(
    Cuenta $cuenta,
    ItemPresentacion $presentacion,
    string $envases,
    string $enDispensacion,
): Cargo {
    app(RegistradorDeCargo::class)->registrar($cuenta, new LineaDeCargo(
        item: $presentacion->item,
        cantidad: Decimal::de($enDispensacion),
        claveIdempotencia: (string) Str::uuid(),
        presentacion: $presentacion,
        envases: Decimal::de($envases),
    ));

    return Cargo::query()->where('cuenta_id', $cuenta->id)->orderByDesc('id')->firstOrFail();
}

it('🔴 el cargo guarda el envase sin tocar la cantidad del kardex', function (): void {
    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create());
    $frasco = unFrascoDeJarabe('60.0000');

    $cargo = cargarEnvases($cuenta, $frasco, '1', '60');

    expect($cargo->cantidad)->toBe('60.0000')
        ->and($cargo->cantidad_presentacion)->toBe('1.0000')
        ->and($cargo->item_presentacion_id)->toBe($frasco->id);
})->note('Las dos lecturas conviven: 60 ML salieron del estante y 1 FRASCO se le cobró al paciente.');

it('🔴 el renglon se lee «1 FRASCO» y no «60»', function (): void {
    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create());
    $frasco = unFrascoDeJarabe('60.0000');

    $cargo = cargarEnvases($cuenta, $frasco, '1', '60');

    $renglon = RenglonDeCuenta::de(collect([$cargo]));

    expect($renglon->cantidad->redondeado(0))->toBe('1')
        ->and($renglon->unidad)->toBe('FRASCO')
        /*
         * El frasco entero: 60 ml a L 100 el mililitro. Antes decía «60 ×
         * L 100», que es el mismo dinero contado como nadie lo compra.
         */
        ->and($renglon->precioUnitario)->toBe('6000.0000')
        ->and($cargo->bruto)->toBe('6000.00');
})->note('El precio unitario se DERIVA y no se guarda otra vez: dos copias del mismo numero alguna vez difieren.');

it('sin envase la cantidad de dispensacion sigue siendo la que se lee', function (): void {
    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create());

    conUnServicioDe($cuenta, '1000.0000');

    $cargo = Cargo::query()->where('cuenta_id', $cuenta->id)->firstOrFail();
    $renglon = RenglonDeCuenta::de(collect([$cargo]));

    expect($renglon->cantidad->redondeado(0))->toBe('1')
        ->and($renglon->unidad)->toBeNull()
        ->and($renglon->precioUnitario)->toBe($cargo->precio_unitario);
})->note('Un honorario o una consulta no tienen envase: ahí la cantidad ya se lee sola.');

it('🔴 ignora una presentacion que no es de este item', function (): void {
    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create());

    $frascoDeOtro = unFrascoDeJarabe('60.0000');
    $elQueSeCobra = unServicioDe('500.0000');

    app(RegistradorDeCargo::class)->registrar($cuenta, new LineaDeCargo(
        item: $elQueSeCobra,
        cantidad: Decimal::de('1'),
        claveIdempotencia: (string) Str::uuid(),
        presentacion: $frascoDeOtro,
        envases: Decimal::de('1'),
    ));

    $cargo = Cargo::query()->where('cuenta_id', $cuenta->id)->orderByDesc('id')->firstOrFail();

    expect($cargo->item_presentacion_id)->toBeNull()
        ->and($cargo->cantidad_presentacion)->toBeNull();
})->note('Dejaría el renglón diciendo «1 CAJA X 100» de algo que se entregó en frasco, y eso el CHECK de la base no lo puede ver: las dos columnas estarían llenas y la fila sería válida.');

it('varias entregas del mismo envase suman envases, no mililitros', function (): void {
    $cuenta = unaCuentaCon(Convenio::factory()->contado()->create());
    $frasco = unFrascoDeJarabe('60.0000');

    $uno = cargarEnvases($cuenta, $frasco, '1', '60');
    $dos = cargarEnvases($cuenta, $frasco, '1', '60');

    $renglon = RenglonDeCuenta::de(collect([$uno, $dos]));

    expect($renglon->cantidad->redondeado(0))->toBe('2')
        ->and($renglon->cuantasEntregas())->toBe(2);
})->note('Dos frascos son dos, no ciento veinte.');
