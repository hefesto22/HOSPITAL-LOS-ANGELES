<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaRecibida;
use App\Domain\ValueObjects\Monto;
use App\Models\Almacen;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Tarifario;
use App\Services\FijadorDePrecio;
use App\Services\RegistradorDeRecepcion;
use Carbon\Carbon;
use Database\Seeders\DescuentosLegalesSeeder;
use Database\Seeders\MargenesObjetivoSeeder;

/**
 * QUÉ PRECIO NACE CUANDO ENTRA MERCADERÍA.
 *
 * ─────────────────────────────────────────────────────────────────────
 * UN PRECIO POR ENVASE, Y NINGUNO DE MÁS
 * ─────────────────────────────────────────────────────────────────────
 *
 * Recibir es el único momento en que el sistema sabe, junto, qué entró y
 * cuánto costó. Si no le pone precio ahí, el precio lo termina poniendo
 * alguien en el mostrador con el paciente enfrente.
 *
 * 🔴 Pero el precio que nace tiene que corresponderle a existencia real.
 * Sembrar además uno «del producto entero» le dejaba a cada medicamento
 * una fila que no era de ningún frasco — y como el resolutor cae a esa
 * fila cuando no encuentra la del envase, y no avisa, el número
 * equivocado quedaba a un renglón faltante de salir cobrado.
 */
function conPoliticaDePrecios(): void
{
    (new DescuentosLegalesSeeder)->run();
    (new MargenesObjetivoSeeder)->run();
}

function unJarabe(): Item
{
    return Item::factory()->medicamento()->create([
        'nombre'                    => 'ACETAMINOFEN JARABE',
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico,
    ]);
}

function unFrascoDe(Item $item, string $ml): ItemPresentacion
{
    return ItemPresentacion::factory()
        ->for($item)
        ->conContenido($ml, 'FRASCO '.$ml.' ML')
        ->create();
}

/**
 * Un renglón de la recepción con los números como los teclea bodega:
 * cuántos frascos, cuántos ml trae cada uno, y qué costó el frasco.
 *
 * @param numeric-string $frascos
 * @param numeric-string $ml
 * @param numeric-string $costoDelFrasco
 */
function unRenglon(
    Item $item,
    string $frascos,
    string $ml,
    string $costoDelFrasco,
    ?ItemPresentacion $presentacion = null,
    string $lote = 'JB-001',
): LineaRecibida {
    return new LineaRecibida(
        item: $item,
        presentacion: $presentacion,
        cantidadPresentacion: Decimal::de($frascos),
        unidadesPorPresentacion: Decimal::de($ml),
        costoPorPresentacion: Decimal::de($costoDelFrasco),
        numeroLote: $lote,
        vencimiento: Carbon::parse('2027-06-30'),
    );
}

function recibir(Item $item, LineaRecibida ...$renglones): void
{
    app(RegistradorDeRecepcion::class)->registrar(
        almacen: Almacen::factory()->create(),
        lineas: array_values($renglones),
        referencia: 'Factura 000-001-01-00000657',
    );
}

/**
 * Los precios del producto leídos como «ENVASE => precio». La fila sin
 * envase aparece como «respaldo», que es exactamente lo que muestra la
 * pantalla de Precios.
 *
 * Se ordena por id —el orden en que se recibieron— para que el diff de
 * un test que falla diga qué renglón cambió y no solo que algo cambió.
 *
 * @return array<string, string>
 */
function preciosDe(Item $item): array
{
    return Tarifario::query()
        ->where('item_id', $item->id)
        ->with('presentacion')
        ->orderBy('id')
        ->get()
        /*
         * Decide la LLAVE `item_presentacion_id` y no la relación: la
         * columna es la que puede venir nula, y preguntarle a ella deja
         * el `->nombre` en la rama donde la presentación seguro existe.
         * Con `?->` sobre la relación, PHPStan ve un nullsafe que nunca
         * dispara y lo marca — tiene razón, ahí ya no puede ser nula.
         */
        ->mapWithKeys(function (Tarifario $precio): array {
            $envase = $precio->item_presentacion_id === null
                ? 'respaldo'
                : $precio->presentacion->nombre;

            return [$envase => $precio->monto()->valor()];
        })
        ->all();
}

/*
|--------------------------------------------------------------------------
| 🔴 Ni uno de más
|--------------------------------------------------------------------------
*/

it('🔴 un frasco que llega deja UN precio, el de ese frasco', function (): void {
    conPoliticaDePrecios();

    $jarabe = unJarabe();
    $de60 = unFrascoDe($jarabe, '60');

    recibir($jarabe, unRenglon($jarabe, '10', '60', '1000', $de60));

    expect(preciosDe($jarabe))->toBe(['FRASCO 60 ML' => '61.11']);
})->note('🔴 Este es el test del bug: antes quedaban DOS filas —la del frasco y una «del producto entero»— y la segunda no le correspondía a ninguna existencia. Diez frascos de 60 ML son diez frascos de 60 ML, y tienen un precio.');

it('🔴 cada presentación sale con SU costo, no con el promedio', function (): void {
    conPoliticaDePrecios();

    $jarabe = unJarabe();

    recibir(
        $jarabe,
        unRenglon($jarabe, '10', '60', '1000', unFrascoDe($jarabe, '60'), 'JB-060'),
        unRenglon($jarabe, '10', '80', '2000', unFrascoDe($jarabe, '80'), 'JB-080'),
        unRenglon($jarabe, '10', '120', '1500', unFrascoDe($jarabe, '120'), 'JB-120'),
    );

    expect(preciosDe($jarabe))->toBe([
        'FRASCO 60 ML'  => '61.11',
        'FRASCO 80 ML'  => '91.67',
        'FRASCO 120 ML' => '45.83',
    ]);
})->note('🔴 El mililitro costó L 16.67, L 25.00 y L 12.50 según el frasco, y de ahí salen tres precios que se llevan el doble entre sí. Un solo precio para los tres haría que el margen del hospital dependiera de cuál frasco estaba abierto — y nadie lo sabría, porque la factura se vería igual.');

/*
|--------------------------------------------------------------------------
| Cuándo el respaldo sí corresponde
|--------------------------------------------------------------------------
*/

it('a granel el respaldo es el único precio posible', function (): void {
    conPoliticaDePrecios();

    $jarabe = unJarabe();

    recibir($jarabe, unRenglon($jarabe, '1', '600', '10000'));

    expect(preciosDe($jarabe))->toBe(['respaldo' => '61.11']);
})->note('Sin envase declarado no hay a qué frasco colgarle el precio, y sin precio el producto no se puede cobrar. Acá la fila sin envase no sobra: es la única que le corresponde a esa existencia.');

it('el respaldo aparece recién cuando llega algo sin envase', function (): void {
    conPoliticaDePrecios();

    $jarabe = unJarabe();
    $de60 = unFrascoDe($jarabe, '60');

    recibir($jarabe, unRenglon($jarabe, '10', '60', '1000', $de60, 'JB-060'));
    recibir($jarabe, unRenglon($jarabe, '1', '600', '9000', null, 'JB-GRANEL'));

    expect(preciosDe($jarabe))->toBe([
        'FRASCO 60 ML' => '61.11',
        'respaldo'     => '55.00',
    ]);
})->note('Nace cuando hay existencia que lo necesite y no antes. L 15 el mililitro a granel × 2,20 ÷ 0,60 = 55,00 — su propio costo, no el promedio de los dos ingresos.');

/*
|--------------------------------------------------------------------------
| Lo que decidió una persona no lo pisa una compra
|--------------------------------------------------------------------------
*/

it('no le pisa el precio a quien ya lo fijó a mano', function (): void {
    conPoliticaDePrecios();

    $jarabe = unJarabe();
    $de60 = unFrascoDe($jarabe, '60');

    app(FijadorDePrecio::class)->fijar(
        item: $jarabe,
        convenio: null,
        sede: null,
        precio: Monto::de('80.00'),
        motivo: 'Lo fijó dirección en la reunión del lunes.',
        desde: now()->subMonth(),
        presentacion: $de60,
    );

    recibir($jarabe, unRenglon($jarabe, '10', '60', '1000', $de60));

    expect(preciosDe($jarabe))->toBe(['FRASCO 60 ML' => '80.00']);
})->note('Un precio fijado es una decisión con fecha, autor y motivo. Que una compra lo pisara sería cambiar la lista sin que nadie lo pida, y el cambio aparecería recién en la utilidad del mes.');
