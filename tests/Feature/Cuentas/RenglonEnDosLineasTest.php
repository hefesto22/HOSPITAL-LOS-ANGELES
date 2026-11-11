<?php

declare(strict_types=1);

use App\Domain\Enums\RegimenIsv;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\RenglonDeCuenta;
use App\Models\Cargo;

/**
 * Cómo se lee un renglón en la lista de la cuenta (11-nov-2026): qué se
 * cobró arriba, de quién o de qué tipo abajo, el descuento con su
 * porcentaje, y el régimen de ISV solo cuando no es exento.
 *
 * El renglón se arma a mano con un cargo sin guardar: lo que se prueba es
 * la lectura, no el agrupado (ese está en `RenglonesDeLaCuentaTest`).
 *
 * @param array<string, mixed> $delCargo
 * @param numeric-string $bruto
 * @param numeric-string $descuento
 */
function renglonParaLeer(string $texto, array $delCargo = [], string $bruto = '100.00', string $descuento = '0'): RenglonDeCuenta
{
    $cargo = (new Cargo)->forceFill($delCargo + ['regimen_isv' => RegimenIsv::Exento, 'es_tardio' => false]);

    return new RenglonDeCuenta(
        texto: $texto,
        nota: '',
        cantidad: Decimal::de('1'),
        precioUnitario: $bruto,
        descuento: Decimal::de($descuento),
        total: Decimal::de($bruto)->restar($descuento),
        quien: null,
        entregas: [$cargo],
        bruto: Decimal::de($bruto),
    );
}

it('el honorario de un médico se lee en dos líneas: qué arriba, de quién abajo', function (): void {
    $consulta = renglonParaLeer('CONSULTA EXTERNA CIRUGIA GENERAL · DR. ROBERTO ABNER SANABRIA PEÑA', ['medico_id' => 7]);
    $honorario = renglonParaLeer('HONORARIO MÉDICO · ALLISON TABORA · GINECOLOGIA Y OBSTETRICIA', ['medico_id' => 8]);

    expect($consulta->titulo())->toBe('CONSULTA EXTERNA CIRUGIA GENERAL')
        ->and($consulta->deQuien())->toBe('DR. ROBERTO ABNER SANABRIA PEÑA')
        ->and($honorario->titulo())->toBe('HONORARIO MÉDICO')
        ->and($honorario->deQuien())->toBe('ALLISON TABORA · GINECOLOGIA Y OBSTETRICIA');
})->note('Mauricio, 11-nov-2026: «se puede mejorar el diseño de la lista». En una sola línea el nombre del médico partía la descripción en tres renglones.');

it('sin médico el texto queda entero aunque traiga un punto medio', function (): void {
    $medicamento = renglonParaLeer('ACETAMINOFEN 500 MG · LOTE A2231');

    expect($medicamento->titulo())->toBe('ACETAMINOFEN 500 MG · LOTE A2231')
        ->and($medicamento->deQuien())->toBeNull();
});

it('el descuento se lee también como parte de lo que valía', function (): void {
    expect(renglonParaLeer('CONSULTA', bruto: '1100.00', descuento: '330.00')->porcentajeDeDescuento())->toBe('30 %')
        ->and(renglonParaLeer('TURNO A', bruto: '570.00', descuento: '142.50')->porcentajeDeDescuento())->toBe('25 %')
        ->and(renglonParaLeer('HEMOGRAMA', bruto: '380.00')->porcentajeDeDescuento())->toBeNull();
});

it('el régimen de ISV se dice solo cuando no es exento, y el cargo tardío siempre', function (): void {
    $exento = renglonParaLeer('HEMOGRAMA');
    $gravado = renglonParaLeer('CAFETERIA', ['regimen_isv' => RegimenIsv::Gravado15]);
    $tardio = renglonParaLeer('TRANSFUSION', ['es_tardio' => true]);

    expect($exento->regimenQueSeDice())->toBeNull()
        ->and($gravado->regimenQueSeDice())->toBe(RegimenIsv::Gravado15->etiqueta())
        ->and($exento->esTardio())->toBeFalse()
        ->and($tardio->esTardio())->toBeTrue();
})->note('Casi todo lo de un hospital es exento (Art. 15, Ley del ISV): un «Exento» debajo de cada renglón no decía nada. Lo raro es lo que hay que ver. El pie sigue separando exento, gravado e ISV.');
