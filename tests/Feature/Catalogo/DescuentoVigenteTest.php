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

it('siembra exactamente los ocho numerales del Articulo 30 para la tercera edad', function (): void {
    (new DescuentosLegalesSeeder)->run();

    /*
     * ⚠️ Filtrado por rango desde el 20-ago-2026. Sin el `where`, las
     * dieciséis filas colapsan de a pares en el `mapWithKeys` —misma
     * categoría, dos edades— y la aserción pasa a depender del ORDER BY:
     * a veces compara la tercera edad y a veces la cuarta.
     */
    $sembrado = DescuentoLegal::query()
        ->where('rango_edad', RangoEdad::Tercera->value)
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

it('🔴 siembra la cuarta edad con sus propios porcentajes, mas altos que los de la tercera', function (): void {
    (new DescuentosLegalesSeeder)->run();

    /*
     * ⚠️ El `orderBy` no es decorativo: `toBe()` compara arrays con su
     * orden, y sin él Postgres devuelve las filas como se le da la gana.
     * El test pasaba o fallaba según el plan de la consulta, con los ocho
     * porcentajes correctos — que es la peor forma de fallar.
     */
    $cuarta = DescuentoLegal::query()
        ->where('rango_edad', RangoEdad::Cuarta->value)
        ->orderBy('categoria_legal')
        ->get()
        ->mapWithKeys(fn (DescuentoLegal $d): array => [
            $d->categoria_legal->value => $d->fraccion()->redondeado(2),
        ])
        ->all();

    expect($cuarta)->toBe([
        'consulta_especializada'          => '0.35',
        'consulta_general'                => '0.30',
        'intervencion_quirurgica'         => '0.40',
        'medicamento_material_quirurgico' => '0.40',
        'medicina_computarizada'          => '0.40',
        'odontologia_oftalmologia'        => '0.40',
        'radiologia_laboratorio'          => '0.40',
        'servicio_hospitalario'           => '0.30',
    ]);
})->note('🔴 Este test decía lo contrario hasta el 20-ago-2026 —«no siembra cuarta edad porque el 45-2025 no reformó salud»— y estaba mirando el decreto equivocado. El 45-2025 reformó el Art. 31 (energía, agua, cable); el que reformó el Art. 30 es el **Decreto 59-2023**, publicado en La Gaceta el 14-feb-2024. Sin estas filas, la escalera le daba a un paciente de 85 años el 25 % de la tercera donde la ley manda 40 %.');

it('la cuarta edad recibe mas que la tercera en toda categoria', function (): void {
    (new DescuentosLegalesSeeder)->run();

    foreach (CategoriaLegalDeDescuento::cases() as $categoria) {
        if ($categoria === CategoriaLegalDeDescuento::SinDescuentoLegal) {
            continue;
        }

        $tercera = DescuentoLegal::query()
            ->where('categoria_legal', $categoria->value)
            ->where('rango_edad', RangoEdad::Tercera->value)
            ->value('porcentaje');

        $cuarta = DescuentoLegal::query()
            ->where('categoria_legal', $categoria->value)
            ->where('rango_edad', RangoEdad::Cuarta->value)
            ->value('porcentaje');

        expect((float) $cuarta)->toBeGreaterThan(
            (float) $tercera,
            "La cuarta edad debería recibir más que la tercera en {$categoria->value}",
        );
    }
})->note('Es la forma de la reforma: el 59-2023 no reemplazó los porcentajes, los subió para quien pasa de 80. Si alguna categoría quedara igual o por debajo, es que se cargó mal.');

it('se puede correr dos veces sin duplicar', function (): void {
    (new DescuentosLegalesSeeder)->run();
    (new DescuentosLegalesSeeder)->run();

    expect(DescuentoLegal::query()->count())->toBe(16);
})->note('Ocho categorías × dos rangos de edad.');

it('marca la receta solo en medicamentos', function (): void {
    (new DescuentosLegalesSeeder)->run();

    /*
     * `pluck()` sobre Eloquent aplica los casts del modelo, así que acá
     * vienen instancias del enum y no strings. Se compara contra el enum
     * —los casos son singletons, así que la comparación estricta
     * funciona— en vez de mapear a `->value`: dice mejor lo que se está
     * afirmando.
     */
    /*
     * ⚠️ `unique()` desde el 20-ago-2026: medicamentos tiene ahora DOS
     * filas —tercera y cuarta edad— y las dos exigen receta, así que sin
     * esto vendría la misma categoría repetida. Lo que se afirma es qué
     * categorías la exigen, no cuántas filas hay.
     */
    $conReceta = DescuentoLegal::query()
        ->where('exige_receta', true)
        ->pluck('categoria_legal')
        ->unique()
        ->values()
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
    /*
     * Sin el seeder a propósito: desde el Decreto 59-2023 TODAS las
     * categorías tienen fila de cuarta edad, así que la escalera ya no se
     * puede probar con datos reales. Se arma el caso a mano —solo fila de
     * tercera— porque la red tiene que seguir estando: una reforma futura
     * puede agregar una categoría y dejarla sin porcentaje de cuarta.
     */
    DescuentoLegal::factory()
        ->de(CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico, RangoEdad::Tercera)
        ->del('0.2500')
        ->create();

    $descuento = resolutor()->paraCategoria(
        CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico,
        RangoEdad::Cuarta,
        Carbon::parse('2026-08-18'),
    );

    expect($descuento->aplica())->toBeTrue()
        ->and($descuento->comoPorcentaje())->toBe('25 %');
})->note('Un paciente de 80 años también tiene 60. Buscar solo el rango exacto y rendirse le negaría el descuento a quien más derecho tiene, con la lógica pareciendo correcta.');

it('cuando la cuarta edad tiene fila propia, gana la suya y no la de tercera', function (): void {
    /*
     * 🔴 Este test creaba a mano una fila «hipotética» de cuarta edad
     * ENCIMA del seeder. Dejó de poder hacerlo el 20-ago-2026, y por la
     * mejor razón posible: el seeder ya la siembra, así que la segunda
     * chocaba contra el EXCLUDE `descuentos_legales_sin_traslape`.
     *
     * La hipótesis dejó de ser hipótesis: el Decreto 59-2023 le dio a la
     * cuarta edad el 40 % en medicamentos, y esto ahora verifica el dato
     * real en vez de uno inventado.
     */
    (new DescuentosLegalesSeeder)->run();

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
})->note('El Congreso extendió la cuarta edad a salud y fue exactamente eso: un INSERT. No se tocó una línea de código del resolutor.');

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

it('el maximo de un medicamento es 40 por ciento: el de la cuarta edad', function (): void {
    (new DescuentosLegalesSeeder)->run();

    $maximo = resolutor()->maximoPara(
        CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico,
        Carbon::parse('2026-08-18'),
    );

    expect($maximo->comoPorcentaje())->toBe('40 %');
})->note('§4.5: precio_lista = costo × (1 + margen) / (1 − descuento_máximo). Calcularlo desde el peor caso es lo que convierte el piso de 120 % en garantía — y desde el Decreto 59-2023 el peor caso es el paciente de 80 años, no el de 60.');

it('aplicado a un precio de lista da el neto exacto', function (): void {
    (new DescuentosLegalesSeeder)->run();

    $descuento = resolutor()->maximoPara(
        CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico,
        Carbon::parse('2026-08-18'),
    );

    $lista = Monto::de('36.67');

    expect($descuento->netoDe($lista))->toBeMonto('22.00')
        ->and($descuento->sobre($lista))->toBeMonto('14.67');
})->note('36.67 × 0.40 = 14.668 y 36.67 − 14.668 = 22.002 → 22.00. Es el piso de 120 % tocándose exacto sobre un costo de 10, ahora con la tabla real en el medio. La lista subió de 29.33 a 36.67 justamente para que el paciente de 80 años siga dejando el 120 %.');
