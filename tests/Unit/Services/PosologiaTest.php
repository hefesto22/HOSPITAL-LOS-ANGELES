<?php

declare(strict_types=1);

use App\Domain\Exceptions\PosologiaException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\Posologia;

/**
 * LA RECETA, CONVERTIDA EN CUÁNTO HAY QUE ENTREGAR.
 *
 * Hoy esa multiplicación la hace alguien de cabeza en el mostrador, a las
 * tres de la mañana, con un paciente enfrente. Se hace mal alguna vez, y
 * cuando se hace mal no se nota: el número que queda escrito es plausible.
 */
function receta(string $dosis, int $cada, int $dias): Posologia
{
    return new Posologia(Decimal::de($dosis), $cada, $dias);
}

it('🔴 quince mililitros cada seis horas por dos dias son ciento veinte', function (): void {
    expect(receta('15', 6, 2)->total()->redondeado(0))->toBe('120')
        ->and(receta('15', 6, 2)->tomas())->toBe(8);
})->note('🔴 El caso de la reunión, al pie de la letra: cuatro tomas al día por dos días son ocho, y ocho por quince son 120 ml. De ahí sale UN frasco de 120 o DOS de 60.');

it('cada ocho horas por tres dias son nueve tomas', function (): void {
    expect(receta('10', 8, 3)->tomas())->toBe(9)
        ->and(receta('10', 8, 3)->total()->redondeado(0))->toBe('90');
});

it('cada cuarenta y ocho horas cuenta bien los dias alternos', function (): void {
    expect(receta('5', 48, 6)->tomas())->toBe(3);
})->note('Seis días con una toma cada dos días son tres tomas, no seis. Es el error que más se comete a mano.');

it('🔴 las tomas se redondean hacia arriba', function (): void {
    expect(receta('10', 5, 2)->tomas())->toBe(10);
})->note('🔴 48 ÷ 5 = 9,6 y no existe media toma. Se redondea hacia arriba y no hacia el más cercano porque los dos errores no cuestan lo mismo: que sobre una dosis es un gasto, que falte es un paciente sin su medicamento a la hora que le tocaba.');

it('la dosis unica es un dia con una toma', function (): void {
    $unaVez = Posologia::unaSolaVez(Decimal::de('500'));

    expect($unaVez->tomas())->toBe(1)
        ->and($unaVez->total()->redondeado(0))->toBe('500')
        ->and($unaVez->comoSeLee('MG'))->toBe('500 MG dosis única');
})->note('Se modela como un día cada 24 horas para que el resto del sistema no tenga que preguntarse si hay posología o no.');

it('se lee como la dice el medico', function (): void {
    expect(receta('15', 6, 2)->comoSeLee('ML'))->toBe('15 ML c/6h × 2 días');
})->note('Va en la línea de la cuenta al lado de los frascos: sin la receta, «2 frascos» no explica de dónde salió el número — y esa es justo la pregunta que hace el paciente.');

it('una frecuencia en cero no revienta: avisa', function (): void {
    receta('15', 0, 2);
})->throws(PosologiaException::class)
    ->note('«Cada 0 horas» no es un tipeo raro: es lo que queda cuando alguien borra el campo y envía. Sin la guarda, la división tira un error de PHP en vez de decir qué falta.');

it('cero dias tampoco', function (): void {
    receta('15', 6, 0);
})->throws(PosologiaException::class);

it('dosis en cero tampoco', function (): void {
    receta('0', 6, 2);
})->throws(PosologiaException::class);
