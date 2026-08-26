<?php

declare(strict_types=1);

use App\Domain\Enums\AlcanceDeConteo;
use App\Domain\Enums\EstadoConteo;
use App\Domain\Enums\TipoAlmacen;
use App\Domain\Enums\TipoMovimiento;
use App\Domain\Exceptions\ConteoException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaRecibida;
use App\Models\Ajuste;
use App\Models\Almacen;
use App\Models\Conteo;
use App\Models\ConteoLinea;
use App\Models\Item;
use App\Models\Lote;
use App\Models\User;
use App\Services\AbridorDeConteo;
use App\Services\CerradorDeConteo;
use App\Services\ConsultorDeExistencias;
use App\Services\RegistradorDeConteo;
use App\Services\RegistradorDeMovimiento;
use App\Services\RegistradorDeRecepcion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Ayudantes
|--------------------------------------------------------------------------
|
| Nombres propios y no `costos()` / `loQueHay()`: Pest carga TODOS los
| archivos de prueba en el mismo proceso, así que dos funciones con el
| mismo nombre en dos archivos distintos hacen fallar la suite entera con
| «cannot redeclare function» — y el error no señala al archivo nuevo.
|
*/

function elAbridor(): AbridorDeConteo
{
    return app(AbridorDeConteo::class);
}

function elContador(): RegistradorDeConteo
{
    return app(RegistradorDeConteo::class);
}

function elCerrador(): CerradorDeConteo
{
    return app(CerradorDeConteo::class);
}

function existenciaDeConteo(): ConsultorDeExistencias
{
    return app(ConsultorDeExistencias::class);
}

/**
 * Mete existencia de verdad: por el mismo camino que la mete bodega, así
 * el costo promedio también queda armado.
 *
 * @param numeric-string $unidades
 * @param numeric-string $costoUnitario
 */
function entradaParaConteo(
    Item $item,
    Almacen $almacen,
    string $unidades,
    string $costoUnitario = '10',
    ?string $lote = 'AB-123',
): void {
    app(RegistradorDeRecepcion::class)->registrar(
        almacen: $almacen,
        lineas: [
            new LineaRecibida(
                item: $item,
                presentacion: null,
                cantidadPresentacion: Decimal::de($unidades),
                unidadesPorPresentacion: Decimal::de('1'),
                costoPorPresentacion: Decimal::de($costoUnitario),
                numeroLote: $lote,
                vencimiento: now()->addYear(),
            ),
        ],
        referencia: 'Entrada de prueba',
    );
}

function medicamentoParaConteo(): Item
{
    return Item::factory()->medicamento()->create(['requiere_lote' => true]);
}

/*
|--------------------------------------------------------------------------
| Abrir
|--------------------------------------------------------------------------
*/

it('un conteo total carga una linea por cada existencia con saldo', function (): void {
    actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $uno = medicamentoParaConteo();
    $otro = medicamentoParaConteo();

    entradaParaConteo($uno, $bodega, '100', '10', 'L-1');
    entradaParaConteo($otro, $bodega, '50', '20', 'L-2');

    $conteo = elAbridor()->abrir($bodega, AlcanceDeConteo::Total);

    expect($conteo->lineas()->count())->toBe(2)
        ->and($conteo->cuantasFaltan())->toBe(2)
        ->and($conteo->lineas()->whereNotNull('cantidad_sistema')->count())->toBe(0);
})->note('Las líneas nacen PENDIENTES y sin saldo congelado. Congelar al abrir haría que todo lo despachado durante el conteo apareciera como faltante.');

it('no deja abrir dos conteos a la vez en el mismo almacen', function (): void {
    actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaConteo();
    entradaParaConteo($item, $bodega, '10');

    elAbridor()->abrir($bodega, AlcanceDeConteo::Parcial);

    elAbridor()->abrir($bodega, AlcanceDeConteo::Parcial);
})->throws(ConteoException::class, 'Ya hay un conteo abierto');

it('el indice unico parcial impide dos abiertos aunque alguien esquive el servicio', function (): void {
    actingAsAdmin();

    $bodega = Almacen::factory()->create();
    Conteo::factory()->enElAlmacen($bodega)->create();

    Conteo::factory()->enElAlmacen($bodega)->create();
})->throws(QueryException::class);

it('en otro almacen si se puede abrir uno al mismo tiempo', function (): void {
    actingAsAdmin();

    $bodega = Almacen::factory()->de(TipoAlmacen::BodegaCentral)->create();
    $farmacia = Almacen::factory()->de(TipoAlmacen::FarmaciaVenta)->create();

    Conteo::factory()->enElAlmacen($bodega)->create();
    Conteo::factory()->enElAlmacen($farmacia)->create();

    expect(Conteo::query()->abiertos()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Contar
|--------------------------------------------------------------------------
*/

it('congela el saldo del sistema en el momento de teclear, no antes', function (): void {
    actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaConteo();
    entradaParaConteo($item, $bodega, '100');

    $conteo = elAbridor()->abrir($bodega, AlcanceDeConteo::Parcial);
    $lote = Lote::query()->firstOrFail();

    /*
     * Entre abrir y contar entra otra caja. Lo que tiene que quedar
     * congelado es el saldo DE AHORA, no el de cuando se abrió.
     */
    entradaParaConteo($item, $bodega, '20', '10', 'AB-123');

    $linea = elContador()->contar($conteo, $item, $lote, Decimal::de('115'));

    expect($linea->cantidad_sistema)->toBe('120.0000')
        ->and($linea->diferencia)->toBe('-5.0000');
})->note('El corte va por línea. Si se congelara al abrir, esas 20 que entraron después aparecerían como sobrante.');

it('contar cero es valido y es distinto de no haber contado', function (): void {
    actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaConteo();
    entradaParaConteo($item, $bodega, '10');

    $conteo = elAbridor()->abrir($bodega, AlcanceDeConteo::Parcial);
    $lote = Lote::query()->firstOrFail();

    $linea = elContador()->contar($conteo, $item, $lote, Decimal::de('0'));

    expect($linea->estaContada())->toBeTrue()
        ->and($linea->cantidad_contada)->toBe('0.0000')
        ->and($linea->diferencia)->toBe('-10.0000');
})->note('El estante vacío es un dato. Lo que no existe es «no lo conté» convertido en cero por omisión.');

it('la base no admite una cantidad contada sin su saldo congelado', function (): void {
    actingAsAdmin();

    $conteo = Conteo::factory()->create();

    DB::table('conteo_lineas')->insert([
        'conteo_id'        => $conteo->id,
        'item_id'          => Item::factory()->create()->id,
        'cantidad_contada' => '95.0000',
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);
})->throws(QueryException::class, 'conteo_lineas_conteo_completo')
    ->note('El CHECK que hace imposible el cero implícito. Se prueba con insert crudo: el modelo nunca escribiría eso.');

it('exige recuento cuando la diferencia pasa la tolerancia, y el segundo conteo lo libera', function (): void {
    actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaConteo();
    entradaParaConteo($item, $bodega, '100');

    $conteo = Conteo::factory()->enElAlmacen($bodega)->conTolerancia('3')->create();
    $lote = Lote::query()->firstOrFail();

    $primera = elContador()->contar($conteo, $item, $lote, Decimal::de('90'));

    expect($primera->exige_recuento)->toBeTrue()
        ->and($primera->veces_contado)->toBe(1);

    $segunda = elContador()->contar($conteo, $item, $lote, Decimal::de('98'));

    expect($segunda->exige_recuento)->toBeFalse()
        ->and($segunda->veces_contado)->toBe(2)
        ->and($segunda->primer_conteo)->toBe('90.0000')
        ->and($segunda->cantidad_contada)->toBe('98.0000');
})->note('El recuento PISA al primero, con su propio corte. La primera lectura se guarda aparte porque la distancia entre las dos dice si el problema era el estante o el que contaba.');

it('no deja contar el mismo producto y lote dos veces como dos lineas', function (): void {
    actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaConteo();
    entradaParaConteo($item, $bodega, '100');

    $conteo = Conteo::factory()->enElAlmacen($bodega)->create();
    $lote = Lote::query()->firstOrFail();

    elContador()->contar($conteo, $item, $lote, Decimal::de('90'));
    elContador()->contar($conteo, $item, $lote, Decimal::de('95'));

    expect($conteo->lineas()->count())->toBe(1);
})->note('Escanear dos veces el mismo frasco no puede crear dos líneas: el cierre asentaría la diferencia dos veces.');

/*
|--------------------------------------------------------------------------
| Cerrar — lo que de verdad importa
|--------------------------------------------------------------------------
*/

it('asienta la DIFERENCIA y no el valor absoluto, asi la farmacia puede seguir despachando', function (): void {
    $bodeguero = actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaConteo();
    entradaParaConteo($item, $bodega, '100');

    $conteo = Conteo::factory()->enElAlmacen($bodega)->abiertoPor($bodeguero)->create();
    $lote = Lote::query()->firstOrFail();

    // 09:14 · el estante tiene 95 y el sistema dice 100.
    elContador()->contar($conteo, $item, $lote, Decimal::de('95'));

    // 10:30 · mientras tanto, farmacia despacha 10.
    app(RegistradorDeMovimiento::class)->registrar(
        item: $item,
        lote: $lote,
        almacen: $bodega,
        tipo: TipoMovimiento::SalidaPorDispensacion,
        cantidad: Decimal::de('10'),
    );

    expect(existenciaDeConteo()->totalEn($item, $bodega)->redondeado(0))->toBe('90');

    // 11:00 · cierra OTRA persona.
    $jefe = User::factory()->create(['is_active' => true]);
    test()->actingAs($jefe);

    $resultado = elCerrador()->cerrar($conteo->refresh());

    expect(existenciaDeConteo()->totalEn($item, $bodega)->redondeado(0))->toBe('85')
        ->and($resultado->lineasAsentadas)->toBe(1)
        ->and($conteo->refresh()->estado)->toBe(EstadoConteo::Cerrado);
})->note('El caso entero del módulo: 90 − 5 = 85. Con un valor absoluto, las 10 dispensadas se habrían devuelto solas al estante y el kardex diría que nunca salieron.');

it('el saldo sigue cuadrando con el kardex despues de cerrar', function (): void {
    $bodeguero = actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaConteo();
    entradaParaConteo($item, $bodega, '100');

    $conteo = Conteo::factory()->enElAlmacen($bodega)->abiertoPor($bodeguero)->create();
    $lote = Lote::query()->firstOrFail();
    elContador()->contar($conteo, $item, $lote, Decimal::de('93'));

    test()->actingAs(User::factory()->create(['is_active' => true]));
    elCerrador()->cerrar($conteo->refresh());

    expect(existenciaDeConteo()->totalEn($item, $bodega)->exacto())
        ->toBe(existenciaDeConteo()->segunElKardex($item, $bodega)->exacto());
})->note('La tabla de saldos es un caché; el kardex es la verdad. El día que no coincidan, gana el kardex.');

it('no cierra el conteo la misma persona que lo abrio', function (): void {
    $bodeguero = actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaConteo();
    entradaParaConteo($item, $bodega, '10');

    $conteo = Conteo::factory()->enElAlmacen($bodega)->abiertoPor($bodeguero)->create();
    $lote = Lote::query()->firstOrFail();
    elContador()->contar($conteo, $item, $lote, Decimal::de('9'));

    elCerrador()->cerrar($conteo->refresh());
})->throws(ConteoException::class, 'lo tiene que cerrar otra persona')
    ->note('Cerrar asienta faltantes. Un faltante firmado por quien dijo haberlo contado es un faltante que nadie verificó.');

it('un conteo total no cierra con lineas sin contar', function (): void {
    $bodeguero = actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $uno = medicamentoParaConteo();
    $otro = medicamentoParaConteo();
    entradaParaConteo($uno, $bodega, '100', '10', 'L-1');
    entradaParaConteo($otro, $bodega, '50', '10', 'L-2');

    $conteo = elAbridor()->abrir($bodega, AlcanceDeConteo::Total);

    expect($conteo->created_by)->toBe($bodeguero->id);

    elContador()->contar($conteo, $uno, Lote::query()->where('numero', 'L-1')->firstOrFail(), Decimal::de('100'));

    test()->actingAs(User::factory()->create(['is_active' => true]));

    elCerrador()->cerrar($conteo->refresh());
})->throws(ConteoException::class, 'por contar y este es un conteo total')
    ->note('Dar por cero lo que nadie contó borraría el estante entero de un producto porque el que contaba se fue a almorzar.');

it('no cierra mientras quede algo por recontar', function (): void {
    $bodeguero = actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaConteo();
    entradaParaConteo($item, $bodega, '100');

    $conteo = Conteo::factory()->enElAlmacen($bodega)->abiertoPor($bodeguero)->conTolerancia('2')->create();
    $lote = Lote::query()->firstOrFail();
    elContador()->contar($conteo, $item, $lote, Decimal::de('80'));

    test()->actingAs(User::factory()->create(['is_active' => true]));

    elCerrador()->cerrar($conteo->refresh());
})->throws(ConteoException::class, 'todavía nadie volvió a contar');

it('si todo cuadra no genera ningun ajuste, y eso es una buena noticia', function (): void {
    $bodeguero = actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaConteo();
    entradaParaConteo($item, $bodega, '100');

    $conteo = Conteo::factory()->enElAlmacen($bodega)->abiertoPor($bodeguero)->create();
    $lote = Lote::query()->firstOrFail();
    elContador()->contar($conteo, $item, $lote, Decimal::de('100'));

    test()->actingAs(User::factory()->create(['is_active' => true]));

    $resultado = elCerrador()->cerrar($conteo->refresh());

    expect($resultado->todoCuadro())->toBeTrue()
        ->and($resultado->ajuste)->toBeNull()
        ->and(Ajuste::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Controlados — §9.F11
|--------------------------------------------------------------------------
*/

it('un controlado se cuenta pero su diferencia NO se ajusta', function (): void {
    $bodeguero = actingAsAdmin();

    $bodega = Almacen::factory()->deControlados()->create();
    $fentanilo = Item::factory()->medicamento()->controlado()->create([
        'requiere_lote' => true,
        'nombre'        => 'FENTANILO 100 MCG',
    ]);
    $normal = medicamentoParaConteo();

    entradaParaConteo($fentanilo, $bodega, '50', '100', 'F-1');
    entradaParaConteo($normal, $bodega, '100', '10', 'N-1');

    $conteo = Conteo::factory()->enElAlmacen($bodega)->abiertoPor($bodeguero)->create();

    elContador()->contar($conteo, $fentanilo, Lote::query()->where('numero', 'F-1')->firstOrFail(), Decimal::de('48'));
    elContador()->contar($conteo, $normal, Lote::query()->where('numero', 'N-1')->firstOrFail(), Decimal::de('97'));

    test()->actingAs(User::factory()->create(['is_active' => true]));

    $resultado = elCerrador()->cerrar($conteo->refresh());

    expect($resultado->hayControladosPendientes())->toBeTrue()
        ->and($resultado->controladosSinAsentar)->toHaveCount(1)
        ->and($resultado->lineasAsentadas)->toBe(1)
        // El fentanilo NO se tocó: sigue diciendo 50 aunque se contaron 48.
        ->and(existenciaDeConteo()->totalEn($fentanilo, $bodega)->redondeado(0))->toBe('50')
        // El otro sí se ajustó.
        ->and(existenciaDeConteo()->totalEn($normal, $bodega)->redondeado(0))->toBe('97');
})->note('§9.F11. Que el descuadre quede a la vista y sin resolver es EL punto: un faltante de fentanilo no puede desaparecer apretando un botón. Va al libro, con folio y doble firma.');

/*
|--------------------------------------------------------------------------
| Después de cerrar, nada se toca
|--------------------------------------------------------------------------
*/

it('un conteo cerrado no se puede modificar ni siquiera con SQL crudo', function (): void {
    $bodeguero = actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaConteo();
    entradaParaConteo($item, $bodega, '10');

    $conteo = Conteo::factory()->enElAlmacen($bodega)->abiertoPor($bodeguero)->create();
    elContador()->contar($conteo, $item, Lote::query()->firstOrFail(), Decimal::de('10'));

    test()->actingAs(User::factory()->create(['is_active' => true]));
    elCerrador()->cerrar($conteo->refresh());

    DB::table('conteos')->where('id', $conteo->id)->update(['descripcion' => 'otra cosa']);
})->throws(QueryException::class)
    ->note('El trigger de PostgreSQL, no una regla del modelo. Un conteo cerrado explica movimientos de un kardex que no se edita.');

it('las lineas de un conteo cerrado tampoco se tocan', function (): void {
    $bodeguero = actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaConteo();
    entradaParaConteo($item, $bodega, '10');

    $conteo = Conteo::factory()->enElAlmacen($bodega)->abiertoPor($bodeguero)->create();
    $linea = elContador()->contar($conteo, $item, Lote::query()->firstOrFail(), Decimal::de('10'));

    test()->actingAs(User::factory()->create(['is_active' => true]));
    elCerrador()->cerrar($conteo->refresh());

    DB::table('conteo_lineas')->where('id', $linea->id)->update(['cantidad_contada' => '999.0000']);
})->throws(QueryException::class);

/*
|--------------------------------------------------------------------------
| Anular
|--------------------------------------------------------------------------
*/

it('anular exige explicacion y no borra nada', function (): void {
    actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $conteo = Conteo::factory()->enElAlmacen($bodega)->create();

    elCerrador()->anular($conteo, 'Se abrió en el almacén equivocado');

    expect($conteo->refresh()->estado)->toBe(EstadoConteo::Anulado)
        ->and(Conteo::query()->count())->toBe(1)
        ->and(Conteo::query()->abiertos()->count())->toBe(0);
});

it('no anula con un motivo de tres letras', function (): void {
    actingAsAdmin();

    $conteo = Conteo::factory()->create();

    elCerrador()->anular($conteo, 'error');
})->throws(ConteoException::class, 'al menos diez caracteres');

it('anulado el conteo, se puede abrir otro en el mismo almacen', function (): void {
    actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaConteo();
    entradaParaConteo($item, $bodega, '10');

    $primero = elAbridor()->abrir($bodega, AlcanceDeConteo::Parcial);
    elCerrador()->anular($primero, 'Se abrió por error, no era ese estante');

    $segundo = elAbridor()->abrir($bodega, AlcanceDeConteo::Parcial);

    expect($segundo->estaAbierto())->toBeTrue()
        ->and(ConteoLinea::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Los agujeros que encontró la auditoría del módulo
|--------------------------------------------------------------------------
*/

it('el doble clic NO libera el recuento obligatorio', function (): void {
    actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaConteo();
    entradaParaConteo($item, $bodega, '100');

    $conteo = Conteo::factory()->enElAlmacen($bodega)->conTolerancia('2')->create();
    $lote = Lote::query()->firstOrFail();

    $envio = '11111111-1111-4111-8111-111111111111';

    $primera = elContador()->contar($conteo, $item, $lote, Decimal::de('80'), claveDeEnvio: $envio);

    expect($primera->exige_recuento)->toBeTrue()
        ->and($primera->veces_contado)->toBe(1);

    // El mismo botón otra vez: misma acción, mismo token.
    $segunda = elContador()->contar($conteo, $item, $lote, Decimal::de('80'), claveDeEnvio: $envio);

    expect($segunda->exige_recuento)->toBeTrue()
        ->and($segunda->veces_contado)->toBe(1)
        ->and($segunda->primer_conteo)->toBeNull();
})->note('Sin esta guarda, apretar dos veces contaba como «ya lo volví a contar» y el control de segunda pasada quedaba satisfecho por un dedo nervioso.');

it('un envio nuevo SI cuenta como recuento, aunque el numero sea el mismo', function (): void {
    actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaConteo();
    entradaParaConteo($item, $bodega, '100');

    $conteo = Conteo::factory()->enElAlmacen($bodega)->conTolerancia('2')->create();
    $lote = Lote::query()->firstOrFail();

    elContador()->contar(
        $conteo,
        $item,
        $lote,
        Decimal::de('80'),
        claveDeEnvio: '11111111-1111-4111-8111-111111111111'
    );

    $segunda = elContador()->contar(
        $conteo,
        $item,
        $lote,
        Decimal::de('80'),
        claveDeEnvio: '22222222-2222-4222-8222-222222222222'
    );

    expect($segunda->veces_contado)->toBe(2)
        ->and($segunda->exige_recuento)->toBeFalse();
})->note('La pantalla renueva el token después de cada registro, así que un token nuevo significa que alguien volvió al estante y confirmó el número. Y no mira ningún reloj: comparar un timestamptz leído contra now() de PHP es la suposición que el §7.5 prohíbe.');

it('sin token no hay guarda: un comando o un import cuentan cada vez', function (): void {
    actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaConteo();
    entradaParaConteo($item, $bodega, '100');

    $conteo = Conteo::factory()->enElAlmacen($bodega)->conTolerancia('2')->create();
    $lote = Lote::query()->firstOrFail();

    elContador()->contar($conteo, $item, $lote, Decimal::de('80'));
    $segunda = elContador()->contar($conteo, $item, $lote, Decimal::de('80'));

    expect($segunda->veces_contado)->toBe(2);
})->note('Sin pantalla no hay doble clic posible, así que no hay nada que proteger.');

it('el recuento guarda quien y cuando hizo la primera lectura', function (): void {
    $auxiliar = actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaConteo();
    entradaParaConteo($item, $bodega, '100');

    $conteo = Conteo::factory()->enElAlmacen($bodega)->conTolerancia('2')->create();
    $lote = Lote::query()->firstOrFail();

    elContador()->contar($conteo, $item, $lote, Decimal::de('80'));

    $jefe = User::factory()->create(['is_active' => true]);
    test()->actingAs($jefe);

    $segunda = elContador()->contar($conteo, $item, $lote, Decimal::de('99'));

    expect($segunda->primer_conteo)->toBe('80.0000')
        ->and($segunda->primer_conteo_por)->toBe($auxiliar->id)
        ->and($segunda->primer_conteo_en)->not->toBeNull()
        ->and($segunda->contado_por)->toBe($jefe->id);
})->note('Sin el actor de la primera lectura, la pregunta que el recuento existe para contestar —¿el problema era el estante o el que contaba?— no se puede contestar.');

it('si el producto se agoto entre el conteo y el cierre, no se tumba el cierre entero', function (): void {
    $bodeguero = actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $seAgota = medicamentoParaConteo();
    $normal = medicamentoParaConteo();

    entradaParaConteo($seAgota, $bodega, '100', '10', 'AG-1');
    entradaParaConteo($normal, $bodega, '100', '10', 'NO-1');

    $conteo = Conteo::factory()->enElAlmacen($bodega)->abiertoPor($bodeguero)->create();

    $loteQueSeAgota = Lote::query()->where('numero', 'AG-1')->firstOrFail();
    $loteNormal = Lote::query()->where('numero', 'NO-1')->firstOrFail();

    elContador()->contar($conteo, $seAgota, $loteQueSeAgota, Decimal::de('95'));
    elContador()->contar($conteo, $normal, $loteNormal, Decimal::de('97'));

    // Rotación normal de la mañana: se despacha TODO lo del primero.
    app(RegistradorDeMovimiento::class)->registrar(
        item: $seAgota,
        lote: $loteQueSeAgota,
        almacen: $bodega,
        tipo: TipoMovimiento::SalidaPorDispensacion,
        cantidad: Decimal::de('100'),
    );

    test()->actingAs(User::factory()->create(['is_active' => true]));

    $resultado = elCerrador()->cerrar($conteo->refresh());

    expect($resultado->noAsentadasPorExistencia)->toHaveCount(1)
        ->and($resultado->lineasAsentadas)->toBe(1)
        // El que sí cabía se asentó.
        ->and(existenciaDeConteo()->totalEn($normal, $bodega)->redondeado(0))->toBe('97')
        // El que se agotó quedó en cero, no en −5.
        ->and(existenciaDeConteo()->totalEn($seAgota, $bodega)->redondeado(0))->toBe('0')
        // Y el hallazgo quedó ESCRITO, no solo en una notificación.
        ->and($conteo->refresh()->notas_del_cierre)->toContain('existencia bajó');
})->note('Antes tumbaba las otras 299 diferencias y dejaba el conteo abierto sin mensaje útil.');

it('el hallazgo de controlados queda escrito en el conteo, no solo en pantalla', function (): void {
    $bodeguero = actingAsAdmin();

    $bodega = Almacen::factory()->deControlados()->create();
    $fentanilo = Item::factory()->medicamento()->controlado()->create([
        'requiere_lote' => true,
        'nombre'        => 'FENTANILO 100 MCG',
    ]);

    entradaParaConteo($fentanilo, $bodega, '50', '100', 'F-1');

    $conteo = Conteo::factory()->enElAlmacen($bodega)->abiertoPor($bodeguero)->create();
    elContador()->contar($conteo, $fentanilo, Lote::query()->firstOrFail(), Decimal::de('48'));

    test()->actingAs(User::factory()->create(['is_active' => true]));

    $resultado = elCerrador()->cerrar($conteo->refresh());

    expect($resultado->ajuste)->toBeNull()
        ->and($conteo->refresh()->notas_del_cierre)->toContain('FENTANILO')
        ->and($conteo->notas_del_cierre)->toContain('doble firma');
})->note('En un conteo de estupefacientes por turno TODAS las diferencias son de controlados: no hay ajuste donde guardar la nota, y un toast muere con la sesión.');

it('el ajuste del cierre lleva la fecha del CONTEO, no la del cierre', function (): void {
    $bodeguero = actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaConteo();
    entradaParaConteo($item, $bodega, '100');

    $conteo = Conteo::factory()->enElAlmacen($bodega)->abiertoPor($bodeguero)->create();
    $linea = elContador()->contar($conteo, $item, Lote::query()->firstOrFail(), Decimal::de('95'));

    /*
     * La fecha esperada se lee de `contado_en`, no de `now()`: así el
     * test compara el ajuste contra el mismo instante que el servicio
     * tiene que reproducir, sin depender de a qué hora del día corra la
     * suite ni de cómo viaje el `timestamptz` de ida y vuelta.
     */
    $diaDelConteo = $linea->contado_en?->toDateString();

    // Se cierra al día siguiente, como pasa de verdad.
    $this->travel(1)->day();

    test()->actingAs(User::factory()->create(['is_active' => true]));

    $resultado = elCerrador()->cerrar($conteo->refresh());

    expect($resultado->ajuste?->fecha_operacion->toDateString())->toBe($diaDelConteo);
})->note('§7.5-4 y §8.7-9: un conteo del 31 de agosto cerrado el 1 de septiembre asienta su merma en AGOSTO, o el costo de ventas del mes cambia después de cerrado.');

it('quien esta contando no ve los numeros del sistema; quien va a cerrar si', function (): void {
    $auxiliar = actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaConteo();
    entradaParaConteo($item, $bodega, '100');

    $conteo = Conteo::factory()->enElAlmacen($bodega)->abiertoPor($auxiliar)->create();
    elContador()->contar($conteo, $item, Lote::query()->firstOrFail(), Decimal::de('95'));

    $jefe = User::factory()->create(['is_active' => true]);

    expect($conteo->refresh()->esCiegoPara($auxiliar->id))->toBeTrue()
        ->and($conteo->esCiegoPara($jefe->id))->toBeFalse();
})->note('§9.G4. Si el que cuenta ve el número que espera el sistema, escribe ese número — y el conteo deja de medir el estante.');

it('una linea ya contada no se saca del conteo', function (): void {
    actingAsAdmin();

    $bodega = Almacen::factory()->create();
    $item = medicamentoParaConteo();
    entradaParaConteo($item, $bodega, '100');

    $conteo = Conteo::factory()->enElAlmacen($bodega)->create();
    $linea = elContador()->contar($conteo, $item, Lote::query()->firstOrFail(), Decimal::de('95'));

    elContador()->quitar($linea);
})->throws(ConteoException::class, 'ya se contó')
    ->note('Sacarla dejaría el conteo diciendo que ese producto nunca estuvo en la planilla — que es lo que un faltante querría que dijera.');
