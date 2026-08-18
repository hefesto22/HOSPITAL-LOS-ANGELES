<?php

declare(strict_types=1);

use App\Domain\Exceptions\ValueObjectInvalidoException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\Monto;

describe('Monto — invariantes del constructor', function (): void {
    test('rechaza valor negativo', function (): void {
        Monto::de('-1');
    })->throws(ValueObjectInvalidoException::class)
        ->note('El signo lo pone el MOVIMIENTO, no la cantidad. Una nota de crédito no es una factura de menos ochocientos: es otro documento por ochocientos.');

    test('rechaza moneda con longitud distinta a 3', function (): void {
        Monto::de('100', 'HONDURAS');
    })->throws(ValueObjectInvalidoException::class);

    test('acepta cero', function (): void {
        expect(Monto::cero()->esCero())->toBeTrue();
    });

    test('normaliza la moneda a mayúsculas', function (): void {
        expect(Monto::de('100', 'hnl')->moneda)->toBe('HNL');
    });

    test('construye desde centavos', function (): void {
        expect(Monto::deCentavos(12345)->valor())->toBe('123.45');
    });
});

describe('Monto — aritmética', function (): void {
    test('suma dos montos de la misma moneda', function (): void {
        expect(Monto::de('100.50')->sumar(Monto::de('50.25')))->toBeMonto('150.75');
    });

    test('rechaza suma entre monedas distintas', function (): void {
        Monto::de('100', 'HNL')->sumar(Monto::de('100', 'USD'));
    })->throws(ValueObjectInvalidoException::class);

    test('aplica porcentaje — caso ISV 15 %', function (): void {
        expect(Monto::de('1000')->aplicarPorcentaje('15'))->toBeMonto('150.00');
    });

    test('resta produce error si el resultado sería negativo', function (): void {
        Monto::de('50')->restar(Monto::de('100'));
    })->throws(ValueObjectInvalidoException::class);

    test('multiplica sin perder precisión', function (): void {
        expect(Monto::de('33.33')->multiplicarPor('3'))->toBeMonto('99.99');
    });
});

describe('Monto — el caso que motivó reescribirlo', function (): void {
    test('el piso de margen de 120 % se toca exacto a los 60 años', function (): void {
        $costo = Decimal::de('10.00');
        $margen = Decimal::de('1.20');
        $descuento = Decimal::de('0.25');

        // lista = costo × (1 + margen) / (1 − descuento_máximo)   §4.5
        $objetivo = $costo->por($margen->sumar('1'));
        $lista = Monto::de($objetivo->entre(Decimal::de('1')->restar($descuento)));

        expect($lista)->toBeMonto('29.33');

        // Lo que paga el adulto mayor sobre la lista YA redondeada.
        $pagaMayor = Monto::de($lista->valor())->menosPorcentaje('25');

        expect($pagaMayor)->toBeMonto('22.00');
    })->note('29.33 × 0.75 = 21.9975. Con float y round() eso puede caer en 21.99: el margen queda en 119.9 % y el piso que fijó Mauricio se incumple en CADA venta a un adulto mayor, sin que ningún test falle.');

    test('el precio de lista correcto es 20 % más barato que el del dato viejo', function (): void {
        $objetivo = Decimal::de('22.00');

        $conElDescuentoReal = Monto::de($objetivo->entre('0.75'));   // 25 % del Art. 30
        $conElDatoDePrensa = Monto::de($objetivo->entre('0.60'));    // el 40 % que no aplica a salud

        expect($conElDescuentoReal)->toBeMonto('29.33')
            ->and($conElDatoDePrensa)->toBeMonto('36.67');
    })->note('Verificar la ley contra la fuente primaria le bajó 20 % el precio de lista a cada medicamento, sin tocarle un centavo al margen sobre el adulto mayor.');
});

describe('Monto — comparación e inmutabilidad', function (): void {
    test('mayorQue y menorQue comparan correctamente', function (): void {
        expect(Monto::de('200')->mayorQue(Monto::de('100')))->toBeTrue()
            ->and(Monto::de('100')->mayorQue(Monto::de('200')))->toBeFalse()
            ->and(Monto::de('100')->menorQue(Monto::de('200')))->toBeTrue();
    });

    test('igualA verifica monto Y moneda', function (): void {
        expect(Monto::de('100')->igualA(Monto::de('100')))->toBeTrue()
            ->and(Monto::de('100', 'HNL')->igualA(Monto::de('100', 'USD')))->toBeFalse();
    });

    test('igualA compara lo que se cobra, no la doceava cifra decimal', function (): void {
        $unTercio = Monto::de(Decimal::de('10')->entre('3'));

        expect($unTercio->igualA(Monto::de('3.33')))->toBeTrue()
            ->and($unTercio->exacto())->toBe('3.333333333333');
    })->note('Dos montos que difieren en la doceava cifra son el mismo dinero. El valor exacto se conserva para seguir calculando.');

    test('sumar retorna nueva instancia, no muta', function (): void {
        $a = Monto::de('100');
        $b = Monto::de('50');
        $suma = $a->sumar($b);

        expect($a->valor())->toBe('100.00')
            ->and($b->valor())->toBe('50.00')
            ->and($suma)->not->toBe($a);
    });
});

describe('Monto — formato', function (): void {
    test('formateado usa el símbolo provisto', function (): void {
        expect(Monto::de('1234.56')->formateado('L.'))->toBe('L. 1,234.56')
            ->and(Monto::de('1234.56')->formateado('$'))->toBe('$ 1,234.56');
    });

    test('formateado sin símbolo usa el default', function (): void {
        expect(Monto::de('99.99')->formateado())->toBe('L. 99.99');
    });

    test('toString delega en formateado', function (): void {
        expect((string) Monto::de('99.99'))->toBe('L. 99.99');
    });

    test('centavos devuelve enteros', function (): void {
        expect(Monto::de('123.45')->centavos())->toBe(12345)
            ->and(Monto::de('0.01')->centavos())->toBe(1);
    });
});
