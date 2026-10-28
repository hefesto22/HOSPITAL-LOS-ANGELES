<?php

declare(strict_types=1);

use App\Domain\Enums\TipoEncuentro;
use App\Support\TiempoDelEncuentro;
use Illuminate\Support\Carbon;

/*
 * CUÁNTO LLEVA EL PACIENTE, EN LA TARJETA (Mauricio, 28-oct-2026).
 *
 * «Hace 1 mes» decían todas: `diffForHumans()` redondea, y lo mismo era
 * un internado de 31 días que uno de 58. En hospitalización va el día de
 * estancia —el de ingreso es el 1, y cambia a la hora de corte del
 * censo—; en lo demás, minutos, horas o días sin redondear a meses.
 *
 * Fechas armadas desde `now()` (§9.C2): lo que se prueba es la
 * diferencia, no un día del calendario.
 */

function momentoDeLaEstancia(string $hora, int $diasAtras = 0): Carbon
{
    [$h, $m] = array_map(intval(...), explode(':', $hora));

    return now()->startOfDay()->subDays($diasAtras)->setTime($h, $m);
}

it('🔴 en hospitalización el día de ingreso es el día 1', function (): void {
    $ingreso = momentoDeLaEstancia('08:00');

    expect(TiempoDelEncuentro::paraLaTarjeta(TipoEncuentro::Hospitalizacion, $ingreso, momentoDeLaEstancia('15:00')))
        ->toBe('Día 1');
});

it('🔴 el día cambia a medianoche, no a las 24 horas', function (): void {
    $ingreso = momentoDeLaEstancia('23:50', diasAtras: 1);

    expect(TiempoDelEncuentro::paraLaTarjeta(TipoEncuentro::Hospitalizacion, $ingreso, momentoDeLaEstancia('00:10')))
        ->toBe('Día 2');
})->note('Quien entró a las 23:50 ya está en su segundo día a las 00:10: así lo cuenta el censo y así se cobra la estancia.');

it('el internado de un mes dice en qué día va', function (): void {
    $ingreso = momentoDeLaEstancia('10:00', diasAtras: 31);

    expect(TiempoDelEncuentro::paraLaTarjeta(TipoEncuentro::Hospitalizacion, $ingreso, momentoDeLaEstancia('09:00')))
        ->toBe('Día 32');
})->note('Antes decía «hace 1 mes», igual que uno de 58 días.');

it('con otra hora de corte del censo, la madrugada es todavía el día anterior', function (): void {
    config()->set('sihla.operacion.hora_corte_censo', '06:00');

    $ingreso = momentoDeLaEstancia('03:00');

    expect(TiempoDelEncuentro::paraLaTarjeta(TipoEncuentro::Hospitalizacion, $ingreso, momentoDeLaEstancia('05:00')))
        ->toBe('Día 1')
        ->and(TiempoDelEncuentro::paraLaTarjeta(TipoEncuentro::Hospitalizacion, $ingreso, momentoDeLaEstancia('07:00')))
        ->toBe('Día 2');
})->note('La hora de corte es configuración (§7.5-5): el día de la estancia cambia cuando cambia el del censo.');

it('un reloj corrido hacia atrás no da «Día 0»', function (): void {
    $ingreso = momentoDeLaEstancia('10:00');

    expect(TiempoDelEncuentro::diaDeEstancia($ingreso, $ingreso->copy()->subDay()))->toBe(1);
});

it('en emergencia dice minutos, horas o días', function (int $minutos, string $esperado): void {
    $ahora = momentoDeLaEstancia('12:00');

    expect(TiempoDelEncuentro::paraLaTarjeta(TipoEncuentro::Emergencia, $ahora->copy()->subMinutes($minutos), $ahora))
        ->toBe($esperado);
})->with([
    'recién'  => [0, 'Recién'],
    'minutos' => [40, 'hace 40 min'],
    'horas'   => [200, 'hace 3 h'],
    'un día'  => [30 * 60, 'hace 1 día'],
    'varios'  => [3 * 24 * 60 + 5, 'hace 3 días'],
])->note('Una emergencia que dice «hace 1 día» ya es una cuenta que alguien olvidó cerrar.');

it('la consulta externa no se redondea a meses', function (): void {
    $ahora = momentoDeLaEstancia('12:00');

    expect(TiempoDelEncuentro::paraLaTarjeta(TipoEncuentro::Ambulatorio, $ahora->copy()->subDays(45), $ahora))
        ->toBe('hace 45 días');
});
