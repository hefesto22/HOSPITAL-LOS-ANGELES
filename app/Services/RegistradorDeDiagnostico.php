<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\EstadoDiagnostico;
use App\Domain\Enums\EstadoEncuentro;
use App\Domain\Enums\MomentoDiagnostico;
use App\Domain\Enums\TipoDiagnostico;
use App\Domain\Exceptions\DiagnosticoException;
use App\Models\Cie10;
use App\Models\Diagnostico;
use App\Models\Encuentro;
use App\Support\UsuarioAutenticado;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * La única puerta de escritura del diagnóstico (§11).
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 ACÁ NO HAY `UPDATE` (ADR-0004)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Corregir es escribir otro que apunta al anterior; el anterior queda
 * legible y tachado, con quién lo cambió y por qué. Lo que un perito
 * busca no es el diagnóstico final: es qué se pensó, cuándo se cambió de
 * idea y quién la cambió. Un `UPDATE diagnosticos SET cie10_id = ...`
 * borra exactamente eso, y lo borra para siempre.
 *
 * La ÚNICA escritura que no crea una fila nueva es marcar el anterior
 * como corregido o retractado — que no toca su contenido, solo su
 * estado. Esa es la diferencia entre enmendar y editar.
 */
final class RegistradorDeDiagnostico
{
    /**
     * @throws DiagnosticoException
     */
    public function registrar(
        Encuentro $encuentro,
        Cie10 $cie10,
        TipoDiagnostico $tipo,
        MomentoDiagnostico $momento,
        ?bool $confirmado = null,
        ?string $observacion = null,
        ?CarbonInterface $enQueMomento = null,
    ): Diagnostico {
        $this->exigirEncuentroVivo($encuentro);
        $this->exigirQueNoEsteRepetido($encuentro, $cie10, $momento);

        if ($tipo === TipoDiagnostico::Principal) {
            $this->exigirQueNoHayaOtroPrincipal($encuentro, $momento);
        }

        return Diagnostico::query()->create([
            'encuentro_id' => $encuentro->id,
            'cie10_id'     => $cie10->id,
            'tipo'         => $tipo->value,
            'momento'      => $momento->value,
            'estado'       => EstadoDiagnostico::Vigente->value,
            /*
             * Al ingreso nace presuntivo y al egreso confirmado, pero es
             * solo la propuesta: quien firma decide. Guardar un presuntivo
             * como confirmado es lo que hace que las estadísticas cuenten
             * apendicitis que nunca existieron.
             */
            'confirmado'        => $confirmado ?? $momento->naceConfirmado(),
            'observacion'       => $observacion,
            'diagnosticado_por' => UsuarioAutenticado::id(),
            'diagnosticado_en'  => $enQueMomento ?? now(),
        ]);
    }

    /**
     * Cambió el criterio: el anterior queda tachado y el nuevo lo
     * referencia.
     *
     * Los dos movimientos van en UNA transacción. A mitad de camino
     * quedarían dos principales vigentes —o ninguno—, y el índice parcial
     * de la base rechazaría el primer caso justo después de haber tachado
     * el viejo, dejando el encuentro sin diagnóstico principal.
     *
     * @throws DiagnosticoException
     */
    public function corregir(
        Diagnostico $anterior,
        Cie10 $cie10,
        string $motivo,
        ?bool $confirmado = null,
        ?string $observacion = null,
    ): Diagnostico {
        $this->exigirVigente($anterior);
        $this->exigirMotivo($motivo);

        /** @var Diagnostico $nuevo */
        $nuevo = DB::transaction(function () use ($anterior, $cie10, $motivo, $confirmado, $observacion): Diagnostico {
            $anterior->forceFill([
                'estado'            => EstadoDiagnostico::Corregido->value,
                'motivo_correccion' => $motivo,
            ])->save();

            return Diagnostico::query()->create([
                'encuentro_id'      => $anterior->encuentro_id,
                'cie10_id'          => $cie10->id,
                'tipo'              => $anterior->tipo->value,
                'momento'           => $anterior->momento->value,
                'estado'            => EstadoDiagnostico::Vigente->value,
                'confirmado'        => $confirmado ?? $anterior->confirmado,
                'observacion'       => $observacion ?? $anterior->observacion,
                'diagnosticado_por' => UsuarioAutenticado::id(),
                'diagnosticado_en'  => now(),
                'corrige_a_id'      => $anterior->id,
            ]);
        });

        return $nuevo;
    }

    /**
     * No debió escribirse nunca. Distinto de corregir: acá no hay
     * reemplazo, y por eso el motivo importa todavía más — es lo único
     * que va a explicar el hueco.
     *
     * @throws DiagnosticoException
     */
    public function retractar(Diagnostico $diagnostico, string $motivo): Diagnostico
    {
        $this->exigirVigente($diagnostico);
        $this->exigirMotivo($motivo);

        $diagnostico->forceFill([
            'estado'            => EstadoDiagnostico::Retractado->value,
            'motivo_correccion' => $motivo,
        ])->save();

        return $diagnostico;
    }

    /**
     * @throws DiagnosticoException
     */
    private function exigirEncuentroVivo(Encuentro $encuentro): void
    {
        if ($encuentro->estado === EstadoEncuentro::Anulado) {
            throw DiagnosticoException::encuentroAnulado($encuentro->numero);
        }
    }

    /**
     * ⚠️ Se pregunta antes aunque la base ya lo impida con un índice
     * parcial. El índice evita el dato malo; esto evita que el médico se
     * lleve un error de Postgres en la cara cuando lo que necesita es que
     * le digan qué hacer en su lugar.
     *
     * @throws DiagnosticoException
     */
    private function exigirQueNoHayaOtroPrincipal(Encuentro $encuentro, MomentoDiagnostico $momento): void
    {
        $principal = Diagnostico::query()
            ->where('encuentro_id', $encuentro->id)
            ->where('momento', $momento->value)
            ->where('tipo', TipoDiagnostico::Principal->value)
            ->vigentes()
            ->with('cie10')
            ->first();

        if ($principal instanceof Diagnostico) {
            /*
             * Sin `?->`: `cie10_id` es NOT NULL y la relación viene
             * cargada con `with`, así que siempre está. Y dentro del
             * operando izquierdo de `??` el nullsafe no protegería nada
             * igual — `??` usa semántica de `isset` y no tira error al
             * pedirle una propiedad a null.
             */
            throw DiagnosticoException::yaHayPrincipal(
                mb_strtolower($momento->etiqueta()),
                $principal->cie10->codigo,
            );
        }
    }

    /**
     * @throws DiagnosticoException
     */
    private function exigirQueNoEsteRepetido(
        Encuentro $encuentro,
        Cie10 $cie10,
        MomentoDiagnostico $momento,
    ): void {
        $repetido = Diagnostico::query()
            ->where('encuentro_id', $encuentro->id)
            ->where('momento', $momento->value)
            ->where('cie10_id', $cie10->id)
            ->vigentes()
            ->exists();

        if ($repetido) {
            throw DiagnosticoException::yaEstaRegistrado($cie10->codigo);
        }
    }

    /**
     * @throws DiagnosticoException
     */
    private function exigirVigente(Diagnostico $diagnostico): void
    {
        if (! $diagnostico->estado->esVigente()) {
            throw DiagnosticoException::noEsVigente();
        }
    }

    /**
     * @throws DiagnosticoException
     */
    private function exigirMotivo(string $motivo): void
    {
        if (mb_strlen(trim($motivo)) < 10) {
            throw DiagnosticoException::enmiendaSinMotivo();
        }
    }
}
