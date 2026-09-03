<?php

declare(strict_types=1);

use App\Models\TurnoDeCaja;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * La deuda de zona horaria del bloque 5d-1, convertida en prueba.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ PASABA
 * ─────────────────────────────────────────────────────────────────────
 *
 * `APP_TIMEZONE=America/Tegucigalpa` hacía que `now()` devolviera hora
 * local, y `config/database.php` fijaba la sesión de PostgreSQL en UTC.
 * Laravel serializa el Carbon con `getDateFormat()` = `Y-m-d H:i:s`: un
 * literal SIN offset. PostgreSQL recibía las 18:30 de Tegucigalpa y las
 * entendía como 18:30 UTC — seis horas antes del instante real.
 *
 * Toda columna `timestamptz` quedaba corrida, y no se veía, porque al
 * leer tampoco se convertía: se escribía 18:30 y se leía 18:30.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ SE PRUEBA CON UN TURNO DE CAJA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Porque el corte de caja es el sitio donde el error costaba plata. Un
 * turno abierto a las 18:30 del 1 de septiembre, guardado seis horas
 * adelantado, cae en el día 2 para cualquier reporte que agrupe por la
 * columna cruda. El arqueo de un día incluye movimientos de otro, y la
 * diferencia aparece sin explicación.
 *
 * Las 18:30 no son casuales: la ventana rota es la de 18:00 a
 * medianoche, que es justo el turno de la tarde.
 *
 * ⚠️ Estos tests comparan INSTANTES (epoch), no cadenas. Una comparación
 * de cadenas se puede satisfacer con dos configuraciones equivocadas que
 * se cancelan entre sí, que es exactamente cómo el sistema pasó semanas
 * en verde con las fechas corridas.
 */
const HORA_LOCAL = '2026-09-01 18:30:00';

const DIA_DE_OPERACION = '2026-09-01';

/*
 * El turno de la tarde del 1 de septiembre, abierto a las 18:30 de
 * Tegucigalpa. Todo este archivo gira alrededor de este instante.
 */
function elTurnoDeLaTarde(): TurnoDeCaja
{
    return TurnoDeCaja::factory()->create([
        'abierto_en'      => CarbonImmutable::parse(HORA_LOCAL, 'America/Tegucigalpa'),
        'fecha_operacion' => DIA_DE_OPERACION,
    ]);
}

it('guarda el instante en que se abrió el turno, no la hora de pared', function (): void {
    $instante = CarbonImmutable::parse(HORA_LOCAL, 'America/Tegucigalpa');

    $turno = elTurnoDeLaTarde();

    /*
     * EXTRACT(EPOCH ...) no depende de la zona de la sesión: devuelve el
     * instante absoluto. Es la única lectura que no se puede engañar
     * cambiando una configuración.
     */
    /** @var object{epoca: string} $fila */
    $fila = DB::selectOne(
        'SELECT EXTRACT(EPOCH FROM abierto_en)::bigint AS epoca FROM turnos_de_caja WHERE id = ?',
        [$turno->id],
    );

    expect((int) $fila->epoca)->toBe(
        $instante->getTimestamp(),
        'El turno quedó guardado en otro instante: la sesión de PostgreSQL está interpretando los literales de Laravel en la zona equivocada.',
    );
});

it('leído en UTC, el turno de las 18:30 es la medianoche y media del día siguiente', function (): void {
    $turno = elTurnoDeLaTarde();

    /** @var object{utc: string} $fila */
    $fila = DB::selectOne(
        "SELECT to_char(abierto_en AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') AS utc
         FROM turnos_de_caja WHERE id = ?",
        [$turno->id],
    );

    expect($fila->utc)->toBe('2026-09-02 00:30');
})->note('Mismo instante, otra etiqueta. Antes del arreglo esta lectura devolvía 2026-09-01 18:30, que es OTRO momento.');

it('el viaje redondo por Eloquent devuelve la misma hora de pared', function (): void {
    $turno = elTurnoDeLaTarde();

    $releido = TurnoDeCaja::query()->findOrFail($turno->id);

    expect($releido->abierto_en->setTimezone('America/Tegucigalpa')->format('Y-m-d H:i'))
        ->toBe('2026-09-01 18:30')
        ->and($releido->abierto_en->getTimestamp())
        ->toBe(CarbonImmutable::parse(HORA_LOCAL, 'America/Tegucigalpa')->getTimestamp());
})->note('La cajera tiene que leer en pantalla la hora que marcaba el reloj de la pared cuando abrió.');

it('el corte de caja agrupado por día cae en el día que fue', function (): void {
    elTurnoDeLaTarde();

    /*
     * Así agrupa un reporte por día: convierte el instante a la zona del
     * hospital y le pide la fecha. Con el desfase, este turno aparecía
     * en el día 2 y el arqueo del 1 salía incompleto.
     */
    /** @var object{dia: string} $fila */
    $fila = DB::selectOne(
        "SELECT to_char((abierto_en AT TIME ZONE 'America/Tegucigalpa')::date, 'YYYY-MM-DD') AS dia
         FROM turnos_de_caja
         WHERE fecha_operacion = ?",
        [DIA_DE_OPERACION],
    );

    expect($fila->dia)->toBe(DIA_DE_OPERACION);
})->note('§7.5-1: el censo de medianoche y el corte de caja son lo que se corría seis horas.');

it('el reloj de PHP y el de PostgreSQL nombran el mismo instante', function (): void {
    /** @var object{epoca: string} $fila */
    $fila = DB::selectOne('SELECT EXTRACT(EPOCH FROM now())::bigint AS epoca');

    /*
     * Tolerancia amplia a propósito: `now()` de PostgreSQL es el inicio
     * de la transacción, no el momento de la consulta, y bajo
     * RefreshDatabase esa transacción abre en el setUp. Lo que este test
     * detecta no son segundos: son las SEIS HORAS de desfase, que quedan
     * 360 veces fuera de este margen.
     *
     * (El §7.5-1 prohíbe usar `now()` de PostgreSQL en el dominio. Este
     * test no lo usa: lo audita.)
     */
    expect(abs((int) $fila->epoca - now()->getTimestamp()))->toBeLessThan(60);
});
