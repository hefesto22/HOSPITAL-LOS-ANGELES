<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\RangoEdad;
use App\Domain\Enums\TipoItem;
use App\Domain\Exceptions\PrecioNoDerivableException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\EscenarioDePrecio;
use App\Domain\ValueObjects\Monto;
use App\Models\Item;
use App\Models\MargenObjetivo;
use App\Services\CalculadoraDePrecioDeLista;
use Carbon\Carbon;
use Database\Seeders\DescuentosLegalesSeeder;
use Database\Seeders\MargenesObjetivoSeeder;
use Illuminate\Database\QueryException;

function calculadora(): CalculadoraDePrecioDeLista
{
    return app(CalculadoraDePrecioDeLista::class);
}

function conLaPoliticaSembrada(): void
{
    (new DescuentosLegalesSeeder)->run();
    (new MargenesObjetivoSeeder)->run();
}

function unMedicamento(): Item
{
    return Item::factory()->medicamento()->create([
        'categoria_legal_descuento' => CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico,
    ]);
}

const DIA_DEL_SERVICIO = '2026-08-18';

/*
|--------------------------------------------------------------------------
| EL GOLDEN TEST — la política de Mauricio, al céntimo
|--------------------------------------------------------------------------
*/

it('con costo 10 y margen 120 por ciento el precio de lista es 36.67', function (): void {
    conLaPoliticaSembrada();

    $precio = calculadora()->para(
        unMedicamento(),
        Monto::de('10.00'),
        Carbon::parse(DIA_DEL_SERVICIO),
    );

    expect($precio->lista)->toBeMonto('36.67')
        ->and($precio->margenObjetivoComoPorcentaje())->toBe('120 %')
        ->and($precio->descuentoMaximo->comoPorcentaje())->toBe('40 %');
})->note('🔴 El divisor pasó de 0.75 a 0.60 el 20-ago-2026: el Decreto 59-2023 le dio a la CUARTA edad 40 % en medicamentos, y el descuento máximo del Art. 30 es el que fija la lista. 10 × 2.20 / 0.60 = 36.6666… → 36.67. Con el 0.75 viejo daban 29.33 y el paciente de 80 años rompía el piso.');

it('la cuarta edad paga 22.00 y el margen queda clavado en 120 por ciento', function (): void {
    conLaPoliticaSembrada();

    $precio = calculadora()->para(
        unMedicamento(),
        Monto::de('10.00'),
        Carbon::parse(DIA_DEL_SERVICIO),
    );

    $cuarta = $precio->escenarioDe(RangoEdad::Cuarta);

    expect($cuarta?->paga)->toBeMonto('22.00')
        ->and($cuarta?->margenComoPorcentaje())->toBe('120 %')
        ->and($precio->cumpleElPiso())->toBeTrue();
})->note('36.67 − 40 % = 22.002 → 22.00, y (22 − 10) / 10 es exactamente 1.20. El piso lo toca quien más descuento recibe: ahí es donde se prueba que es piso y no promedio.');

it('la tercera edad queda por encima del piso, no clavada en el', function (): void {
    conLaPoliticaSembrada();

    $precio = calculadora()->para(
        unMedicamento(),
        Monto::de('10.00'),
        Carbon::parse(DIA_DEL_SERVICIO),
    );

    $tercera = $precio->escenarioDe(RangoEdad::Tercera);

    expect($tercera?->paga)->toBeMonto('27.50')
        ->and($tercera?->margenComoPorcentaje())->toBe('175 %');
})->note('36.67 − 25 % = 27.5025 → 27.50. Desde que la cuarta edad tiene su propio porcentaje, la tercera dejó de ser el peor caso y el hospital gana MÁS con ella que antes.');

it('el paciente sin descuento paga la lista completa', function (): void {
    conLaPoliticaSembrada();

    $precio = calculadora()->para(
        unMedicamento(),
        Monto::de('10.00'),
        Carbon::parse(DIA_DEL_SERVICIO),
    );

    $normal = $precio->escenarioDe(RangoEdad::Normal);

    expect($normal?->paga)->toBeMonto('36.67')
        ->and($normal?->margenComoPorcentaje())->toBe('266.7 %');
})->note('Nadie recibe un precio de lista distinto por su edad: el descuento cae sobre el mismo precio que ve cualquiera, así que el adulto mayor SÍ paga menos que quien va detrás en la fila.');

it('🔴 la cuarta edad ya no hereda de la tercera: tiene su propio porcentaje', function (): void {
    conLaPoliticaSembrada();

    $precio = calculadora()->para(
        unMedicamento(),
        Monto::de('10.00'),
        Carbon::parse(DIA_DEL_SERVICIO),
    );

    $tercera = $precio->escenarioDe(RangoEdad::Tercera);
    $cuarta = $precio->escenarioDe(RangoEdad::Cuarta);

    expect($cuarta?->paga)->toBeMonto('22.00')
        ->and($tercera?->paga)->toBeMonto('27.50')
        ->and($cuarta?->paga->valor())->not->toBe($tercera?->paga->valor());
})->note('🔴 Hasta el 20-ago-2026 no había filas de cuarta edad y la escalera le daba lo de la tercera: 25 % donde el Decreto 59-2023 manda 40 %. Un paciente de 85 años pagaba 5.50 de más en cada compra de este medicamento, sin que nada avisara.');

it('el peor escenario es el del descuento mas alto', function (): void {
    conLaPoliticaSembrada();

    $precio = calculadora()->para(
        unMedicamento(),
        Monto::de('10.00'),
        Carbon::parse(DIA_DEL_SERVICIO),
    );

    expect($precio->peorEscenario()->rango)->toBe(RangoEdad::Cuarta);
})->note('Es el número que decide si la política se cumple. Mirar el margen del paciente sin descuento es mirar el que nunca está en riesgo. Desde el Decreto 59-2023 el peor caso es la cuarta edad, no la tercera.');

/*
|--------------------------------------------------------------------------
| POR QUÉ LA FÓRMULA DIVIDE
|--------------------------------------------------------------------------
*/

it('sin dividir por el descuento el piso se incumpliria con cada paciente mayor', function (): void {
    $costo = Decimal::de('10.00');
    $margen = Decimal::de('1.20');
    $descuento = Decimal::de('0.25');

    // La cuenta ingenua: costo × (1 + margen), sin dividir.
    $listaIngenua = Monto::de($costo->por($margen->sumar('1')));
    $pagaElMayor = Monto::de(Decimal::de($listaIngenua->valor())->menosPorcentaje('25'));

    $margenReal = Decimal::de($pagaElMayor->valor())->restar('10.00')->entre('10.00');

    expect($listaIngenua)->toBeMonto('22.00')
        ->and($pagaElMayor)->toBeMonto('16.50')
        ->and($margenReal->redondeado(2))->toBe('0.65');
})->note('65 %, no 120 %. Fijar la lista desde el descuento máximo es LO ÚNICO que convierte el piso en garantía en vez de en un objetivo que se incumple con cada paciente mayor.');

it('la lista se redondea una sola vez, al final', function (): void {
    conLaPoliticaSembrada();

    $precio = calculadora()->para(
        unMedicamento(),
        Monto::de('7.77'),
        Carbon::parse(DIA_DEL_SERVICIO),
    );

    // 7.77 × 2.20 / 0.60 = 28.49  →  28.49
    expect($precio->lista)->toBeMonto('28.49')
        ->and($precio->escenarioDe(RangoEdad::Tercera)?->paga)->toBeMonto('21.37')
        ->and($precio->escenarioDe(RangoEdad::Cuarta)?->paga)->toBeMonto('17.09');
})->note('§4.5: se redondea sobre la lista, y el descuento se aplica sobre la lista YA redondeada. 28.49 − 25 % = 21.3675 → 21.37; 28.49 − 40 % = 17.094 → 17.09.');

/*
|--------------------------------------------------------------------------
| La escalera de márgenes
|--------------------------------------------------------------------------
*/

it('el margen del tipo le gana al default de la instalacion', function (): void {
    (new DescuentosLegalesSeeder)->run();

    MargenObjetivo::factory()->del('0.5000')->create();
    MargenObjetivo::factory()->para(TipoItem::Medicamento)->del('1.2000')->create();

    $precio = calculadora()->para(
        unMedicamento(),
        Monto::de('10.00'),
        Carbon::parse(DIA_DEL_SERVICIO),
    );

    expect($precio->margenObjetivoComoPorcentaje())->toBe('120 %');
});

it('cae al default cuando el tipo no tiene margen propio', function (): void {
    (new DescuentosLegalesSeeder)->run();

    MargenObjetivo::factory()->del('0.8000')->create();

    $insumo = Item::factory()->de(
        TipoItem::Insumo,
        CategoriaLegalDeDescuento::MedicamentoYMaterialQuirurgico,
    )->create();

    $precio = calculadora()->para($insumo, Monto::de('10.00'), Carbon::parse(DIA_DEL_SERVICIO));

    expect($precio->margenObjetivoComoPorcentaje())->toBe('80 %');
});

it('respeta la vigencia del margen', function (): void {
    (new DescuentosLegalesSeeder)->run();

    MargenObjetivo::factory()->vigenteEntre('2026-01-01', '2026-06-30')->del('1.0000')->create();
    MargenObjetivo::factory()->vigenteEntre('2026-07-01')->del('1.2000')->create();

    $item = unMedicamento();

    $enMarzo = calculadora()->para($item, Monto::de('10.00'), Carbon::parse('2026-03-15'));
    $enAgosto = calculadora()->para($item, Monto::de('10.00'), Carbon::parse('2026-08-18'));

    expect($enMarzo->margenObjetivoComoPorcentaje())->toBe('100 %')
        ->and($enAgosto->margenObjetivoComoPorcentaje())->toBe('120 %');
})->note('Cuando en 2028 alguien pregunte por qué un producto se vendía a ese precio en marzo, la respuesta es una fila con fecha.');

it('no deja dos defaults globales vigentes a la vez', function (): void {
    MargenObjetivo::factory()->vigenteEntre('2026-01-01')->create();
    MargenObjetivo::factory()->vigenteEntre('2026-06-01')->create();
})->throws(QueryException::class)
    ->note('En SQL NULL = NULL no es verdadero, así que la exclusión va sobre COALESCE(tipo_item, \'*\'). Sin eso, el margen del hospital dependería del ORDER BY.');

/*
|--------------------------------------------------------------------------
| Lo que se niega a calcular
|--------------------------------------------------------------------------
*/

it('no deriva el precio de un servicio', function (): void {
    conLaPoliticaSembrada();

    $consulta = Item::factory()->de(
        TipoItem::Honorario,
        CategoriaLegalDeDescuento::ConsultaEspecializada,
    )->create();

    calculadora()->para($consulta, Monto::de('500.00'), Carbon::parse(DIA_DEL_SERVICIO));
})->throws(PrecioNoDerivableException::class)
    ->note('Ruta B: una habitación, un hemograma o el honorario de un cirujano no tienen costo de compra. Su precio se fija en el tarifario.');

it('no deriva un precio de un costo cero', function (): void {
    conLaPoliticaSembrada();

    calculadora()->para(unMedicamento(), Monto::cero(), Carbon::parse(DIA_DEL_SERVICIO));
})->throws(PrecioNoDerivableException::class)
    ->note('Un margen sobre cero sigue siendo cero: el producto quedaría gratis. Si de verdad entró sin costo —donación, muestra médica— el precio se fija a mano.');

it('no inventa un margen cuando no hay ninguno definido', function (): void {
    (new DescuentosLegalesSeeder)->run();

    calculadora()->para(unMedicamento(), Monto::de('10.00'), Carbon::parse(DIA_DEL_SERVICIO));
})->throws(PrecioNoDerivableException::class)
    ->note('Un default silencioso acá sería un precio inventado con cara de calculado.');

/*
|--------------------------------------------------------------------------
| Lo que ve la pantalla antes de confirmar
|--------------------------------------------------------------------------
*/

it('devuelve un escenario por cada rango de edad', function (): void {
    conLaPoliticaSembrada();

    $precio = calculadora()->para(
        unMedicamento(),
        Monto::de('10.00'),
        Carbon::parse(DIA_DEL_SERVICIO),
    );

    $rangos = array_map(
        static fn (EscenarioDePrecio $escenario): RangoEdad => $escenario->rango,
        $precio->escenarios,
    );

    expect($rangos)->toBe([RangoEdad::Normal, RangoEdad::Tercera, RangoEdad::Cuarta]);
})->note('§4.5: antes de confirmar un precio, la pantalla muestra el margen resultante en CADA rango, ya con el descuento aplicado. La decisión se toma con los números a la vista, no a ojo.');

it('un item fuera del Articulo 30 se vende al margen limpio', function (): void {
    conLaPoliticaSembrada();

    $cafeteria = Item::factory()->de(
        TipoItem::Insumo,
        CategoriaLegalDeDescuento::SinDescuentoLegal,
    )->create();

    $precio = calculadora()->para($cafeteria, Monto::de('10.00'), Carbon::parse(DIA_DEL_SERVICIO));

    expect($precio->lista)->toBeMonto('22.00')
        ->and($precio->peorEscenario()->margenComoPorcentaje())->toBe('120 %');
})->note('Sin descuento legal el divisor es 1, así que la lista es el margen puro. Es el único caso donde la cuenta ingenua y la correcta coinciden.');
