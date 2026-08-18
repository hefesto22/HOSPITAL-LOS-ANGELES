<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\RangoEdad;
use App\Domain\ValueObjects\Monto;
use App\Models\DescuentoLegal;
use App\Models\Item;
use App\Services\ResolutorDeDescuentoLegal;
use Carbon\Carbon;
use Database\Seeders\DescuentosLegalesSeeder;
use Illuminate\Database\QueryException;

function resolutor(): ResolutorDeDescuentoLegal
{
    return app(ResolutorDeDescuentoLegal::class);
}

/*
|--------------------------------------------------------------------------
| Lo que la base no deja pasar
|--------------------------------------------------------------------------
*/

it('no deja dos descuentos vigentes a la vez para lo mismo', function (): void {
    DescuentoLegal::factory()->vigenteEntre('2007-07-21')->create();
    DescuentoLegal::factory()->vigenteEntre('2030-01-01')->create();
})->throws(QueryException::class)
    ->note('Con dos filas vigentes el mismo día, el descuento que gana depende del ORDER BY — y es un derecho legal, no una preferencia.');

it('deja encadenar vigencias contiguas', function (): void {
    DescuentoLegal::factory()->vigenteEntre('2007-07-21', '2029-12-31')->del('0.2500')->create();
    DescuentoLegal::factory()->vigenteEntre('2030-01-01')->del('0.3000')->create();

    expect(DescuentoLegal::query()->count())->toBe(2);
})->note('Una reforma no edita la fila vieja: le pone fecha de fin y agrega la nueva. La historia es el punto.');

it('no deja un porcentaje mayor a uno', function (): void {
    DescuentoLegal::factory()->del('1.5000')->create();
})->throws(QueryException::class)
    ->note('Se guarda como fracción: 0.2500 es 25 %. Un 25 guardado como 25 sería un descuento del 2500 %.');

it('no deja registrar descuento para el rango normal', function (): void {
    DescuentoLegal::factory()->de(
        CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico,
        RangoEdad::Normal,
    )->create();
})->throws(QueryException::class)
    ->note('No tener descuento no es un descuento de cero: es la ausencia de derecho. Una fila en 0 % haría creer que alguien la decidió.');

it('exige que el porcentaje cite su fundamento', function (): void {
    DescuentoLegal::factory()->create(['fundamento' => 'ley']);
})->throws(QueryException::class)
    ->note('Es lo que hay que poder mostrar cuando llega una denuncia a la línea 115.');

/*
|--------------------------------------------------------------------------
| El seeder contra la ley
|--------------------------------------------------------------------------
*/

it('siembra exactamente los ocho numerales del Articulo 30', function (): void {
    (new DescuentosLegalesSeeder)->run();

    $sembrado = DescuentoLegal::query()
        ->orderBy('categoria_legal')
        ->get()
        ->mapWithKeys(fn (DescuentoLegal $d): array => [
            $d->categoria_legal->value => $d->fraccion()->redondeado(2),
        ])
        ->all();

    expect($sembrado)->toBe([
        'consulta_especializada'          => '0.30',
        'consulta_general'                => '0.25',
        'intervencion_quirurgica'         => '0.30',
        'medicamento_material_quirurgico' => '0.25',
        'medicina_computarizada'          => '0.30',
        'odontologia_oftalmologia'        => '0.30',
        'radiologia_laboratorio'          => '0.30',
        'servicio_hospitalario'           => '0.25',
    ]);
})->note('Art. 30 del Decreto 199-2006, numerales 5 a 9. De estos ocho números sale el precio de lista de todo el catálogo.');

it('no siembra cuarta edad porque el 45-2025 no reformo salud', function (): void {
    (new DescuentosLegalesSeeder)->run();

    expect(DescuentoLegal::query()->where('rango_edad', RangoEdad::Cuarta->value)->count())->toBe(0);
})->note('El Decreto 45-2025 reformó el Art. 31 —energía, agua, cable—, no el 30. En salud el único umbral es 60 años.');

it('se puede correr dos veces sin duplicar', function (): void {
    (new DescuentosLegalesSeeder)->run();
    (new DescuentosLegalesSeeder)->run();

    expect(DescuentoLegal::query()->count())->toBe(8);
});

it('marca la receta solo en medicamentos', function (): void {
    (new DescuentosLegalesSeeder)->run();

    /*
     * `pluck()` sobre Eloquent aplica los casts del modelo, así que acá
     * vienen instancias del enum y no strings. Se compara contra el enum
     * —los casos son singletons, así que la comparación estricta
     * funciona— en vez de mapear a `->value`: dice mejor lo que se está
     * afirmando.
     */
    $conReceta = DescuentoLegal::query()
        ->where('exige_receta', true)
        ->pluck('categoria_legal')
        ->all();

    expect($conReceta)->toBe([CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico]);
})->note('Art. 34: receta original firmada y sellada. Es propiedad del DESCUENTO, no del ítem — una reforma podría quitar el requisito sin tocar el catálogo.');

/*
|--------------------------------------------------------------------------
| El resolutor
|--------------------------------------------------------------------------
*/

it('resuelve contra la fecha del servicio, no contra hoy', function (): void {
    DescuentoLegal::factory()->vigenteEntre('2007-07-21', '2029-12-31')->del('0.2500')->create();
    DescuentoLegal::factory()->vigenteEntre('2030-01-01')->del('0.3000')->create();

    $hoy = resolutor()->paraCategoria(
        CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico,
        RangoEdad::Tercera,
        Carbon::parse('2026-08-18'),
    );

    $despues = resolutor()->paraCategoria(
        CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico,
        RangoEdad::Tercera,
        Carbon::parse('2031-03-15'),
    );

    expect($hoy->comoPorcentaje())->toBe('25 %')
        ->and($despues->comoPorcentaje())->toBe('30 %');
})->note('Una factura de 2027 reimpresa en 2031 tiene que salir con el porcentaje de 2027: ese dinero ya se cobró.');

it('el paciente de cuarta edad recibe lo de tercera cuando la ley no le da nada propio', function (): void {
    (new DescuentosLegalesSeeder)->run();

    $descuento = resolutor()->paraCategoria(
        CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico,
        RangoEdad::Cuarta,
        Carbon::parse('2026-08-18'),
    );

    expect($descuento->aplica())->toBeTrue()
        ->and($descuento->comoPorcentaje())->toBe('25 %');
})->note('Un paciente de 80 años también tiene 60. Buscar solo el rango exacto y rendirse le negaría el descuento a quien más derecho tiene, con la lógica pareciendo correcta.');

it('cuando la cuarta edad si tiene fila propia, gana la mejor', function (): void {
    (new DescuentosLegalesSeeder)->run();

    DescuentoLegal::factory()
        ->de(CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico, RangoEdad::Cuarta)
        ->del('0.4000')
        ->create(['fundamento' => 'Reforma hipotética que extiende la cuarta edad a salud']);

    $cuarta = resolutor()->paraCategoria(
        CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico,
        RangoEdad::Cuarta,
        Carbon::parse('2026-08-18'),
    );

    $tercera = resolutor()->paraCategoria(
        CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico,
        RangoEdad::Tercera,
        Carbon::parse('2026-08-18'),
    );

    expect($cuarta->comoPorcentaje())->toBe('40 %')
        ->and($tercera->comoPorcentaje())->toBe('25 %');
})->note('El día que el Congreso extienda la cuarta edad a salud, es un INSERT y no se toca una línea de código.');

it('nunca le da menos a alguien por ser mas viejo', function (): void {
    DescuentoLegal::factory()
        ->de(CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico, RangoEdad::Tercera)
        ->del('0.3000')->create();

    DescuentoLegal::factory()
        ->de(CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico, RangoEdad::Cuarta)
        ->del('0.1000')->create(['fundamento' => 'Fila cargada mal a propósito para esta prueba']);

    $cuarta = resolutor()->paraCategoria(
        CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico,
        RangoEdad::Cuarta,
        Carbon::parse('2026-08-18'),
    );

    expect($cuarta->comoPorcentaje())->toBe('30 %');
})->note('Protege contra un dato mal cargado: se consulta toda la escalera y se toma el mayor, no el más específico.');

it('el paciente sin edad de descuento no recibe nada', function (): void {
    (new DescuentosLegalesSeeder)->run();

    $descuento = resolutor()->paraCategoria(
        CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico,
        RangoEdad::Normal,
        Carbon::parse('2026-08-18'),
    );

    expect($descuento->aplica())->toBeFalse()
        ->and($descuento->explicacion())->toBe('Sin descuento de adulto mayor.');
});

it('un item fuera del Articulo 30 no recibe nada aunque el paciente tenga la edad', function (): void {
    (new DescuentosLegalesSeeder)->run();

    $cafeteria = Item::factory()->create([
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::SinDescuentoLegal,
    ]);

    $descuento = resolutor()->para($cafeteria, RangoEdad::Cuarta, Carbon::parse('2026-08-18'));

    expect($descuento->aplica())->toBeFalse();
})->note('La cafetería y el parqueo no están en el Art. 30. Tampoco el tratamiento de belleza estética, que además va gravado con ISV.');

it('resuelve el descuento de un item del catalogo', function (): void {
    (new DescuentosLegalesSeeder)->run();

    $radiografia = Item::factory()->create([
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::RadiologiaYLaboratorio,
    ]);

    $descuento = resolutor()->para($radiografia, RangoEdad::Tercera, Carbon::parse('2026-08-18'));

    expect($descuento->comoPorcentaje())->toBe('30 %')
        ->and($descuento->explicacion())->toContain('Art. 30, numerales 8 y 9');
});

/*
|--------------------------------------------------------------------------
| El máximo — de acá sale el precio de lista
|--------------------------------------------------------------------------
*/

it('el maximo de un medicamento es 25 por ciento', function (): void {
    (new DescuentosLegalesSeeder)->run();

    $maximo = resolutor()->maximoPara(
        CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico,
        Carbon::parse('2026-08-18'),
    );

    expect($maximo->comoPorcentaje())->toBe('25 %');
})->note('§4.5: precio_lista = costo × (1 + margen) / (1 − descuento_máximo). Calcularlo desde el peor caso es lo que convierte el piso de 120 % en garantía.');

it('aplicado a un precio de lista da el neto exacto', function (): void {
    (new DescuentosLegalesSeeder)->run();

    $descuento = resolutor()->maximoPara(
        CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico,
        Carbon::parse('2026-08-18'),
    );

    $lista = Monto::de('29.33');

    expect($descuento->netoDe($lista))->toBeMonto('22.00')
        ->and($descuento->sobre($lista))->toBeMonto('7.33');
})->note('29.33 × 0.25 = 7.3325 y 29.33 − 7.3325 = 21.9975 → 22.00. Es el piso de 120 % tocándose exacto, ahora con la tabla real en el medio.');
