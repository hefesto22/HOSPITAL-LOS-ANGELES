<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\TipoItem;
use App\Filament\Resources\Items\RelationManagers\ExistenciasRelationManager;
use App\Filament\Resources\Items\RelationManagers\PresentacionesRelationManager;
use App\Models\Existencia;
use App\Models\Item;
use App\Models\Unidad;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| El tipo propone, la columna decide
|--------------------------------------------------------------------------
*/

it('el tipo sigue proponiendo si se almacena cuando nadie contesta', function (): void {
    $medicamento = Item::factory()->medicamento()->create();
    $servicio = Item::factory()->create();

    expect($medicamento->mueveInventario())->toBeTrue()
        ->and($servicio->mueveInventario())->toBeFalse();
})->note('🔴 Todo lo ya escrito —seeders, imports, factories de pruebas viejas— nunca contestó esta pregunta. Sin el valor derivado del tipo, de un día para el otro nada movería kardex y ninguna prueba lo diría.');

it('🔴 un insumo se puede declarar NO inventariado', function (): void {
    $papel = Item::factory()
        ->de(TipoItem::Insumo, CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico)
        ->create(['se_almacena' => false, 'unidad_dispensacion_id' => null]);

    expect($papel->mueveInventario())->toBeFalse()
        ->and($papel->requiere_lote)->toBeFalse();
})->note('🔴 El papel de la camilla y el gel del ecógrafo se compran y se consumen sin inventariar. Forzados al kardex aparecen en cada conteo físico con diferencias que nadie puede explicar — y así se aprende a ignorar las diferencias del conteo, que es lo que después deja pasar un faltante de verdad.');

it('un item de tipo otro se puede almacenar', function (): void {
    $gasa = Item::factory()
        ->de(TipoItem::Otro, CategoriaLegalDeDescuento::SinDescuentoLegal)
        ->create([
            'se_almacena'            => true,
            'unidad_dispensacion_id' => Unidad::factory(),
        ]);

    expect($gasa->mueveInventario())->toBeTrue();
})->note('Antes lo impedía un CHECK sobre el tipo. Lo que se guarda en bodega y hay que contar no siempre entra en las dos etiquetas que había.');

/*
|--------------------------------------------------------------------------
| Lo que la base sigue sin permitir
|--------------------------------------------------------------------------
*/

it('🔴 no se puede almacenar algo sin unidad de dispensacion', function (): void {
    expect(fn (): Item => Item::factory()
        ->de(TipoItem::Otro, CategoriaLegalDeDescuento::SinDescuentoLegal)
        ->create([
            'se_almacena'            => true,
            'unidad_dispensacion_id' => null,
        ]))->toThrow(QueryException::class);
})->note('🔴 Sin unidad no se puede costear ni descontar del kardex, y el error aparecería recién en la primera dispensación, de noche, con el paciente esperando.');

it('no se puede exigir lote en algo que no se almacena', function (): void {
    /*
     * El modelo limpia la bandera antes de llegar a la base, así que lo
     * que se comprueba es que la limpia — no que la base la rechaza, que
     * ya está probado en el bloque 2.
     */
    $honorario = Item::factory()
        ->de(TipoItem::Honorario, CategoriaLegalDeDescuento::ConsultaGeneral)
        ->create(['se_almacena' => false, 'requiere_lote' => true]);

    expect($honorario->requiere_lote)->toBeFalse()
        ->and($honorario->es_controlado)->toBeFalse()
        ->and($honorario->fraccionable)->toBeFalse();
})->note('Un lote sobre algo que no se almacena es un campo obligatorio que nadie puede llenar.');

/*
|--------------------------------------------------------------------------
| El candado: no se apaga con inventario escrito
|--------------------------------------------------------------------------
*/

it('🔴 sabe cuando un item ya tiene inventario escrito', function (): void {
    $conStock = Item::factory()->medicamento()->create();
    $sinStock = Item::factory()->medicamento()->create();

    Existencia::factory()->create(['item_id' => $conStock->id]);

    expect($conStock->tieneInventarioEscrito())->toBeTrue()
        ->and($sinStock->tieneInventarioEscrito())->toBeFalse();
})->note('🔴 Apagar «se almacena» en un ítem que ya se movió no borra la existencia: la vuelve invisible. El stock sigue ahí, ninguna pantalla lo muestra y el conteo físico siguiente no lo encuentra para cuadrarlo.');

/*
|--------------------------------------------------------------------------
| Qué pestañas se ofrecen
|--------------------------------------------------------------------------
*/

it('las existencias solo se ofrecen en lo que se almacena', function (): void {
    $medicamento = Item::factory()->medicamento()->create();
    $honorario = Item::factory()
        ->de(TipoItem::Honorario, CategoriaLegalDeDescuento::ConsultaGeneral)
        ->create();

    expect(ExistenciasRelationManager::canViewForRecord($medicamento, 'cualquiera'))->toBeTrue()
        ->and(ExistenciasRelationManager::canViewForRecord($honorario, 'cualquiera'))->toBeFalse();
})->note('Es literalmente lo que se pidió: si no se almacena, no tiene ningún sentido que aparezca con stock.');

it('🔴 las presentaciones se ofrecen tambien en lo que no se almacena', function (): void {
    $honorario = Item::factory()
        ->de(TipoItem::Honorario, CategoriaLegalDeDescuento::ConsultaGeneral)
        ->create();

    expect(PresentacionesRelationManager::canViewForRecord($honorario, 'cualquiera'))->toBeTrue();
})->note('🔴 Una presentación no es solo un envase: es una VARIANTE. HONORARIO DE CONSULTA → «Dr. Carlos», «Dr. Miguel». Sin esto, el catálogo termina con cuarenta filas de honorarios que solo se distinguen por el apellido al final del nombre — que es donde alguien elige el equivocado con el paciente enfrente.');

/*
|--------------------------------------------------------------------------
| Qué códigos estándar tienen sentido para cada tipo
|--------------------------------------------------------------------------
*/

it('🔴 un honorario no usa ninguno de los tres codigos estandar', function (): void {
    expect(TipoItem::Honorario->usaAlgunCodigoEstandar())->toBeFalse()
        ->and(TipoItem::Honorario->usaCie10())->toBeFalse()
        ->and(TipoItem::Honorario->usaLoinc())->toBeFalse()
        ->and(TipoItem::Honorario->usaAtc())->toBeFalse();
})->note('🔴 Los tres sirven para hablar con AFUERA: CIE-10 con SESAL y las aseguradoras, LOINC con los analizadores, ATC para clasificar el medicamento. Un formulario que los muestra igual enseña a saltear campos — y el día que uno de esos campos SÍ importa, también se saltea.');

it('cada codigo aparece donde alguien lo va a usar', function (): void {
    expect(TipoItem::Medicamento->usaAtc())->toBeTrue()
        ->and(TipoItem::Medicamento->usaLoinc())->toBeFalse()
        ->and(TipoItem::EstudioLaboratorio->usaLoinc())->toBeTrue()
        ->and(TipoItem::EstudioLaboratorio->usaCie10())->toBeTrue()
        ->and(TipoItem::Procedimiento->usaCie10())->toBeTrue()
        ->and(TipoItem::Servicio->usaAlgunCodigoEstandar())->toBeFalse();
})->note('ATC clasifica medicamentos, LOINC habla con el analizador del laboratorio, CIE-10 con SESAL y las aseguradoras. Cada uno tiene un interlocutor y solo aparece donde ese interlocutor existe.');
