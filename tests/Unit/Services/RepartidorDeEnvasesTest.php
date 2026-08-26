<?php

declare(strict_types=1);

use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\EnvaseDisponible;
use App\Domain\ValueObjects\TomaDeEnvase;
use App\Services\RepartidorDeEnvases;

/**
 * DE QUÉ FRASCOS SALE LO QUE SE DESPACHA.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA REGLA APROBADA (Mauricio, 21-ago-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * **Se consume en orden de vencimiento, y si hay que destapar uno, se
 * destapa el que vence antes.** Solo entre frascos que vencen el mismo
 * día decide la aritmética: la combinación que deje menos destapado, y a
 * igualdad la que toque menos envases.
 *
 * 🔴 El precio NO entra en la decisión. Los envases tienen precios por
 * mililitro distintos —L 45.83 el de 120, L 61.11 el de 60, L 91.67 el de
 * 80— así que elegir frascos mueve lo que paga el paciente. Un repartidor
 * que optimizara la cuenta sería una decisión de precios tomada en el
 * lugar donde nadie la va a buscar.
 */
const EL_DE_60 = 1;

const EL_DE_80 = 2;

const EL_DE_120 = 3;

/**
 * Diez frascos de cada tamaño, todos con la misma fecha salvo lo que se
 * pida distinto.
 *
 * @param array<int, string> $vence
 *
 * @return list<EnvaseDisponible>
 */
function unEstante(array $vence = []): array
{
    return [
        new EnvaseDisponible(EL_DE_60, Decimal::de('600'), Decimal::de('60'), $vence[EL_DE_60] ?? '2026-12-30'),
        new EnvaseDisponible(EL_DE_80, Decimal::de('800'), Decimal::de('80'), $vence[EL_DE_80] ?? '2026-12-30'),
        new EnvaseDisponible(EL_DE_120, Decimal::de('1200'), Decimal::de('120'), $vence[EL_DE_120] ?? '2026-12-30'),
    ];
}

/**
 * @param list<EnvaseDisponible>|null $estante
 *
 * @return list<TomaDeEnvase>
 */
function despachar(string $mililitros, ?array $estante = null): array
{
    return (new RepartidorDeEnvases)->repartir(
        Decimal::de($mililitros),
        $estante ?? unEstante(),
    );
}

/**
 * «60:60 · 80:41*» — de qué frasco, cuánto, y si lo destapó.
 *
 * Se compara una cadena y no una estructura a propósito: el despacho
 * completo se lee de un vistazo y el diff de un test que falla dice qué
 * cambió sin tener que abrir el objeto.
 *
 * @param list<TomaDeEnvase> $tomas
 */
function comoSeSirvio(array $tomas): string
{
    $tamano = [EL_DE_60 => '60', EL_DE_80 => '80', EL_DE_120 => '120'];

    return implode(' · ', array_map(
        fn (TomaDeEnvase $toma): string => $tamano[$toma->clave]
            .':'.rtrim(rtrim($toma->cantidad->redondeado(2), '0'), '.')
            .($toma->destapa ? '*' : ''),
        $tomas,
    ));
}

/*
|--------------------------------------------------------------------------
| Cerrar exacto, que es dejar cero destapado
|--------------------------------------------------------------------------
*/

it('sirve un frasco entero cuando el pedido calza justo', function (): void {
    expect(comoSeSirvio(despachar('60')))->toBe('60:60');
})->note('Cero destapado es el mínimo posible, así que la combinación exacta gana sola: no hay que programarla como preferencia.');

it('🔴 cierra en dos frascos enteros antes que destapar uno', function (): void {
    expect(comoSeSirvio(despachar('140')))->toBe('80:80 · 60:60');
})->note('🔴 140 = 80 + 60 y no queda nada abierto. La alternativa —un frasco de 120 y 20 de otro— dejaría 100 ml destapados que empiezan a correr contra su vida útil esa misma tarde.');

it('entre dos formas exactas usa la que toca menos frascos', function (): void {
    expect(comoSeSirvio(despachar('120')))->toBe('120:120');
})->note('120 se puede armar con dos frascos de 60 o con uno de 120. Los dos dejan cero abierto, así que desempata la manipulación: un envase tocado en vez de dos.');

/*
|--------------------------------------------------------------------------
| Cuando no calza, se destapa lo que deje menos en riesgo
|--------------------------------------------------------------------------
*/

it('🔴 destapa el frasco que deje menos mililitros sueltos', function (): void {
    expect(comoSeSirvio(despachar('65')))->toBe('80:65*');
})->note('🔴 Un solo frasco de 80 deja 15 ml abiertos. Servir 60 entero y abrir otro de 60 deja 55, y abrir uno de 120 deja 115. Ninguna regla de «siempre el más grande» o «siempre el más chico» llega acá: hay que mirar el resultado.');

it('sirve 85 destapando el de 120', function (): void {
    expect(comoSeSirvio(despachar('85')))->toBe('120:85*');
})->note('35 ml abiertos contra los 55 que dejaría un frasco de 80 entero más cinco de otro.');

it('combina enteros y destapa uno solo cuando el pedido pasa del frasco más grande', function (): void {
    expect(comoSeSirvio(despachar('121')))->toBe('80:80 · 60:41*');
})->note('19 ml abiertos. Un frasco de 120 entero más uno de otro dejaría 59: lo intuitivo es tres veces peor.');

/*
|--------------------------------------------------------------------------
| 🔴 El vencimiento manda, incluso sobre el desperdicio
|--------------------------------------------------------------------------
*/

it('🔴 destapa el que vence antes aunque sobre más', function (): void {
    expect(comoSeSirvio(despachar('65', unEstante([EL_DE_120 => '2026-08-25']))))
        ->toBe('120:65*');
})->note('🔴 Deja 55 abiertos donde el de 80 dejaría 15 — y aun así gana. Destapar un frasco que ya estaba por vencerse NO agrega riesgo: ese frasco ya estaba perdido. Destapar uno fresco pone en peligro mililitros que hasta ese momento estaban seguros.');

it('no toca otro vencimiento mientras el primero alcance', function (): void {
    expect(comoSeSirvio(despachar('65', unEstante([EL_DE_60 => '2026-08-25']))))
        ->toBe('60:65*');
})->note('Los frascos de 60 vencen en agosto y alcanzan de sobra, así que el pedido entero sale de ahí: dos frascos, 55 ml destapados. Los de 80 dejarían menos suelto, pero vencen en diciembre y todavía no urgen.');

/*
|--------------------------------------------------------------------------
| El frasco ya abierto se termina antes de destapar otro
|--------------------------------------------------------------------------
*/

it('🔴 sirve del frasco abierto sin destapar ninguno', function (): void {
    $estante = [
        new EnvaseDisponible(EL_DE_60, Decimal::de('600'), Decimal::de('60'), '2026-12-30'),
        new EnvaseDisponible(EL_DE_120, Decimal::de('1135'), Decimal::de('120'), '2026-12-30'),
    ];

    expect(comoSeSirvio(despachar('40', $estante)))->toBe('120:40');
})->note('🔴 1135 ml en frascos de 120 son nueve cerrados y uno abierto con 55. Servir de ese agrega cero volumen nuevo en riesgo, así que gana sin estar programado como regla — y de ahí sale la invariante de la que depende todo: nunca puede haber dos frascos abiertos del mismo lote.');

it('agota el abierto antes de destapar el siguiente', function (): void {
    $estante = [
        new EnvaseDisponible(EL_DE_60, Decimal::de('600'), Decimal::de('60'), '2026-12-30'),
        new EnvaseDisponible(EL_DE_120, Decimal::de('1135'), Decimal::de('120'), '2026-12-30'),
    ];

    expect(comoSeSirvio(despachar('65', $estante)))->toBe('120:55 · 60:10*');
})->note('Los 55 del abierto salen primero y los 10 que faltan destapan un frasco de 60 — el que deja menos suelto de lo que queda cerrado.');

/*
|--------------------------------------------------------------------------
| Lo que no tiene envase, y lo que no alcanza
|--------------------------------------------------------------------------
*/

it('lo que llegó a granel se sirve sin destapar nada', function (): void {
    $estante = [new EnvaseDisponible(EL_DE_60, Decimal::de('500'))];

    expect(comoSeSirvio(despachar('120', $estante)))->toBe('60:120');
})->note('Sin envase declarado no hay frasco que romper: todo el saldo cuenta como suelto. Es el caso del jarabe que entró a granel.');

it('consume el lote que vence primero antes de tocar el siguiente', function (): void {
    $estante = [
        new EnvaseDisponible(EL_DE_60, Decimal::de('120'), Decimal::de('60'), '2026-08-25'),
        new EnvaseDisponible(EL_DE_120, Decimal::de('1200'), Decimal::de('120'), '2026-12-30'),
    ];

    expect(comoSeSirvio(despachar('180', $estante)))->toBe('60:120 · 120:60*');
})->note('Los dos frascos que vencen en agosto salen enteros aunque el pedido no calce con ellos: lo que urge es que no se venzan, no que el reparto quede prolijo.');

it('devuelve solo lo que hay cuando no alcanza', function (): void {
    $estante = [new EnvaseDisponible(EL_DE_60, Decimal::de('120'), Decimal::de('60'), '2026-12-30')];

    expect(comoSeSirvio(despachar('300', $estante)))->toBe('60:120');
})->note('El repartidor no inventa existencia ni revienta: reparte lo que hay. Quien despacha es el que decide qué hacer con el faltante, y ya tiene su propio error para eso.');

/*
|--------------------------------------------------------------------------
| 🔴 Modo envase entero: el frasco es del paciente porque lo pagó
|--------------------------------------------------------------------------
*/

/**
 * @param list<EnvaseDisponible>|null $estante
 *
 * @return list<TomaDeEnvase>
 */
function entregar(string $mililitros, ?array $estante = null): array
{
    return (new RepartidorDeEnvases)->repartir(
        Decimal::de($mililitros),
        $estante ?? unEstante(),
        envaseEntero: true,
    );
}

it('🔴 ciento veinte mililitros salen como un frasco de ciento veinte', function (): void {
    expect(comoSeSirvio(entregar('120')))->toBe('120:120');
})->note('🔴 El caso de la reunión: «15 ml c/6h × 2 días» son 120 ml, y esos 120 salen como UN frasco. La alternativa —dos de 60— también cierra exacto, pero desempata la manipulación: un envase en vez de dos.');

it('🔴 cien mililitros recetados salen como un frasco de ciento veinte', function (): void {
    expect(comoSeSirvio(entregar('100')))->toBe('120:120');
})->note('🔴 Se lleva 20 ml de más y los paga, y está bien: el frasco es suyo y él decide qué hace con lo que sobre. Lo que NO puede pasar es que esos 20 se le cobren después a otro paciente — la misma gota cobrada dos veces. Por eso la marca es del producto.');

it('🔴 nunca deja un frasco destapado', function (): void {
    $tomas = entregar('65');

    expect(array_filter($tomas, fn (TomaDeEnvase $t): bool => $t->destapa))->toBeEmpty();
})->note('🔴 Es la diferencia con el modo normal, donde 65 ml destapan un frasco de 80 y dejan 15 para el siguiente. Acá no hay sobrante que rastrear, y la invariante del «único frasco abierto» no tiene caso.');

it('no sirve de un frasco que ya estaba abierto', function (): void {
    $estante = [
        new EnvaseDisponible(EL_DE_60, Decimal::de('600'), Decimal::de('60'), '2026-12-30'),
        new EnvaseDisponible(EL_DE_120, Decimal::de('1135'), Decimal::de('120'), '2026-12-30'),
    ];

    expect(comoSeSirvio(entregar('40', $estante)))->toBe('60:60');
})->note('Los 55 ml del frasco destapado son de otro paciente que ya los pagó. En modo normal se servirían primero; acá no se tocan, y el pedido sale del frasco cerrado más chico que lo cubra.');

it('lo que vino a granel no se puede entregar por envase', function (): void {
    $estante = [new EnvaseDisponible(EL_DE_60, Decimal::de('500'))];

    expect(comoSeSirvio(entregar('120', $estante)))->toBe('');
})->note('Sin envase declarado no hay frasco que entregar, y este producto se cobra por frasco. Devuelve vacío en vez de inventar: quien despacha decide qué hacer, y ya tiene su propio error para eso.');

it('el vencimiento sigue mandando', function (): void {
    expect(comoSeSirvio(entregar('60', unEstante([EL_DE_120 => '2026-08-25']))))
        ->toBe('120:120');
})->note('El de 120 vence primero, así que sale ese aunque un frasco de 60 cubriera el pedido exacto. Que no se venza pesa más que que calce.');
