<?php

declare(strict_types=1);

use App\Domain\ValueObjects\Decimal;
use App\Support\NumeroDeFormulario;

/**
 * El conversor que evita el cero silencioso.
 *
 * ⚠️ Este archivo nace de un bug real: las tres pantallas del módulo
 * tenían su propio conversor y los tres devolvían `'0'` ante cualquier
 * cosa que no entendieran. En la pantalla de contar, donde el cero es un
 * valor LEGAL, eso guardaba la baja del lote completo sin un solo mensaje
 * de error.
 *
 * La regla que estos tests protegen: **nulo significa «no entiendo
 * esto», y no es lo mismo que cero.**
 */
it('acepta lo que de verdad llega de un formulario', function (mixed $entrada, string $esperado): void {
    $resultado = NumeroDeFormulario::aDecimal($entrada);

    expect($resultado)->toBeInstanceOf(Decimal::class)
        ->and($resultado?->redondeado(4))->toBe($esperado);
})->with([
    'entero'               => [12, '12.0000'],
    'entero cero'          => [0, '0.0000'],
    'cadena entera'        => ['12', '12.0000'],
    'cadena decimal'       => ['12.5', '12.5000'],
    'float decimal'        => [12.5, '12.5000'],
    'float entero'         => [7.0, '7.0000'],
    'coma como decimal'    => ['12,5', '12.5000'],
    'sin cero adelante'    => ['.5', '0.5000'],
    'punto al final'       => ['5.', '5.0000'],
    'ceros a la izquierda' => ['0005', '5.0000'],
    'con espacios'         => ['  12.5  ', '12.5000'],
    'cero como cadena'     => ['0', '0.0000'],
]);

it('devuelve nulo —y NO cero— cuando no entiende', function (mixed $entrada): void {
    expect(NumeroDeFormulario::aDecimal($entrada))->toBeNull();
})->with([
    'nulo'                => [null],
    'vacío'               => [''],
    'solo espacios'       => ['   '],
    'texto'               => ['doce'],
    'notación científica' => ['1e3'],
    'negativo'            => ['-5'],
    'arreglo'             => [[12]],
    'booleano'            => [true],
    'punto solo'          => ['.'],
    'dos puntos'          => ['1.2.3'],
]);

it('rechaza la notacion cientifica aunque PHP la considere numerica', function (): void {
    expect(NumeroDeFormulario::aDecimal('1e3'))->toBeNull();
})->note('`is_numeric("1e3")` es verdadero, así que «1e3» pasa la validación `numeric` de Filament — y son mil unidades que nadie quiso poner. Acá se rechaza a propósito. (La aserción sobre `is_numeric` no va: PHPStan resuelve el literal en tiempo de análisis y la marca como comparación siempre verdadera.)');

it('el signo nunca viaja en el numero', function (): void {
    expect(NumeroDeFormulario::aDecimal('-5'))->toBeNull();
})->note('El signo lo pone el tipo de movimiento. Un menos tecleado de más es cómo una rotura termina sumando existencias.');

it('el respaldo solo se usa donde no puede confundirse con un dato', function (): void {
    $cero = Decimal::cero();

    expect(NumeroDeFormulario::aDecimalO('doce', $cero)->redondeado(4))->toBe('0.0000')
        ->and(NumeroDeFormulario::aDecimalO('3', $cero)->redondeado(4))->toBe('3.0000');
})->note('Sirve para una tolerancia —donde cero es el valor MÁS estricto—, nunca para una cantidad contada.');
