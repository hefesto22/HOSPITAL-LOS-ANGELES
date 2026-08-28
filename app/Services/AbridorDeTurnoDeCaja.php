<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\EstadoTurnoDeCaja;
use App\Domain\Enums\TipoCorrelativo;
use App\Domain\Exceptions\CajaException;
use App\Domain\ValueObjects\Decimal;
use App\Models\Sede;
use App\Models\TurnoDeCaja;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Abre y cierra el turno de caja de una persona.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL ARQUEO ES LO QUE LE DA SENTIDO A TODO ESTO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Abrir es declarar con cuánto se arranca. Cerrar es contar lo que hay y
 * compararlo con lo que tendría que haber:
 *
 *     esperado   = fondo inicial + efectivo recibido en el turno
 *     diferencia = contado − esperado
 *
 * Un turno que cierra sin contar no sirve para nada, y por eso la base
 * rechaza un cierre sin las cuatro columnas del arqueo.
 *
 * 🔴 EL ESPERADO SE CONGELA. Se calcula en el momento del cierre y se
 * guarda. Recalcularlo después haría que anular un recibo mañana cambie
 * el arqueo de anoche — y un arqueo que se mueve solo no responsabiliza
 * a nadie.
 */
final class AbridorDeTurnoDeCaja
{
    public function __construct(
        private readonly AsignadorDeCorrelativo $correlativos,
    ) {}

    public function abrir(
        User $usuario,
        Sede $sede,
        Decimal $fondoInicial,
        ?string $nombre = null,
    ): TurnoDeCaja {
        if ($fondoInicial->esNegativo()) {
            throw CajaException::elEfectivoContadoEsInvalido();
        }

        return DB::transaction(function () use ($usuario, $sede, $fondoInicial, $nombre): TurnoDeCaja {
            $abierto = $this->abiertoDe($usuario);

            /*
             * La verificación acá atrapa el caso normal con un mensaje
             * que se entiende. El índice parcial de la base atrapa la
             * carrera —dos pestañas, doble clic— que esta lectura no
             * puede ver.
             */
            if ($abierto instanceof TurnoDeCaja) {
                throw CajaException::yaTenesUnTurnoAbierto($abierto->numero);
            }

            /*
             * ─────────────────────────────────────────────────────────
             * 🔴 Y TAMPOCO SI OTRO DEJÓ EL SUYO ABIERTO.
             * ─────────────────────────────────────────────────────────
             *
             * Una sede, una gaveta. Dos turnos abiertos a la vez
             * terminan en un solo montón de billetes con dos personas
             * responsables — y ningún arqueo que se pueda defender.
             *
             * ⚠️ Se verifica en el servicio y no con un índice único
             * por sede a propósito: el día que el hospital abra una
             * segunda ventanilla con su propia gaveta, esto es una
             * condición que se afloja; un índice sería una migración
             * con datos vivos adentro.
             */
            $ajeno = $this->otroTurnoAbiertoEn($sede, $usuario);

            if ($ajeno instanceof TurnoDeCaja) {
                throw CajaException::otraPersonaTieneLaGaveta(
                    /* `->` y no `?->`: el `??` ya atrapa el nulo solo. */
                    $ajeno->usuario->name ?? 'Otra persona',
                    $ajeno->numero,
                    $ajeno->abierto_en->format('H:i'),
                );
            }

            $ahora = now();

            return TurnoDeCaja::query()->create([
                'sede_id'    => $sede->id,
                'numero'     => $this->correlativos->siguiente($sede, TipoCorrelativo::TurnoDeCaja),
                'nombre'     => $nombre === null || trim($nombre) === '' ? null : trim($nombre),
                'usuario_id' => $usuario->id,
                'estado'     => EstadoTurnoDeCaja::Abierto->value,

                'fondo_inicial' => $fondoInicial->redondeado(2),
                'abierto_en'    => $ahora,

                /*
                 * ⚠️ La fecha de operación la pone PHP, no Postgres: el
                 * servidor puede estar en UTC y el turno que abre a las
                 * 11 de la noche caería en el día siguiente.
                 */
                'fecha_operacion' => $ahora->toDateString(),
            ]);
        });
    }

    public function cerrar(
        TurnoDeCaja $turno,
        Decimal $efectivoContado,
        ?string $notas = null,
        ?int $cerradoPor = null,
    ): TurnoDeCaja {
        if ($efectivoContado->esNegativo()) {
            throw CajaException::elEfectivoContadoEsInvalido();
        }

        return DB::transaction(function () use ($turno, $efectivoContado, $notas, $cerradoPor): TurnoDeCaja {
            /** @var TurnoDeCaja $bloqueado */
            $bloqueado = TurnoDeCaja::query()->whereKey($turno->id)->lockForUpdate()->firstOrFail();

            if (! $bloqueado->estaAbierto()) {
                throw CajaException::elTurnoYaEstaCerrado($bloqueado->numero);
            }

            $esperado = $bloqueado->efectivoEsperado();
            $diferencia = $efectivoContado->restar($esperado);

            /*
             * 🔴 Sobrante o faltante SIN explicación no se guarda. Es la
             * razón de ser del arqueo: si los billetes no cuadran,
             * alguien escribe por qué esa misma noche.
             */
            if (! $diferencia->esCero() && ($notas === null || mb_strlen(trim($notas)) < 10)) {
                throw CajaException::laDiferenciaExigeExplicacion($diferencia->redondeado(2));
            }

            $bloqueado->update([
                'estado'            => EstadoTurnoDeCaja::Cerrado->value,
                'cerrado_en'        => now(),
                'cerrado_por'       => $cerradoPor,
                'efectivo_esperado' => $esperado->redondeado(2),
                'efectivo_contado'  => $efectivoContado->redondeado(2),
                'diferencia'        => $diferencia->redondeado(2),
                'notas_cierre'      => $notas === null || trim($notas) === '' ? null : trim($notas),
            ]);

            return $bloqueado->refresh();
        });
    }

    /**
     * El turno abierto de OTRA persona en esta sede, si lo hay.
     */
    private function otroTurnoAbiertoEn(Sede $sede, User $usuario): ?TurnoDeCaja
    {
        return TurnoDeCaja::query()
            ->with('usuario:id,name')
            ->where('sede_id', $sede->id)
            ->where('usuario_id', '<>', $usuario->id)
            ->where('estado', EstadoTurnoDeCaja::Abierto->value)
            ->orderBy('id')
            ->first();
    }

    /**
     * El turno abierto de esta persona, si tiene uno.
     */
    public function abiertoDe(User|int $usuario): ?TurnoDeCaja
    {
        $id = $usuario instanceof User ? $usuario->id : $usuario;

        return TurnoDeCaja::query()
            ->where('usuario_id', $id)
            ->where('estado', EstadoTurnoDeCaja::Abierto->value)
            ->orderByDesc('id')
            ->first();
    }
}
