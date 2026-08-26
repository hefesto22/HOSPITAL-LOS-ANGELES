<?php

declare(strict_types=1);

use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\Monto;
use App\Filament\Pages\BasesDePrecios;
use App\Filament\Resources\Convenios\Tables\ConveniosTable;
use App\Models\Convenio;
use App\Models\Item;
use App\Models\Tarifario;
use App\Services\AjustadorDeBaseDePrecios;
use App\Services\CopiadorDeBaseDePrecios;
use App\Services\ResolutorDePrecio;

function elAjustadorDeBases(): AjustadorDeBaseDePrecios
{
    return app(AjustadorDeBaseDePrecios::class);
}

function elCopiadorDeBases(): CopiadorDeBaseDePrecios
{
    return app(CopiadorDeBaseDePrecios::class);
}

/**
 * El número que el listado de seguros muestra al lado de cada pagador,
 * sacado de la misma subconsulta que usa la pantalla y no de una copia
 * escrita para la prueba — que es como una prueba pasa mientras la
 * pantalla miente.
 */
function preciosDelListado(Convenio $convenio): int
{
    $consulta = Convenio::query()->where('convenios.id', $convenio->id);

    ConveniosTable::conElConteoDePrecios($consulta);

    return (int) $consulta->firstOrFail()->getAttribute('items_con_precio');
}

/*
|--------------------------------------------------------------------------
| Corregir no es lo mismo que cambiar
|--------------------------------------------------------------------------
*/

it('corrige el precio del mismo dia sin dejar una fila nueva', function (): void {
    $item = Item::factory()->create();

    elAjustadorDeBases()->ajustar($item, null, Monto::de('100.00'), 'Precio inicial de la base.');
    elAjustadorDeBases()->ajustar($item, null, Monto::de('120.00'), 'Se había tecleado un cero de menos.');

    $filas = Tarifario::query()->where('item_id', $item->id)->get();

    expect($filas)->toHaveCount(1)
        ->and($filas->firstOrFail()->precio)->toBe('120.0000')
        ->and($filas->firstOrFail()->vigencia_hasta)->toBeNull();
})->note('🔴 Sin esto, corregir un cero de más recién tecleado deja dos filas — y peor: `FijadorDePrecio` cierra la vigente poniéndole «ayer», así que la fila quedaría con desde hoy y hasta ayer y el CHECK de la base la rechazaría.');

it('un precio de otro dia abre una vigencia nueva y cierra la anterior', function (): void {
    $item = Item::factory()->create();

    $ayer = now()->subDay();

    elAjustadorDeBases()->ajustar($item, null, Monto::de('100.00'), 'Precio de ayer, el que regía.', desde: $ayer);
    elAjustadorDeBases()->ajustar($item, null, Monto::de('130.00'), 'Ajuste de precios de este mes.');

    $filas = Tarifario::query()->where('item_id', $item->id)->orderBy('vigencia_desde')->get();

    expect($filas)->toHaveCount(2)
        ->and($filas->first()?->precio)->toBe('100.0000')
        ->and($filas->first()?->vigencia_hasta?->toDateString())->toBe($ayer->toDateString())
        ->and($filas->last()?->precio)->toBe('130.0000')
        ->and($filas->last()?->vigencia_hasta)->toBeNull();
})->note('ADR-0003: el precio lleva vigencia. Sobrescribir borraría la respuesta a «¿a cuánto estaba esto en marzo?», que es la primera pregunta de cualquier auditoría de una aseguradora.');

it('el precio de ayer sigue siendo el de ayer', function (): void {
    $item = Item::factory()->create();
    $contado = Convenio::factory()->contado()->create();

    $ayer = now()->subDay();

    elAjustadorDeBases()->ajustar($item, null, Monto::de('100.00'), 'Precio de ayer, el que regía.', desde: $ayer);
    elAjustadorDeBases()->ajustar($item, null, Monto::de('130.00'), 'Ajuste de precios de este mes.');

    $resolutor = app(ResolutorDePrecio::class);

    expect($resolutor->para($item, $contado, $ayer)->precio)->toBeMonto('100.00')
        ->and($resolutor->para($item, $contado, now())->precio)->toBeMonto('130.00');
})->note('Reimprimir la cuenta de un ingreso de ayer tiene que dar el precio de ayer. Si diera el de hoy, cada reimpresión sería un documento distinto del que el paciente firmó.');

/*
|--------------------------------------------------------------------------
| Armar la base de un pagador nuevo
|--------------------------------------------------------------------------
*/

it('copia todo el catalogo aplicando el porcentaje', function (): void {
    $aseguradora = Convenio::factory()->create(['codigo' => 'MILITAR']);

    $uno = Item::factory()->create();
    $dos = Item::factory()->create();

    Tarifario::factory()->delItem($uno)->a('1000.0000')->create();
    Tarifario::factory()->delItem($dos)->a('500.0000')->create();

    $resultado = elCopiadorDeBases()->copiar(
        origen: null,
        destino: $aseguradora,
        factor: Decimal::de('0.85'),
        motivo: 'Copiado desde el precio de lista al 85 % al armar la base.',
    );

    expect($resultado['creados'])->toBe(2)
        ->and($resultado['respetados'])->toBe(0);

    $resolutor = app(ResolutorDePrecio::class);

    expect($resolutor->para($uno, $aseguradora, now())->precio)->toBeMonto('850.00')
        ->and($resolutor->para($dos, $aseguradora, now())->precio)->toBeMonto('425.00');
})->note('Firmar con una aseguradora nueva es fijar el precio de ciento treinta ítems. A mano, a la mitad alguien se cansa y quedan sesenta sin precio — que aparecen a las once de la noche en el mostrador.');

it('🔴 no pisa lo que ya tenia precio negociado', function (): void {
    $aseguradora = Convenio::factory()->create(['codigo' => 'MILITAR']);

    $negociado = Item::factory()->create();
    $sinNegociar = Item::factory()->create();

    Tarifario::factory()->delItem($negociado)->a('1000.0000')->create();
    Tarifario::factory()->delItem($sinNegociar)->a('1000.0000')->create();

    /* Este se negoció aparte, a mano y a un precio que no sale de ninguna fórmula. */
    Tarifario::factory()->delItem($negociado)->paraElConvenio($aseguradora)->a('333.0000')->create();

    $resultado = elCopiadorDeBases()->copiar(
        origen: null,
        destino: $aseguradora,
        factor: Decimal::de('0.85'),
        motivo: 'Copiado desde el precio de lista al 85 % al armar la base.',
    );

    $resolutor = app(ResolutorDePrecio::class);

    expect($resultado['creados'])->toBe(1)
        ->and($resultado['respetados'])->toBe(1)
        ->and($resolutor->para($negociado, $aseguradora, now())->precio)->toBeMonto('333.00')
        ->and($resolutor->para($sinNegociar, $aseguradora, now())->precio)->toBeMonto('850.00');
})->note('🔴 Ese 333 lo puso alguien a mano después de negociarlo. Una copia masiva que lo borre destruye trabajo sin avisar, y nadie se entera hasta que la aseguradora reclama.');

it('cuenta los items que se quedaron sin precio porque el origen tampoco lo tenia', function (): void {
    $aseguradora = Convenio::factory()->create(['codigo' => 'MILITAR']);

    $conPrecio = Item::factory()->create();
    Item::factory()->create();

    Tarifario::factory()->delItem($conPrecio)->a('200.0000')->create();

    $resultado = elCopiadorDeBases()->copiar(
        origen: null,
        destino: $aseguradora,
        factor: Decimal::de('1'),
        motivo: 'Copiado desde el precio de lista al 100 % al armar la base.',
    );

    expect($resultado['creados'])->toBe(1)
        ->and($resultado['sinPrecioEnElOrigen'])->toBe(1);
})->note('No se inventa un precio donde no lo hay. Se dice cuántos quedaron pendientes, que es lo accionable.');

it('cuenta cuantos items tiene cargados cada base', function (): void {
    $aseguradora = Convenio::factory()->create(['codigo' => 'MILITAR']);

    $uno = Item::factory()->create();
    $dos = Item::factory()->create();

    Tarifario::factory()->delItem($uno)->a('100.0000')->create();
    Tarifario::factory()->delItem($dos)->a('200.0000')->create();
    Tarifario::factory()->delItem($uno)->paraElConvenio($aseguradora)->a('90.0000')->create();

    expect(elCopiadorDeBases()->cuantosTienenPrecio(null))->toBe(2)
        ->and(elCopiadorDeBases()->cuantosTienenPrecio($aseguradora))->toBe(1);
})->note('Es el número que va al lado de cada pestaña: dice de un vistazo cuánto falta cargar.');

it('el precio copiado se redondea a cuatro decimales sin float', function (): void {
    $aseguradora = Convenio::factory()->create(['codigo' => 'MILITAR']);
    $item = Item::factory()->create();

    Tarifario::factory()->delItem($item)->a('333.3300')->create();

    elCopiadorDeBases()->copiar(
        origen: null,
        destino: $aseguradora,
        factor: Decimal::de('0.85'),
        motivo: 'Copiado desde el precio de lista al 85 % al armar la base.',
    );

    $fila = Tarifario::query()
        ->where('item_id', $item->id)
        ->where('convenio_id', $aseguradora->id)
        ->firstOrFail();

    /* 333.33 × 0.85 = 283.3305 exacto. Con float daría 283.33049999... */
    expect($fila->precio)->toBe('283.3305');
})->note('§8.6.2-1: la aritmética de dinero va en bcmath. El error del punto flotante no se ve en un ítem; se ve en el total de ciento treinta.');

/*
|--------------------------------------------------------------------------
| El selector de origen — el contrato entre las dos pantallas
|--------------------------------------------------------------------------
*/

it('ofrece la lista y cada pagador con base propia, y sabe volver de la clave al convenio', function (): void {
    $palig = Convenio::factory()->create(['codigo' => 'PALIG', 'nombre' => 'PALIG']);

    $item = Item::factory()->create();
    Tarifario::factory()->delItem($item)->a('100.0000')->create();
    Tarifario::factory()->delItem($item)->paraElConvenio($palig)->a('90.0000')->create();

    $opciones = elCopiadorDeBases()->opcionesDeOrigen();

    expect($opciones)->toHaveKey(CopiadorDeBaseDePrecios::ORIGEN_LISTA)
        ->and($opciones)->toHaveKey(CopiadorDeBaseDePrecios::ORIGEN_CONVENIO.$palig->id)
        ->and(elCopiadorDeBases()->origenDesde(CopiadorDeBaseDePrecios::ORIGEN_CONVENIO.$palig->id)?->id)
        ->toBe($palig->id)
        ->and(elCopiadorDeBases()->origenDesde(CopiadorDeBaseDePrecios::ORIGEN_LISTA))->toBeNull();
})->note('🔴 El prefijo `convenio:` no es adorno: PHP convierte de vuelta a entero toda clave de arreglo que parezca un número, y sin él el tipo del arreglo de opciones se rompe.');

it('🔴 no ofrece el contado como origen: su precio ES el de lista', function (): void {
    $contado = Convenio::factory()->contado()->create();

    $item = Item::factory()->create();
    Tarifario::factory()->delItem($item)->a('100.0000')->create();

    $opciones = elCopiadorDeBases()->opcionesDeOrigen(conVacio: true);

    expect($opciones)->toHaveKey(CopiadorDeBaseDePrecios::ORIGEN_LISTA)
        ->and($opciones)->not->toHaveKey(CopiadorDeBaseDePrecios::ORIGEN_CONVENIO.$contado->id);
})->note('🔴 Aparecía dos veces la misma base: «PRECIO DE LISTA DEL HOSPITAL» y «PACIENTE PARTICULAR». De las dos, solo una copiaba algo — elegir la otra dejaba el seguro nuevo sin un solo precio y sin ningún aviso.');

it('no ofrece como origen a un pagador que no tiene precios propios', function (): void {
    $reciente = Convenio::factory()->create(['codigo' => 'MILITAR']);

    $item = Item::factory()->create();
    Tarifario::factory()->delItem($item)->a('100.0000')->create();

    expect(elCopiadorDeBases()->opcionesDeOrigen())
        ->not->toHaveKey(CopiadorDeBaseDePrecios::ORIGEN_CONVENIO.$reciente->id);
})->note('Copiar desde una base vacía no falla: deja el destino igual de vacío y a quien apretó el botón creyendo que hizo algo.');

it('el rotulo de cada origen dice cuantos items trae', function (): void {
    $palig = Convenio::factory()->create(['codigo' => 'PALIG', 'nombre' => 'PALIG']);

    $uno = Item::factory()->create();
    $dos = Item::factory()->create();

    Tarifario::factory()->delItem($uno)->a('100.0000')->create();
    Tarifario::factory()->delItem($dos)->a('200.0000')->create();
    Tarifario::factory()->delItem($uno)->paraElConvenio($palig)->a('90.0000')->create();

    $opciones = elCopiadorDeBases()->opcionesDeOrigen();

    expect($opciones[CopiadorDeBaseDePrecios::ORIGEN_LISTA])->toContain('2 ítems')
        ->and($opciones[CopiadorDeBaseDePrecios::ORIGEN_CONVENIO.$palig->id])->toContain('1 ítems');
})->note('Se ve qué se está por copiar ANTES de copiarlo. Un selector con solo nombres deja descubrir el error después, cuando ya hay ciento treinta filas escritas.');

it('cuenta los precios de todos los pagadores en una sola consulta', function (): void {
    $palig = Convenio::factory()->create(['codigo' => 'PALIG']);
    $militar = Convenio::factory()->create(['codigo' => 'MILITAR']);

    $uno = Item::factory()->create();
    $dos = Item::factory()->create();

    Tarifario::factory()->delItem($uno)->paraElConvenio($palig)->a('90.0000')->create();
    Tarifario::factory()->delItem($dos)->paraElConvenio($palig)->a('80.0000')->create();
    Tarifario::factory()->delItem($uno)->paraElConvenio($militar)->a('95.0000')->create();

    $conteos = elCopiadorDeBases()->conteosPorPagador();

    expect($conteos[$palig->id] ?? 0)->toBe(2)
        ->and($conteos[$militar->id] ?? 0)->toBe(1);
})->note('Un conteo por pestaña serían veinte viajes a la base para dibujar un desplegable, en cada pintada de la pantalla.');

it('la lista y el vacio no copian nada, un convenio si', function (): void {
    $palig = Convenio::factory()->create(['codigo' => 'PALIG']);

    expect(elCopiadorDeBases()->noCopiaNada(CopiadorDeBaseDePrecios::ORIGEN_VACIO))->toBeTrue()
        ->and(elCopiadorDeBases()->noCopiaNada(null))->toBeTrue()
        ->and(elCopiadorDeBases()->noCopiaNada(''))->toBeTrue()
        ->and(elCopiadorDeBases()->noCopiaNada(CopiadorDeBaseDePrecios::ORIGEN_LISTA))->toBeFalse()
        ->and(elCopiadorDeBases()->noCopiaNada(CopiadorDeBaseDePrecios::ORIGEN_CONVENIO.$palig->id))->toBeFalse();
})->note('«Sin origen» y «desde el precio de lista» son los dos `null`, y confundirlos haría que elegir «empezar vacío» copiara el catálogo entero.');

it('no se ofrece a si mismo como origen', function (): void {
    $palig = Convenio::factory()->create(['codigo' => 'PALIG']);

    $item = Item::factory()->create();
    Tarifario::factory()->delItem($item)->paraElConvenio($palig)->a('90.0000')->create();

    expect(elCopiadorDeBases()->opcionesDeOrigen(excluyendo: $palig->id))
        ->not->toHaveKey(CopiadorDeBaseDePrecios::ORIGEN_CONVENIO.$palig->id);
})->note('Copiarse a sí mismo no haría nada —todo estaría «respetado»— pero deja a quien lo apretó pensando que hizo algo.');

/*
|--------------------------------------------------------------------------
| El contador del listado de seguros
|--------------------------------------------------------------------------
*/

it('🔴 el listado de seguros cuenta solo los precios propios de cada uno', function (): void {
    $palig = Convenio::factory()->create(['codigo' => 'PALIG']);
    $militar = Convenio::factory()->create(['codigo' => 'MILITAR']);

    $uno = Item::factory()->create();
    $dos = Item::factory()->create();

    /* Precio de lista: no es de nadie, no cuenta para ningún pagador. */
    Tarifario::factory()->delItem($uno)->a('100.0000')->create();
    Tarifario::factory()->delItem($dos)->a('200.0000')->create();

    Tarifario::factory()->delItem($uno)->paraElConvenio($palig)->a('90.0000')->create();
    Tarifario::factory()->delItem($dos)->paraElConvenio($militar)->a('180.0000')->create();

    expect(preciosDelListado($palig))->toBe(1)
        ->and(preciosDelListado($militar))->toBe(1);
})->note('🔴 Una subconsulta correlacionada sin el `whereColumn` cuenta los precios de TODOS los pagadores en cada fila: el listado diría que el seguro recién creado ya tiene ciento treinta precios cuando no tiene ninguno.');

it('el seguro recien creado aparece con cero y no en blanco', function (): void {
    $palig = Convenio::factory()->create(['codigo' => 'PALIG']);

    Item::factory()->create();

    expect(preciosDelListado($palig))->toBe(0);
})->note('El cero es el aviso: a ese pagador se le va a cobrar el precio de lista. En blanco se lee como «todavía no cargó la pantalla».');

it('no cuenta el precio vencido ni el de una sede', function (): void {
    $palig = Convenio::factory()->create(['codigo' => 'PALIG']);

    $vencido = Item::factory()->create();
    $vigente = Item::factory()->create();

    Tarifario::factory()->delItem($vencido)->paraElConvenio($palig)->a('90.0000')->create([
        'vigencia_desde' => now()->subMonth(),
        'vigencia_hasta' => now()->subDay(),
    ]);

    Tarifario::factory()->delItem($vigente)->paraElConvenio($palig)->a('80.0000')->create();

    expect(preciosDelListado($palig))->toBe(1);
})->note('El número tiene que responder «¿con cuántos ítems puedo cobrarle HOY?». Contar los vencidos lo convierte en un número tranquilizador y falso.');

/*
|--------------------------------------------------------------------------
| El resumen de la pantalla
|--------------------------------------------------------------------------
*/

it('el resumen dice cuantos items faltan por cargar en la base', function (): void {
    $palig = Convenio::factory()->create(['codigo' => 'PALIG']);

    $conPrecio = Item::factory()->create();
    Item::factory()->create();
    Item::factory()->create();

    Tarifario::factory()->delItem($conPrecio)->paraElConvenio($palig)->a('90.0000')->create();

    $pagina = new BasesDePrecios;
    $pagina->convenioId = $palig->id;

    expect($pagina->resumenDeLaBase())->toBe([
        'total'     => 3,
        'conPrecio' => 1,
        'sinPrecio' => 2,
    ]);
})->note('El número que se mira es el de «sin precio»: cada uno de esos ítems es una discusión en el mostrador esperando a que alguien lo pida.');
