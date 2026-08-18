<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\MagnitudDeMedida;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoItem;
use App\Models\Item;
use App\Models\Unidad;
use App\Support\NormalizadorDeTexto;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| El catálogo no sabe cuánto cuesta nada
|--------------------------------------------------------------------------
*/

it('no tiene columna de precio', function (): void {
    $columnas = Schema::getColumnListing('items');

    expect($columnas)->not->toContain('precio')
        ->and($columnas)->not->toContain('costo')
        ->and($columnas)->not->toContain('margen');
})->note('§9.H2: el precio es una función con vigencia por convenio, jamás una columna del catálogo. Con precio-columna, renegociar con una aseguradora obliga a duplicar el catálogo — y en seis meses hay cuatro, ninguno correcto.');

it('no tiene sede: un paracetamol es el mismo producto en las dos sedes', function (): void {
    expect(Schema::getColumnListing('items'))->not->toContain('sede_id');
})->note('Lo que cambia por sede es el PRECIO y el COSTO, y esos viven en el tarifario y en el kardex.');

/*
|--------------------------------------------------------------------------
| Lo que la base no deja pasar
|--------------------------------------------------------------------------
*/

it('no guarda un medicamento sin unidad de dispensacion', function (): void {
    Item::factory()->create([
        'tipo'                      => TipoItem::Medicamento,
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico,
        'unidad_dispensacion_id'    => null,
    ]);
})->throws(QueryException::class)
    ->note('Sin unidad no se puede costear ni descontar del kardex, y el error aparecería recién en la primera dispensación, de noche, con el paciente esperando.');

it('no deja fraccionable a medias', function (): void {
    Item::factory()->medicamento()->create([
        'fraccionable'          => true,
        'unidad_fraccion_id'    => null,
        'fracciones_por_unidad' => null,
    ]);
})->throws(QueryException::class)
    ->note('Sin unidad de fracción ni cantidad, una dispensación por dosis divide por NULL.');

it('la base rechaza un controlado sin receta aunque se escriba directo', function (): void {
    $unidad = Unidad::factory()->create();

    DB::table('items')->insert([
        'codigo'                    => 'MED-9001',
        'nombre'                    => 'MORFINA',
        'tipo'                      => 'medicamento',
        'regimen_isv'               => 'exento',
        'politica_cargo'            => 'cobrable',
        'categoria_legal_descuento' => 'medicamento_material_quirurgico',
        'unidad_dispensacion_id'    => $unidad->getKey(),
        'es_controlado'             => true,
        'requiere_receta'           => false,
        'vigencia_desde'            => '2026-01-01',
    ]);
})->throws(QueryException::class)
    ->note('Un controlado sin receta es una infracción ante ARSA. El modelo lo corrige; la base lo rechaza aunque el modelo no exista — un import del sistema viejo no pasa por Eloquent.');

it('la base rechaza pedir lote sobre algo que no es fisico', function (): void {
    DB::table('items')->insert([
        'codigo'                    => 'HON-9001',
        'nombre'                    => 'CONSULTA',
        'tipo'                      => 'honorario',
        'regimen_isv'               => 'exento',
        'politica_cargo'            => 'cobrable',
        'categoria_legal_descuento' => 'consulta_general',
        'requiere_lote'             => true,
        'vigencia_desde'            => '2026-01-01',
    ]);
})->throws(QueryException::class);

it('el modelo apaga las banderas de farmacia cuando el item deja de ser fisico', function (): void {
    $item = Item::factory()->controlado()->create();

    expect($item->requiere_lote)->toBeTrue()
        ->and($item->es_controlado)->toBeTrue();

    $item->update(['tipo' => TipoItem::Honorario]);

    expect($item->fresh()?->requiere_lote)->toBeFalse()
        ->and($item->fresh()?->es_controlado)->toBeFalse();
})->note('En la pantalla, al cambiar el tipo la pestaña de farmacia se oculta y sus campos ya no se envían: el valor viejo seguiría en el modelo y la base tiraría un error de SQL en la cara del usuario.');

it('el modelo enciende la receta al marcar controlado', function (): void {
    $item = Item::factory()->medicamento()->create(['requiere_receta' => false]);

    $item->update(['es_controlado' => true]);

    expect($item->fresh()?->requiere_receta)->toBeTrue();
});

it('el modelo limpia la fraccion cuando el item deja de ser fraccionable', function (): void {
    $item = Item::factory()->fraccionable()->create();

    expect($item->unidad_fraccion_id)->not->toBeNull();

    $item->update(['fraccionable' => false]);

    expect($item->fresh()?->unidad_fraccion_id)->toBeNull()
        ->and($item->fresh()?->fracciones_por_unidad)->toBeNull();
});

it('no deja una vigencia que termina antes de empezar', function (): void {
    Item::factory()->create([
        'vigencia_desde' => '2026-06-01',
        'vigencia_hasta' => '2026-01-01',
    ]);
})->throws(QueryException::class);

it('no deja dos items con el mismo codigo', function (): void {
    Item::factory()->create(['codigo' => 'MED-0001']);
    Item::factory()->create(['codigo' => 'MED-0001']);
})->throws(QueryException::class);

it('libera el codigo cuando el item se borra', function (): void {
    $primero = Item::factory()->create(['codigo' => 'MED-0001']);
    $primero->delete();

    $segundo = Item::factory()->create(['codigo' => 'MED-0001']);

    expect($segundo->exists)->toBeTrue();
})->note('El índice único es parcial sobre lo no borrado: un ítem retirado no bloquea el código para siempre.');

/*
|--------------------------------------------------------------------------
| Búsqueda tolerante
|--------------------------------------------------------------------------
*/

it('encuentra un item escrito sin tildes', function (): void {
    Item::factory()->create(['nombre' => 'ACETAMINOFÉN 500 MG TABLETA']);

    expect(Item::buscar('acetaminofen')->pluck('nombre')->all())
        ->toContain('ACETAMINOFÉN 500 MG TABLETA');
})->note('Nadie en el mostrador va a probar tres grafías: si no aparece a la primera, se carga mal o se crea duplicado.');

it('encuentra por principio activo', function (): void {
    Item::factory()->medicamento()->create([
        'nombre'           => 'ACETAMINOFÉN 500 MG TABLETA',
        'principio_activo' => 'PARACETAMOL',
    ]);

    expect(Item::buscar('paracetamol'))->toHaveCount(1);
})->note('El médico prescribe por principio activo y la caja dice otra cosa.');

it('encuentra por codigo exacto', function (): void {
    Item::factory()->create(['codigo' => 'LAB-0042', 'nombre' => 'HEMOGRAMA COMPLETO']);

    expect(Item::buscar('LAB-0042')->pluck('codigo')->all())->toContain('LAB-0042');
})->note('El código va por ILIKE, no por trigramas: los trigramas de un código con guiones son ruido.');

it('devuelve vacio y no revienta con un termino de solo espacios', function (): void {
    Item::factory()->count(3)->create();

    expect(Item::buscar('   ')->count())->toBe(3);
})->note('Un término vacío no filtra; lo que no puede hacer es tirar un error de SQL.');

it('la clave de busqueda de PHP coincide con la que calculo Postgres', function (): void {
    $item = Item::factory()->medicamento()->create([
        'codigo'           => 'MED-0007',
        'nombre'           => 'ACETAMINOFÉN 500 MG',
        'principio_activo' => 'PARACETAMOL',
    ]);

    $esperada = NormalizadorDeTexto::clave('MED-0007 ACETAMINOFÉN 500 MG PARACETAMOL');

    expect($item->fresh()?->nombre_busqueda)->toBe($esperada);
})->note('El gemelo en SQL y el de PHP tienen que dar lo mismo. Si se separan, el ítem existe, nadie lo encuentra y alguien lo carga de nuevo — sin un solo error en el log.');

/*
|--------------------------------------------------------------------------
| Vigencia — contra la fecha del servicio, nunca contra "hoy"
|--------------------------------------------------------------------------
*/

it('sabe si se ofrecia en una fecha pasada', function (): void {
    $item = Item::factory()->vigenteEntre('2026-01-01', '2026-03-31')->create();

    expect($item->vigenteEn(now()->parse('2026-02-15')))->toBeTrue()
        ->and($item->vigenteEn(now()->parse('2026-05-15')))->toBeFalse()
        ->and($item->vigenteEn(now()->parse('2025-12-31')))->toBeFalse();
})->note('Un cargo de febrero se explica con el catálogo de febrero. Preguntar por "hoy" reimprime la factura vieja con datos nuevos.');

it('incluye el ultimo dia de vigencia completo', function (): void {
    $item = Item::factory()->vigenteEntre('2026-01-01', '2026-03-31')->create();

    expect($item->vigenteEn(now()->parse('2026-03-31 23:59:00')))->toBeTrue();
})->note('La cirugía de las 23:40 del último día se cobra: la vigencia es por día, no por instante.');

it('filtra por vigencia en la consulta', function (): void {
    Item::factory()->vigenteEntre('2026-01-01', '2026-03-31')->create(['codigo' => 'VIEJO']);
    Item::factory()->vigenteEntre('2026-04-01')->create(['codigo' => 'NUEVO']);

    expect(Item::query()->vigentesEn(now()->parse('2026-02-01'))->pluck('codigo')->all())
        ->toBe(['VIEJO'])
        ->and(Item::query()->vigentesEn(now()->parse('2026-05-01'))->pluck('codigo')->all())
        ->toBe(['NUEVO']);
});

/*
|--------------------------------------------------------------------------
| Texto canónico
|--------------------------------------------------------------------------
*/

it('guarda nombre y codigo en mayusculas venga de donde venga', function (): void {
    $item = Item::factory()->create([
        'codigo'           => 'med-0100',
        'nombre'           => 'jarabe para la tos',
        'principio_activo' => 'dextrometorfano',
    ]);

    expect($item->codigo)->toBe('MED-0100')
        ->and($item->nombre)->toBe('JARABE PARA LA TOS')
        ->and($item->principio_activo)->toBe('DEXTROMETORFANO');
});

it('NO toca los codigos estandar', function (): void {
    $item = Item::factory()->create([
        'codigo_loinc' => '718-7',
        'codigo_atc'   => 'N02BE01',
        'codigo_cie10' => 'J18.9',
    ]);

    expect($item->codigo_loinc)->toBe('718-7')
        ->and($item->codigo_atc)->toBe('N02BE01')
        ->and($item->codigo_cie10)->toBe('J18.9');
})->note('Se guardan tal como los publica quien los mantiene. Canonizarlos es inofensivo hoy y una bomba el día que se cargue un catálogo real.');

it('NO toca el simbolo de la unidad', function (): void {
    $unidad = Unidad::factory()->create([
        'codigo'   => 'mg',
        'nombre'   => 'miligramo',
        'simbolo'  => 'mg',
        'magnitud' => MagnitudDeMedida::Masa,
    ]);

    expect($unidad->codigo)->toBe('MG')
        ->and($unidad->nombre)->toBe('MILIGRAMO')
        ->and($unidad->simbolo)->toBe('mg');
})->note('"mg" y "Mg" no son lo mismo, y en una dosis esa diferencia mata.');

/*
|--------------------------------------------------------------------------
| Reglas derivadas
|--------------------------------------------------------------------------
*/

it('solo medicamentos e insumos mueven inventario', function (): void {
    $medicamento = Item::factory()->medicamento()->create();
    $honorario = Item::factory()->create(['tipo' => TipoItem::Honorario]);

    expect($medicamento->mueveInventario())->toBeTrue()
        ->and($honorario->mueveInventario())->toBeFalse();
});

it('usa el default de la instalacion cuando el item no fija su caducidad post apertura', function (): void {
    config()->set('sihla.inventario.horas_caducidad_post_apertura_por_defecto', 36);

    $conValor = Item::factory()->fraccionable()->create(['horas_caducidad_post_apertura' => 12]);
    $sinValor = Item::factory()->medicamento()->create();

    expect($conValor->horasDeVidaAbierto())->toBe(12)
        ->and($sinValor->horasDeVidaAbierto())->toBe(36);
});

it('el regimen de ISV se guarda por item, no por factura', function (): void {
    $estancia = Item::factory()->create(['regimen_isv' => RegimenIsv::Exento]);
    $estetica = Item::factory()->create(['regimen_isv' => RegimenIsv::Gravado15]);

    expect($estancia->regimen_isv->tasa())->toBe(0.0)
        ->and($estetica->regimen_isv->tasa())->toBe(0.15);
})->note('§8.6.1: una misma cuenta mezcla hospitalización exenta con una liposucción gravada y con la cafetería. Un flag de encabezado no puede representar eso.');
