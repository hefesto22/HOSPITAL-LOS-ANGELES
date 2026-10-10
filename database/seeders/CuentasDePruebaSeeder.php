<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Enums\Genero;
use App\Domain\Enums\RangoEdad;
use App\Domain\Enums\SexoBiologico;
use App\Domain\Enums\TipoEncuentro;
use App\Domain\Enums\TipoIdentificador;
use App\Domain\Exceptions\CuentaException;
use App\Domain\Exceptions\EncuentroException;
use App\Domain\ValueObjects\DatosDePaciente;
use App\Domain\ValueObjects\DocumentoDeIdentidad;
use App\Models\Convenio;
use App\Models\Persona;
use App\Models\PersonaIdentificador;
use App\Models\Sede;
use App\Services\AbridorDeEncuentro;
use App\Services\RegistradorDePacientes;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Nueve cuentas abiertas para probar el motor de precios de punta a punta.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ ESTE SEEDER NO VA EN `DatabaseSeeder`
 * ─────────────────────────────────────────────────────────────────────
 *
 * Son pacientes inventados. Existen para ver en pantalla cómo cambia el
 * precio de un mismo ítem según QUIÉN paga y CUÁNTOS años tiene el
 * paciente, que son las dos variables del Art. 30 y del tarifario:
 *
 *     3 pagadores  (contado · PALIG · Hospital Militar)
 *   × 3 rangos     (edad normal · tercera edad · cuarta edad)
 *   = 9 cuentas de hospitalización
 *
 * Se corre a mano, DESPUÉS de los catálogos —de ahí salen los dos
 * convenios que no son CONTADO—:
 *
 *     php artisan db:seed --class=CatalogoPaligSeeder
 *     php artisan db:seed --class=CatalogoMilitarSeeder
 *     php artisan db:seed --class=CuentasDePruebaSeeder
 *
 * Es idempotente: el paciente se busca por DNI antes de crearlo, y si ya
 * tiene una cuenta viva se deja como está. Correrlo dos veces no abre
 * dos cuentas ni registra dos veces a nadie.
 *
 * ─────────────────────────────────────────────────────────────────────
 * PASA POR LAS MISMAS PUERTAS QUE LA PANTALLA
 * ─────────────────────────────────────────────────────────────────────
 *
 * `RegistradorDePacientes` para dar de alta —persona, documento, versión
 * 1 y expediente en una sola transacción— y `AbridorDeEncuentro` para
 * abrir encuentro y cuenta con sus correlativos bajo candado. Nada de
 * `Persona::create()` suelto: una cuenta sembrada a mano que se salte el
 * registrador no tiene versión 1 ni expediente, y la primera pantalla
 * que la toque revienta.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LAS FECHAS DE NACIMIENTO SON FIJAS, Y EL SEEDER LAS VIGILA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Fijas para que el DNI y el paciente sean siempre los mismos —así la
 * búsqueda por documento encuentra al de la vez anterior—. Pero la edad
 * corre: el de 65 va a cumplir 80 y pasar a la cuarta edad sin que nadie
 * toque este archivo. Por eso, antes de abrir nada, se comprueba que
 * cada paciente siga cayendo en el rango que se le asignó y, si alguno
 * ya se cambió de rango, el seeder se detiene y lo dice. Es preferible
 * a sembrar «tercera edad» con un paciente que el resolutor ya trata de
 * cuarta.
 */
class CuentasDePruebaSeeder extends Seeder
{
    /**
     * El municipio del DNI: 0401 es Santa Rosa de Copán. El correlativo
     * arranca en 90001 para que se distinga a simple vista de un DNI
     * real.
     */
    private const MUNICIPIO_DNI = '0401';

    private const CODIGO_SEDE = 'HLA';

    /**
     * Cada línea: pagador, rango esperado, fecha de nacimiento, nombres,
     * apellidos, sexo y número de póliza (null en contado, que no tiene).
     *
     * Edades calculadas al 09-sep-2026:
     * 35 · 68 · 83 · 42 · 72 · 87 · 28 · 65 · 91.
     *
     * @var list<array{0: string, 1: RangoEdad, 2: string, 3: string, 4: string, 5: string, 6: string, 7: SexoBiologico, 8: string|null}>
     */
    private const PACIENTES = [
        // ── Contado ───────────────────────────────────────────────────
        [Convenio::CODIGO_CONTADO, RangoEdad::Normal, '1991-03-14', 'JOSE', 'ANTONIO', 'MEJIA', 'LOPEZ', SexoBiologico::Masculino, null],
        [Convenio::CODIGO_CONTADO, RangoEdad::Tercera, '1958-07-02', 'MARIA', 'ELENA', 'PINEDA', 'CASTELLANOS', SexoBiologico::Femenino, null],
        [Convenio::CODIGO_CONTADO, RangoEdad::Cuarta, '1942-11-20', 'PEDRO', 'PABLO', 'INTERIANO', 'MADRID', SexoBiologico::Masculino, null],

        // ── PALIG ─────────────────────────────────────────────────────
        ['PALIG', RangoEdad::Normal, '1984-05-09', 'ANA', 'LUCIA', 'BUESO', 'HERNANDEZ', SexoBiologico::Femenino, 'PAL-2026-90004'],
        ['PALIG', RangoEdad::Tercera, '1954-01-25', 'CARLOS', 'HUMBERTO', 'TABORA', 'ARITA', SexoBiologico::Masculino, 'PAL-2026-90005'],
        ['PALIG', RangoEdad::Cuarta, '1939-08-30', 'ROSA', 'AMELIA', 'GUARDADO', 'PAZ', SexoBiologico::Femenino, 'PAL-2026-90006'],

        // ── Hospital Militar ──────────────────────────────────────────
        ['MILITAR', RangoEdad::Normal, '1997-10-15', 'LUIS', 'FERNANDO', 'ORELLANA', 'SABILLON', SexoBiologico::Masculino, 'HM-2026-90007'],
        ['MILITAR', RangoEdad::Tercera, '1961-04-03', 'GLORIA', 'MARINA', 'CASTRO', 'VILLANUEVA', SexoBiologico::Femenino, 'HM-2026-90008'],
        ['MILITAR', RangoEdad::Cuarta, '1935-06-18', 'RAMON', 'ENRIQUE', 'PERDOMO', 'ALVARADO', SexoBiologico::Masculino, 'HM-2026-90009'],
    ];

    public function run(): void
    {
        $sede = $this->sede();
        $registrador = app(RegistradorDePacientes::class);
        $abridor = app(AbridorDeEncuentro::class);
        $hoy = Carbon::today();

        $this->exigirQueLosRangosSiganVigentes($hoy);

        /** @var array<string, Convenio> $convenios */
        $convenios = [];

        foreach (self::PACIENTES as $i => [$codigoConvenio, $rango, $nacimiento, $primerNombre, $segundoNombre, $primerApellido, $segundoApellido, $sexo, $poliza]) {
            $convenios[$codigoConvenio] ??= $this->convenio($codigoConvenio);
            $convenio = $convenios[$codigoConvenio];

            $dni = $this->dni($nacimiento, $i + 1);
            $persona = $this->personaConDni($dni);

            if ($persona instanceof Persona) {
                $expediente = $registrador->abrirExpedienteEn($persona, $sede);
            } else {
                $expediente = $registrador->registrar(
                    new DatosDePaciente(
                        primerNombre: $primerNombre,
                        primerApellido: $primerApellido,
                        segundoNombre: $segundoNombre,
                        segundoApellido: $segundoApellido,
                        sexoBiologico: $sexo,
                        genero: $sexo === SexoBiologico::Femenino ? Genero::Femenino : Genero::Masculino,
                        fechaNacimiento: Carbon::parse($nacimiento),
                        documentos: [
                            new DocumentoDeIdentidad(TipoIdentificador::Dni, $dni, 'HN', esPrincipal: true),
                        ],
                        departamento: 'COPAN',
                        municipio: 'SANTA ROSA DE COPAN',
                    ),
                    $sede,
                );

                /** @var Persona $persona */
                $persona = Persona::query()->findOrFail($expediente->persona_id);
            }

            try {
                $cuenta = $abridor->abrir(
                    persona: $persona,
                    expediente: $expediente,
                    tipo: TipoEncuentro::Hospitalizacion,
                    convenio: $convenio,
                    sede: $sede,
                    motivo: 'CUENTA DE PRUEBA · '.mb_strtoupper($rango->etiqueta(), 'UTF-8').' · '.$convenio->nombre,
                    numeroPoliza: $poliza,
                );
            } catch (CuentaException|EncuentroException $e) {
                $this->command?->comment(
                    "  · {$persona->nombreCompleto()} ({$rango->etiqueta()}, {$convenio->nombre}): se deja como está — {$e->getMessage()}"
                );

                continue;
            }

            $this->command?->info(
                "  ✓ {$cuenta->numero} → {$persona->nombreCompleto()} · {$rango->etiqueta()} · {$convenio->nombre}"
            );
        }

        $this->command?->info('✓ Cuentas de prueba listas: 3 pagadores × 3 rangos de edad.');
    }

    /**
     * Se detiene ANTES de tocar la base si el paso del tiempo movió a
     * alguien de rango. Corregir la fecha de nacimiento en la lista es un
     * minuto; descubrir en pantalla que «tercera edad» ya cobra como
     * cuarta es una tarde.
     */
    private function exigirQueLosRangosSiganVigentes(Carbon $hoy): void
    {
        foreach (self::PACIENTES as [, $rango, $nacimiento, $primerNombre, , $primerApellido]) {
            $real = RangoEdad::paraPaciente(Carbon::parse($nacimiento), $hoy);

            if ($real !== $rango) {
                throw new RuntimeException(
                    "{$primerNombre} {$primerApellido} (nacido el {$nacimiento}) ya no es «{$rango->etiqueta()}» "
                    ."sino «{$real->etiqueta()}». Corregí su fecha de nacimiento en CuentasDePruebaSeeder::PACIENTES."
                );
            }
        }
    }

    /**
     * 0401-AAAA-9000N: municipio, año de nacimiento y un correlativo que
     * ningún DNI real usa. Trece dígitos, como exige `TipoIdentificador`.
     */
    private function dni(string $nacimiento, int $correlativo): string
    {
        return self::MUNICIPIO_DNI.substr($nacimiento, 0, 4).'9000'.$correlativo;
    }

    private function personaConDni(string $dni): ?Persona
    {
        $identificador = PersonaIdentificador::query()
            ->deNumero(TipoIdentificador::Dni, $dni)
            ->first();

        return $identificador instanceof PersonaIdentificador ? $identificador->persona : null;
    }

    private function sede(): Sede
    {
        $sede = Sede::query()->where('codigo', self::CODIGO_SEDE)->first()
            ?? Sede::query()->orderBy('id')->first();

        if (! $sede instanceof Sede) {
            throw new RuntimeException('No hay ninguna sede. Corré SedeSeeder antes que este seeder.');
        }

        return $sede;
    }

    private function convenio(string $codigo): Convenio
    {
        $convenio = Convenio::query()->where('codigo', $codigo)->first();

        if (! $convenio instanceof Convenio) {
            $seeder = match ($codigo) {
                'PALIG'   => 'CatalogoPaligSeeder',
                'MILITAR' => 'CatalogoMilitarSeeder',
                default   => 'ConveniosSeeder',
            };

            throw new RuntimeException(
                "Falta el convenio «{$codigo}». Corré {$seeder} antes que este seeder."
            );
        }

        return $convenio;
    }
}
