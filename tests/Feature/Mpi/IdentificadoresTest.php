<?php

declare(strict_types=1);

use App\Domain\Enums\TipoIdentificador;
use App\Models\Persona;
use App\Models\PersonaIdentificador;
use Illuminate\Database\QueryException;

/**
 * Se resuelve con una función y no con `$this->…` en un beforeEach: PHPStan
 * no analiza propiedades dinámicas sobre el `$this` de un closure de Pest,
 * y no vale la pena una exclusión para algo que se evita escribiéndolo
 * mejor. Mismo criterio que en AsignadorDeCorrelativoTest.
 *
 * @param array<string, mixed> $atributos
 */
function identificadorDe(Persona $persona, TipoIdentificador $tipo, string $valor, array $atributos = []): PersonaIdentificador
{
    /** @var PersonaIdentificador $identificador */
    $identificador = PersonaIdentificador::query()->create(array_merge([
        'persona_id' => $persona->getKey(),
        'tipo'       => $tipo->value,
        'valor'      => $valor,
    ], $atributos));

    return $identificador;
}

/*
|--------------------------------------------------------------------------
| Normalización
|--------------------------------------------------------------------------
*/

it('guarda el DNI como solo digitos sin importar como lo digiten', function (): void {
    $persona = Persona::factory()->create();

    $conGuiones = identificadorDe($persona, TipoIdentificador::Dni, '0801-1990-12345');

    expect($conGuiones->valor)->toBe('0801199012345')
        ->and($conGuiones->valor_original)->toBe('0801-1990-12345')
        ->and($conGuiones->formateado())->toBe('0801-1990-12345');
})->note('El turno A escribe con guiones y el turno B sin ellos. Guardar las dos formas produce dos pacientes.');

it('encuentra el documento aunque se busque con otro formato', function (): void {
    $persona = Persona::factory()->create();
    identificadorDe($persona, TipoIdentificador::Dni, '0801199012345');

    $encontrado = PersonaIdentificador::query()
        ->deNumero(TipoIdentificador::Dni, '0801-1990-12345')
        ->first();

    expect($encontrado?->persona_id)->toBe($persona->getKey());
})->note('Si la búsqueda normaliza distinto que la escritura, el sistema no encuentra lo que él mismo guardó y admisión crea el duplicado.');

it('valida la longitud del documento sin inventar que existe', function (): void {
    $persona = Persona::factory()->create();

    expect(identificadorDe($persona, TipoIdentificador::Dni, '0801199012345')->longitudEsValida())->toBeTrue()
        ->and(identificadorDe($persona, TipoIdentificador::Rtn, '08011990123')->longitudEsValida())->toBeFalse();
})->note('Atrapa el dedazo. Que el número exista solo lo sabe el RNP.');

/*
|--------------------------------------------------------------------------
| Unicidad — el camino normal está protegido
|--------------------------------------------------------------------------
*/

it('impide que dos personas tengan el mismo DNI', function (): void {
    identificadorDe(Persona::factory()->create(), TipoIdentificador::Dni, '0801199012345');
    identificadorDe(Persona::factory()->create(), TipoIdentificador::Dni, '0801-1990-12345');
})->throws(QueryException::class)
    ->note('El segundo se escribe con guiones a propósito: si la unicidad dependiera del formato digitado, no serviría de nada.');

it('impide dos pasaportes iguales del mismo pais', function (): void {
    identificadorDe(Persona::factory()->create(), TipoIdentificador::Pasaporte, 'E1234567', ['pais_emision' => 'HN']);
    identificadorDe(Persona::factory()->create(), TipoIdentificador::Pasaporte, 'E1234567', ['pais_emision' => 'HN']);
})->throws(QueryException::class);

/*
|--------------------------------------------------------------------------
| …y la salida de emergencia existe
|--------------------------------------------------------------------------
*/

it('permite el numero repetido cuando se declara el conflicto', function (): void {
    $primera = Persona::factory()->create();
    $segunda = Persona::factory()->create();

    identificadorDe($primera, TipoIdentificador::Dni, '0801199012345');
    identificadorDe($segunda, TipoIdentificador::Dni, '0801199012345', [
        'en_conflicto'   => true,
        'conflicto_nota' => 'El paciente presenta DNI ya registrado a otra persona. Verificar con RNP.',
    ]);

    expect(PersonaIdentificador::query()->where('valor', '0801199012345')->count())->toBe(2);
})->note('A las 3 de la mañana el sistema NO puede impedir registrar a un accidentado. La diferencia con inventar un número es que acá el conflicto queda registrado como conflicto, con nombre y hora.');

it('no acepta un conflicto sin explicacion', function (): void {
    identificadorDe(Persona::factory()->create(), TipoIdentificador::Dni, '0801199012345');

    identificadorDe(Persona::factory()->create(), TipoIdentificador::Dni, '0801199012345', [
        'en_conflicto'   => true,
        'conflicto_nota' => 'raro',
    ]);
})->throws(QueryException::class)
    ->note('Marcar algo como conflicto sin decir por qué deja a quien revisa la bandeja sin nada con qué trabajar.');

it('permite el mismo numero de pasaporte si lo emitieron paises distintos', function (): void {
    identificadorDe(Persona::factory()->create(), TipoIdentificador::Pasaporte, 'A1234567', ['pais_emision' => 'HN']);
    identificadorDe(Persona::factory()->create(), TipoIdentificador::Pasaporte, 'A1234567', ['pais_emision' => 'SV']);

    expect(PersonaIdentificador::query()->where('valor', 'A1234567')->count())->toBe(2);
})->note('Un número de pasaporte solo es único dentro del país que lo emitió; sin el país en la llave, el segundo turista choca contra el primero.');

it('permite registrar personas sin ningun documento', function (): void {
    Persona::factory()->nn()->create();
    Persona::factory()->nn()->create();
    Persona::factory()->create();

    expect(PersonaIdentificador::query()->count())->toBe(0)
        ->and(Persona::query()->count())->toBe(3);
})->note('El documento NO es la identidad. Por eso no existe una columna dni UNIQUE NOT NULL en personas.');

/*
|--------------------------------------------------------------------------
| Documento principal
|--------------------------------------------------------------------------
*/

it('acepta un solo documento principal por persona', function (): void {
    $persona = Persona::factory()->create();

    identificadorDe($persona, TipoIdentificador::Dni, '0801199012345', ['es_principal' => true]);
    identificadorDe($persona, TipoIdentificador::Rtn, '08011990123456', ['es_principal' => true]);
})->throws(QueryException::class)
    ->note('Con dos principales, la factura sale con uno u otro según cuál devuelva primero el motor — y un RTN equivocado es un problema con el SAR.');

it('permite que personas distintas tengan cada una su principal', function (): void {
    identificadorDe(Persona::factory()->create(), TipoIdentificador::Dni, '0801199012345', ['es_principal' => true]);
    identificadorDe(Persona::factory()->create(), TipoIdentificador::Dni, '0801199054321', ['es_principal' => true]);

    expect(PersonaIdentificador::query()->where('es_principal', true)->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Vigencia y verificación
|--------------------------------------------------------------------------
*/

it('distingue el documento que alguien tuvo en la mano', function (): void {
    $persona = Persona::factory()->create();

    $dictado = identificadorDe($persona, TipoIdentificador::Dni, '0801199012345');
    $visto = identificadorDe($persona, TipoIdentificador::Rtn, '08011990123456', [
        'verificado_en' => now(),
    ]);

    expect($dictado->estaVerificado())->toBeFalse()
        ->and($visto->estaVerificado())->toBeTrue();
})->note('Un DNI dictado por teléfono y uno que admisión fotocopió no valen lo mismo para facturar con RTN ni para reclamar a una aseguradora.');

it('impide un documento que vence antes de emitirse', function (): void {
    identificadorDe(Persona::factory()->create(), TipoIdentificador::Pasaporte, 'B7654321', [
        'pais_emision' => 'HN',
        'emitido_el'   => '2026-01-10',
        'vence_el'     => '2020-01-10',
    ]);
})->throws(QueryException::class);

it('sabe si un documento esta vencido en una fecha dada', function (): void {
    $identificador = identificadorDe(Persona::factory()->create(), TipoIdentificador::Pasaporte, 'C1122334', [
        'pais_emision' => 'HN',
        'emitido_el'   => '2020-01-10',
        'vence_el'     => '2025-01-10',
    ]);

    expect($identificador->estaVencidoEn(now()))->toBeTrue()
        ->and($identificador->estaVencidoEn(now()->setDate(2023, 5, 1)))->toBeFalse();
})->note('La vigencia se evalúa contra la fecha del servicio, no contra hoy: una factura de 2023 se emitió con un pasaporte que ese día estaba vigente.');

/*
|--------------------------------------------------------------------------
| Reglas del enum
|--------------------------------------------------------------------------
*/

it('separa los documentos que identifican de los que acreditan cobertura', function (): void {
    expect(TipoIdentificador::Dni->identificaLegalmente())->toBeTrue()
        ->and(TipoIdentificador::Pasaporte->identificaLegalmente())->toBeTrue()
        ->and(TipoIdentificador::CarnetIhss->identificaLegalmente())->toBeFalse()
        ->and(TipoIdentificador::PolizaSeguro->identificaLegalmente())->toBeFalse();
})->note('El carné del IHSS y la póliza son llaves de un tercero pagador. Confundirlos es cómo se emite una factura a nombre de la aseguradora cuando el obligado es el paciente.');

it('reconoce el carne de jubilado como acreditacion del beneficio legal', function (): void {
    expect(TipoIdentificador::CarnetJubilado->acreditaBeneficioLegal())->toBeTrue()
        ->and(TipoIdentificador::Dni->acreditaBeneficioLegal())->toBeFalse();
})->note('La ley protege a adultos mayores Y jubilados: un jubilado por invalidez puede tener 48 años y tener derecho igual. La edad no lo dice; el carné sí.');
