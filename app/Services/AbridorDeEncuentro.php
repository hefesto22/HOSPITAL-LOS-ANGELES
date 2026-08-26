<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\EstadoCuenta;
use App\Domain\Enums\EstadoEncuentro;
use App\Domain\Enums\TipoConvenio;
use App\Domain\Enums\TipoCorrelativo;
use App\Domain\Enums\TipoEncuentro;
use App\Domain\Exceptions\CuentaException;
use App\Domain\Exceptions\EncuentroException;
use App\Models\Convenio;
use App\Models\Cuenta;
use App\Models\Encuentro;
use App\Models\Expediente;
use App\Models\Persona;
use App\Models\Sede;
use App\Support\ContextoSede;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Abrir la cuenta: encuentro + cuenta, en una sola transacción.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ LAS DOS COSAS JUNTAS
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un encuentro sin cuenta es un paciente que se está atendiendo y no
 * tiene dónde acumular lo que consume. La mitad de un caso de uso
 * ejecutada es peor que ninguna (§9.A13), y acá la mitad significa que a
 * las tres de la mañana alguien va a intentar cargar una ampolla y el
 * sistema le va a decir que no hay cuenta.
 *
 * ─────────────────────────────────────────────────────────────────────
 * DOS CORRELATIVOS, LOS DOS BAJO CANDADO
 * ─────────────────────────────────────────────────────────────────────
 *
 * `AsignadorDeCorrelativo` toma advisory lock por sede y tipo, así que
 * dos admisiones simultáneas no producen el mismo número de encuentro ni
 * el mismo número de cuenta. Los dos son por SEDE: un contador global
 * sería cuello de botella y confusión operativa garantizada (§8.1).
 *
 * ─────────────────────────────────────────────────────────────────────
 * LO QUE SE NIEGA, Y LO QUE NO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Se niega abrir un SEGUNDO ingreso de hospitalización al mismo paciente
 * —el índice único de la base lo impide igual— porque produce dos
 * cuentas y dos censos del mismo señor.
 *
 * NO se niega nada por falta de papeles, ni por póliza vencida, ni por
 * autorización pendiente. §8.6.3 y §9.E7 son claros: la atención no se
 * bloquea por un asunto administrativo. Lo que el sistema hace con la
 * póliza dudosa es marcarla y cobrarle al paciente, no cerrar la puerta.
 */
final class AbridorDeEncuentro
{
    public function __construct(
        private readonly AsignadorDeCorrelativo $correlativos,
    ) {}

    /**
     * @throws EncuentroException
     * @throws CuentaException
     */
    public function abrir(
        Persona $persona,
        Expediente $expediente,
        TipoEncuentro $tipo,
        Convenio $convenio,
        ?Sede $sede = null,
        ?int $servicioId = null,
        ?int $medicoTratanteId = null,
        ?string $motivo = null,
        ?string $numeroPoliza = null,
        ?string $numeroAutorizacion = null,
        ?int $responsablePersonaId = null,
        ?CarbonInterface $abiertoEn = null,
    ): Cuenta {
        $sede = $sede ?? $this->sedeDelContexto();

        if ($expediente->persona_id !== $persona->id) {
            throw EncuentroException::expedienteDeOtraPersona();
        }

        $this->exigirConvenioVigente($convenio, $abiertoEn ?? now());
        $this->exigirQueNoEsteYaInternado($persona, $tipo);
        $this->exigirQueNoTengaCuentaAbierta($persona);

        $momento = $abiertoEn ?? now();

        /** @var Cuenta $cuenta */
        $cuenta = DB::transaction(function () use (
            $persona,
            $expediente,
            $tipo,
            $convenio,
            $sede,
            $servicioId,
            $medicoTratanteId,
            $motivo,
            $numeroPoliza,
            $numeroAutorizacion,
            $responsablePersonaId,
            $momento,
        ): Cuenta {
            $encuentro = Encuentro::query()->create([
                'sede_id'               => $sede->id,
                'expediente_id'         => $expediente->id,
                'persona_id'            => $persona->id,
                'numero'                => $this->correlativos->siguiente($sede, TipoCorrelativo::Encuentro),
                'tipo'                  => $tipo,
                'estado'                => EstadoEncuentro::Abierto,
                'servicio_id'           => $servicioId,
                'medico_tratante_id'    => $medicoTratanteId,
                'motivo'                => $motivo,
                'abierto_en'            => $momento,
                'encuentro_anterior_id' => $this->encuentroPrevioReciente($persona, $momento),
            ]);

            $cuenta = Cuenta::query()->create([
                'sede_id'                => $sede->id,
                'encuentro_id'           => $encuentro->id,
                'numero'                 => $this->correlativos->siguiente($sede, TipoCorrelativo::Cuenta),
                'convenio_id'            => $convenio->id,
                'numero_poliza'          => $numeroPoliza,
                'numero_autorizacion'    => $numeroAutorizacion,
                'responsable_persona_id' => $responsablePersonaId,
                'estado'                 => EstadoCuenta::Abierta,
                'abierta_en'             => $momento,
                'motivo_apertura'        => 'Apertura del encuentro '.$encuentro->numero.'.',
            ]);

            /*
             * ⚠️ Los ceros de los totales viven como DEFAULT de la base y
             * NO vuelven al modelo en memoria después de `create()`
             * (lección del bloque 3). Sin este `refresh`, la cuenta recién
             * abierta sale con `total` en null y el primer
             * `Monto::de($cuenta->total)` revienta con TypeError bajo
             * strict_types — en plena admisión.
             *
             * Y no se arregla mandando los ceros en el `create()`: esas
             * columnas no están en `$fillable` a propósito, así que
             * Laravel las descartaría en silencio.
             */
            return $cuenta->refresh();
        });

        return $cuenta;
    }

    /**
     * El ingreso anterior del mismo paciente, si fue hace menos de 30
     * días (§9.K14).
     *
     * Es indicador de calidad y moneda de negociación con aseguradoras, y
     * reconstruirlo después es imposible si cada ingreso es una isla. Se
     * enlaza al abrir, que es el único momento en que sale gratis.
     */
    private function encuentroPrevioReciente(Persona $persona, CarbonInterface $momento): ?int
    {
        $previo = Encuentro::query()
            ->where('persona_id', $persona->id)
            ->where('abierto_en', '>=', $momento->copy()->subDays(30))
            ->where('abierto_en', '<', $momento)
            ->orderByDesc('abierto_en')
            ->first();

        return $previo instanceof Encuentro ? $previo->id : null;
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * 🔴 UN PACIENTE, UNA CUENTA VIVA (ADR-0007)
     * ─────────────────────────────────────────────────────────────────
     *
     * Es más ancho que `exigirQueNoEsteYaInternado`, que solo mira la
     * cama y solo cuando el ingreso nuevo la ocupa. Un paciente internado
     * al que le abren una consulta externa pasaba ese filtro y terminaba
     * con dos cuentas vivas — o sea, con dos facturas para una misma
     * estadía.
     *
     * Los dos filtros se quedan: el de la cama da un mensaje mejor para
     * su caso, y por eso corre primero.
     *
     * ⚠️ Congelada cuenta como viva. Congelar es el paso previo a cerrar,
     * no un cierre: la cuenta todavía va a salir en una factura.
     *
     * @throws CuentaException
     */
    private function exigirQueNoTengaCuentaAbierta(Persona $persona): void
    {
        $abierta = Cuenta::query()
            ->whereIn('estado', [
                EstadoCuenta::Abierta->value,
                EstadoCuenta::Congelada->value,
            ])
            ->whereHas('encuentro', fn (Builder $encuentro): Builder => $encuentro
                ->where('persona_id', $persona->id))
            ->orderByDesc('id')
            ->first();

        if ($abierta instanceof Cuenta) {
            throw CuentaException::elPacienteYaTieneUnaAbierta(
                $persona->nombreCompleto(),
                $abierta->numero,
            );
        }
    }

    /**
     * @throws EncuentroException
     */
    private function exigirQueNoEsteYaInternado(Persona $persona, TipoEncuentro $tipo): void
    {
        if (! $tipo->ocupaCama()) {
            return;
        }

        $abierto = Encuentro::query()
            ->where('persona_id', $persona->id)
            ->where('tipo', TipoEncuentro::Hospitalizacion->value)
            ->vivos()
            ->first();

        if ($abierto instanceof Encuentro) {
            throw EncuentroException::yaEstaInternado($persona->nombreCompleto(), $abierto->numero);
        }
    }

    /**
     * @throws CuentaException
     */
    private function exigirConvenioVigente(Convenio $convenio, CarbonInterface $fecha): void
    {
        $desde = $convenio->vigencia_desde;
        $hasta = $convenio->vigencia_hasta;

        $arrancado = $desde === null || $desde->lessThanOrEqualTo($fecha);
        $sigue = $hasta === null || $hasta->greaterThanOrEqualTo($fecha);

        if (! $arrancado || ! $sigue) {
            throw CuentaException::convenioSinVigencia($convenio->nombre);
        }
    }

    /**
     * @throws EncuentroException
     */
    private function sedeDelContexto(): Sede
    {
        $id = ContextoSede::actualId();

        if ($id === null) {
            throw EncuentroException::sinSede();
        }

        try {
            return Sede::query()->findOrFail($id);
        } catch (ModelNotFoundException) {
            throw EncuentroException::sinSede();
        }
    }

    /**
     * Los pagadores que la pantalla ofrece al abrir, con CONTADO
     * primero: es el que se elige en la mayoría de los ingresos y en
     * todos los de emergencia sin papeles.
     *
     * @return Collection<int, Convenio>
     */
    public function pagadoresDisponibles(?CarbonInterface $fecha = null): Collection
    {
        $fecha = $fecha ?? now();

        return Convenio::query()
            ->where('vigencia_desde', '<=', $fecha->toDateString())
            ->where(function ($consulta) use ($fecha): void {
                $consulta->whereNull('vigencia_hasta')
                    ->orWhere('vigencia_hasta', '>=', $fecha->toDateString());
            })
            ->orderByRaw('CASE WHEN tipo = ? THEN 0 ELSE 1 END', [TipoConvenio::Contado->value])
            ->orderBy('nombre')
            ->get();
    }
}
