<?php

declare(strict_types=1);

use App\Domain\Exceptions\ValueObjectInvalidoException;
use App\Domain\ValueObjects\Decimal;

describe('Decimal — qué acepta y qué no', function (): void {
    test('acepta enteros y strings numéricos', function (): void {
        expect(Decimal::de('12.34')->redondeado())->toBe('12.34')
            ->and(Decimal::de(7)->redondeado())->toBe('7.00')
            ->and(Decimal::de('-5.5')->redondeado())->toBe('-5.50');
    });

    test('rechaza texto que no es número', function (): void {
        Decimal::de('doce');
    })->throws(ValueObjectInvalidoException::class);

    test('rechaza notación científica', function (): void {
        Decimal::de('1e5');
    })->throws(ValueObjectInvalidoException::class)
        ->note('is_numeric la acepta y bcmath no la entiende: en PHP 8 tira un ValueError que no dice nada del dominio. Mejor rechazarla acá con un mensaje que sí.');

    test('deFloat existe como escotilla explícita', function (): void {
        expect(Decimal::deFloat(12.34)->redondeado())->toBe('12.34');
    })->note('Se llama a propósito y se ve en el código de quien la usa. Si aparece en un cálculo nuestro de dinero, es un bug: el valor debería haber sido string desde el origen.');
});

describe('Decimal — el problema del punto flotante', function (): void {
    test('0.1 + 0.2 da exactamente 0.3', function (): void {
        expect(Decimal::de('0.1')->sumar('0.2')->redondeado(12))->toBe('0.300000000000');
    })->note('En float esto da 0.30000000000000004, y ese error se reparte en cien mil movimientos hasta que el inventario no cuadra con contabilidad.');

    test('sumar mil veces un centavo da exactamente diez lempiras', function (): void {
        $total = Decimal::cero();

        for ($i = 0; $i < 1000; $i++) {
            $total = $total->sumar('0.01');
        }

        expect($total->redondeado())->toBe('10.00');
    })->note('El caso que rompe a float: el error se acumula paso a paso y no hay una sola operación a la que culpar.');

    test('no redondea en los pasos intermedios', function (): void {
        $resultado = Decimal::de('10')->entre('3')->por('3');

        expect($resultado->redondeado(10))->toBe('10.0000000000');
    })->note('Escala 12 adentro: redondear en cada paso acumula sesgo, y el sesgo tiene signo.');
});

describe('Decimal — redondeo half-up', function (): void {
    test('el medio sube', function (): void {
        expect(Decimal::de('2.345')->redondeado())->toBe('2.35')
            ->and(Decimal::de('2.344')->redondeado())->toBe('2.34')
            ->and(Decimal::de('0.005')->redondeado())->toBe('0.01');
    });

    test('el negativo se aleja del cero', function (): void {
        expect(Decimal::de('-2.345')->redondeado())->toBe('-2.35');
    })->note('Es la convención contable: el valor absoluto redondea igual para arriba y para abajo.');

    test('no produce el cero con signo', function (): void {
        expect(Decimal::de('-0.001')->redondeado())->toBe('0.00');
    })->note('"-0.00" en una factura es una pregunta que alguien va a hacer.');

    test('redondea a la cantidad de decimales que se le pida', function (): void {
        $valor = Decimal::de('1234.56789');

        expect($valor->redondeado(0))->toBe('1235')
            ->and($valor->redondeado(2))->toBe('1234.57')
            ->and($valor->redondeado(4))->toBe('1234.5679');
    });

    test('rechaza decimales negativos', function (): void {
        Decimal::de('1')->redondeado(-1);
    })->throws(ValueObjectInvalidoException::class);
});

describe('Decimal — porcentajes y división', function (): void {
    test('aplica un porcentaje', function (): void {
        expect(Decimal::de('1000')->porcentaje('15')->redondeado())->toBe('150.00');
    });

    test('resta un porcentaje', function (): void {
        expect(Decimal::de('100')->menosPorcentaje('25')->redondeado())->toBe('75.00');
    });

    test('no divide entre cero', function (): void {
        Decimal::de('10')->entre('0');
    })->throws(ValueObjectInvalidoException::class);

    test('tampoco divide entre algo que a la escala interna es cero', function (): void {
        Decimal::de('10')->entre('0.0000000000001');
    })->throws(ValueObjectInvalidoException::class)
        ->note('Trece decimales con escala 12 es cero. bcdiv tiraría un DivisionByZeroError crudo.');
});

describe('Decimal — comparación', function (): void {
    test('compara sin sorpresas de punto flotante', function (): void {
        $a = Decimal::de('0.1')->sumar('0.2');

        expect($a->igualA('0.3'))->toBeTrue()
            ->and(Decimal::de('2')->mayorQue('1'))->toBeTrue()
            ->and(Decimal::de('1')->menorQue('2'))->toBeTrue()
            ->and(Decimal::de('-1')->esNegativo())->toBeTrue()
            ->and(Decimal::cero()->esCero())->toBeTrue();
    })->note('En float, 0.1 + 0.2 == 0.3 es FALSO. Es el chiste más viejo de la programación y sigue costando dinero.');
});
