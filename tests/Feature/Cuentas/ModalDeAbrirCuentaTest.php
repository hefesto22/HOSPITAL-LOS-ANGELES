<?php

declare(strict_types=1);

use App\Domain\Enums\PrecisionFechaNacimiento;
use App\Domain\Enums\SexoBiologico;
use App\Domain\Enums\TipoConvenio;
use App\Domain\Enums\TipoIdentificador;
use App\Filament\Pages\CuentasAbiertas;
use App\Models\Convenio;
use App\Models\Expediente;
use App\Models\Persona;
use App\Models\PersonaIdentificador;
use App\Models\Sede;
use App\Support\FichaDelPaciente;
use Illuminate\Support\Facades\DB;

/*
 * EL MODAL «ABRIR CUENTA», REDISEÑADO (Mauricio, 26-oct-2026: «que acá
 * el diseño sea mejor, más bonito»).
 *
 *   · Cada resultado del buscador y la ficha del elegido dicen la fecha
 *     de nacimiento y el documento: dos homónimos ya no se ven iguales
 *     (§9.D2, §9.D3).
 *   · Con un solo expediente, ya viene marcado.
 *   · El pagador va agrupado por clase, y la póliza y la autorización
 *     aparecen solo cuando hay seguro.
 *
 * Los métodos de la página son PRIVADOS a propósito: uno público de un
 * componente Livewire se puede llamar desde el navegador, y la ficha
 * devolvería el DNI de cualquiera. Por eso se llaman con reflexión.
 *
 * ⚠️ Los ayudantes tienen nombres propios: con `--parallel`, dos
 * funciones globales con el mismo nombre chocan según cómo caiga el
 * reparto (§9.M5).
 */

function privadoDelModalDeAbrir(CuentasAbiertas $pagina, string $metodo, mixed ...$argumentos): mixed
{
    return (new ReflectionMethod($pagina, $metodo))->invoke($pagina, ...$argumentos);
}

/**
 * ANA MEJÍA PAZ, 45 años cumplidos hace diez días. Con DNI si se pide.
 *
 * @param array<string, mixed> $datos
 */
function personaParaLaFicha(array $datos = [], ?string $dni = null): Persona
{
    /** @var Persona $persona */
    $persona = Persona::factory()->create(array_merge([
        'primer_nombre'              => 'ANA',
        'segundo_nombre'             => null,
        'primer_apellido'            => 'MEJÍA',
        'segundo_apellido'           => 'PAZ',
        'sexo_biologico'             => SexoBiologico::Femenino,
        'fecha_nacimiento'           => now()->subYears(45)->subDays(10)->toDateString(),
        'precision_fecha_nacimiento' => PrecisionFechaNacimiento::Exacta,
    ], $datos));

    if ($dni !== null) {
        PersonaIdentificador::query()->create([
            'persona_id'   => $persona->id,
            'tipo'         => TipoIdentificador::Dni->value,
            'valor'        => $dni,
            'es_principal' => true,
        ]);
    }

    return $persona->load('identificadores');
}

/*
|--------------------------------------------------------------------------
| La ficha del paciente
|--------------------------------------------------------------------------
*/

it('🔴 la ficha dice fecha de nacimiento, edad, sexo y documento', function (): void {
    $persona = personaParaLaFicha(dni: '0801198012345');
    $nacio = $persona->fecha_nacimiento?->format('d/m/Y');

    expect(FichaDelPaciente::de($persona, now()))->toBe([
        'iniciales' => 'AM',
        'nombre'    => 'ANA MEJÍA PAZ',
        'nn'        => false,
        'datos'     => ['Nació '.$nacio, '45 años', 'Femenino', 'DNI 0801-1980-12345'],
    ]);
})->note('Son los dos identificadores (§9.D2): antes de abrirle la cuenta se ve a quién. Con solo el nombre, dos «JOSÉ ANTONIO MARTÍNEZ» eran el mismo renglón.');

it('con la fecha aproximada no se inventa el cumpleaños', function (): void {
    $persona = personaParaLaFicha(['precision_fecha_nacimiento' => PrecisionFechaNacimiento::Estimada]);

    expect(FichaDelPaciente::datos($persona, now()))
        ->toBe(['≈ 45 años (fecha aproximada)', 'Femenino', 'Sin documento']);
})->note('El NN entra con una edad calculada a ojo: escribirle «Nació 16/10/1981» sería darle por cierto un día que nadie sabe.');

it('al bebé no se le dice «0 años»', function (string $nacio, string $edad): void {
    $persona = personaParaLaFicha(['fecha_nacimiento' => $nacio]);

    expect(FichaDelPaciente::datos($persona, now())[1])->toBe($edad);
})->with([
    'de hoy'    => [fn (): string => now()->toDateString(), 'Recién nacido'],
    'de un día' => [fn (): string => now()->subDay()->toDateString(), '1 día'],
    'de días'   => [fn (): string => now()->subDays(3)->toDateString(), '3 días'],
    'de meses'  => [fn (): string => now()->subMonths(7)->subDays(3)->toDateString(), '7 meses'],
])->note('Como lo dice enfermería: días o meses. El recién nacido tiene expediente propio desde el minuto cero (§8.2-9).');

it('el documento que se muestra es el principal, aunque haya otro antes', function (): void {
    $persona = personaParaLaFicha();

    PersonaIdentificador::query()->create([
        'persona_id'   => $persona->id,
        'tipo'         => TipoIdentificador::Pasaporte->value,
        'valor'        => 'A1234567',
        'es_principal' => false,
    ]);

    PersonaIdentificador::query()->create([
        'persona_id'   => $persona->id,
        'tipo'         => TipoIdentificador::Dni->value,
        'valor'        => '0801198012345',
        'es_principal' => true,
    ]);

    $datos = FichaDelPaciente::datos($persona->load('identificadores'), now());

    expect($datos)->toContain('DNI 0801-1980-12345')
        ->and($datos)->not->toContain('Pasaporte A1234567');
})->note('El principal es con el que se factura.');

/*
|--------------------------------------------------------------------------
| El renglón del buscador
|--------------------------------------------------------------------------
*/

it('🔴 el renglón del buscador escapa el nombre', function (): void {
    $persona = personaParaLaFicha(['primer_nombre' => '<b>ANA</b>']);

    $html = FichaDelPaciente::opcionDelBuscador($persona, now());

    expect($html)->toContain('&lt;B&gt;ANA&lt;/B&gt;')
        ->and($html)->not->toContain('<B>')
        ->and($html)->not->toContain('Ya tiene');
})->note('La lista del select muestra HTML (`allowHtml`). Un nombre que trae etiquetas tiene que salir como texto, no como código.');

it('el que ya tiene cuenta lo dice en su renglón', function (): void {
    $html = FichaDelPaciente::opcionDelBuscador(personaParaLaFicha(), now(), 'CTA-HLA-2026-000010');

    expect($html)->toContain('Ya tiene CTA-HLA-2026-000010 abierta');
})->note('Se ve pero no se puede elegir: sacarlo del buscador haría que lo registren de nuevo.');

/*
|--------------------------------------------------------------------------
| El expediente
|--------------------------------------------------------------------------
*/

it('🔴 con un solo expediente ya viene marcado; con dos, se elige', function (): void {
    $una = personaParaLaFicha();
    $suyo = Expediente::factory()->create(['persona_id' => $una->id]);

    $otra = personaParaLaFicha(['primer_nombre' => 'LUISA']);
    Expediente::factory()->create(['persona_id' => $otra->id, 'sede_id' => Sede::factory()]);
    Expediente::factory()->create(['persona_id' => $otra->id, 'sede_id' => Sede::factory()]);

    $pagina = app(CuentasAbiertas::class);

    expect(privadoDelModalDeAbrir($pagina, 'expedienteUnicoDe', $una->id))->toBe($suyo->id)
        ->and(privadoDelModalDeAbrir($pagina, 'expedienteUnicoDe', $otra->id))->toBeNull()
        ->and(privadoDelModalDeAbrir($pagina, 'expedienteUnicoDe', null))->toBeNull();
})->note('Antes había que abrir un select para elegir la única opción. Con varios no se adivina.');

it('la ficha y los expedientes se leen una vez por pintada', function (): void {
    $persona = personaParaLaFicha(dni: '0801198012345');
    Expediente::factory()->create(['persona_id' => $persona->id]);

    $pagina = app(CuentasAbiertas::class);

    privadoDelModalDeAbrir($pagina, 'fichaDelPaciente', $persona->id);
    privadoDelModalDeAbrir($pagina, 'expedientesDe', $persona->id);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $ficha = privadoDelModalDeAbrir($pagina, 'fichaDelPaciente', (string) $persona->id);
    privadoDelModalDeAbrir($pagina, 'expedientesDe', $persona->id);
    privadoDelModalDeAbrir($pagina, 'expedienteUnicoDe', $persona->id);

    expect(DB::getQueryLog())->toBe([])
        ->and(data_get($ficha, 'nombre'))->toBe('ANA MEJÍA PAZ');
})->note('El modal se vuelve a pintar con cada campo que se toca, y la ficha la piden el rótulo del buscador, la visibilidad y la vista (§13.2).');

/*
|--------------------------------------------------------------------------
| El pagador
|--------------------------------------------------------------------------
*/

it('el pagador va agrupado por clase, con contado primero', function (): void {
    $contado = Convenio::factory()->contado()->create();
    $palic = Convenio::factory()->de(TipoConvenio::AseguradoraPrivada)->create(['nombre' => 'PALIC']);
    $ficohsa = Convenio::factory()->de(TipoConvenio::AseguradoraPrivada)->create(['nombre' => 'FICOHSA SEGUROS']);
    $externo = Convenio::factory()->reembolso()->create(['nombre' => 'BMI']);

    expect(privadoDelModalDeAbrir(app(CuentasAbiertas::class), 'pagadoresPorClase'))->toBe([
        'Contado'                    => [$contado->id => 'PACIENTE PARTICULAR'],
        'Seguro externo · reembolso' => [$externo->id => 'BMI'],
        'Aseguradora privada'        => [$ficohsa->id => 'FICOHSA SEGUROS', $palic->id => 'PALIC'],
    ]);
});

it('🔴 la póliza y la autorización aparecen solo cuando hay seguro', function (): void {
    $contado = Convenio::factory()->contado()->create();
    $seguro = Convenio::factory()->de(TipoConvenio::AseguradoraPrivada)->create();
    $externo = Convenio::factory()->reembolso()->create();

    $pagina = app(CuentasAbiertas::class);

    $pide = fn (Convenio $convenio): array => [
        'poliza'       => privadoDelModalDeAbrir($pagina, 'pideLaPoliza', $convenio->id),
        'autorizacion' => privadoDelModalDeAbrir($pagina, 'pideLaAutorizacion', (string) $convenio->id),
    ];

    expect($pide($contado))->toBe(['poliza' => false, 'autorizacion' => false])
        ->and($pide($seguro))->toBe(['poliza' => true, 'autorizacion' => true])
        ->and($pide($externo))->toBe(['poliza' => true, 'autorizacion' => false])
        ->and(privadoDelModalDeAbrir($pagina, 'pideLaPoliza', null))->toBeFalse();

    $js = privadoDelModalDeAbrir($pagina, 'siElPagadorPide', fn (int $id): bool => $id === $seguro->id);

    expect($js)->toBe("['{$seguro->id}'].includes(String(\$get('convenio_id') ?? ''))")
        ->and($js)->not->toContain('"');
})->note('Al contado no hay nada que escribir ahí. El seguro externo lleva póliza —sale en la factura para que el paciente reclame— pero no autorización: el hospital le cobra al paciente.');
