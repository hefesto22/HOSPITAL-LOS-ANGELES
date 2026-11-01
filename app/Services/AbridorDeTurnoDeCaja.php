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
use App\Support\NombresDeTurno;
use App\Support\TextoCanonico;
use BezhanSalleh\FilamentShield\Support\Utils as ShieldUtils;
use Illuminate\Database\Eloquent\Builder;
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
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL TURNO ES DE LA GAVETA, NO DE QUIEN LO ABRIÓ (13-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: a veces la administración abre el turno A y lo cierra la
 * cajera del turno A, o al revés; y la administración puede abrir el A,
 * cerrarlo y abrir el B. Así que:
 *
 *   · Hay UN turno abierto por sede —una gaveta—, se llame como se llame.
 *   · Lo opera (cobra en él, factura con él, lo cierra) quien tiene ese
 *     turno asignado en su usuario y los roles de
 *     `sihla.caja.operan_cualquier_turno`. Ver `puedeOperar()`.
 *   · La cajera abre solo SU turno; la administración, cualquiera. Al
 *     abrir, el nombre ya viene: el suyo para la cajera, y para la
 *     administración el que sigue al último en el orden de
 *     `sihla.caja.turnos`. Ver `puedeAbrir()` y `elQueAbre()`.
 *
 * Lo que responsabiliza sigue escrito: `usuario_id` es quién abrió,
 * `cerrado_por` quién contó, y cada recibo dice quién lo cobró.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 ABRIRLO NO LO HACE TUYO (Mauricio, 31-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Hasta el 31-oct el turno también lo operaba quien lo abrió, y la
 * cajera del turno A abría el C y lo cerraba ella: firmaba el arqueo de
 * una gaveta que no era la suya. Mauricio: cada cajera abre y cierra
 * solo su turno; el de otra lo cierran las de ese turno, la dirección o
 * el super administrador.
 *
 * Por eso quien abrió ya no cuenta para operar. La única excepción es el
 * hospital sin turnos configurados: ahí no hay a quién más asignárselo,
 * y el turno lo trabaja quien lo abrió.
 */
final class AbridorDeTurnoDeCaja
{
    public function __construct(
        private readonly AsignadorDeCorrelativo $correlativos,
    ) {}

    /**
     * Abre la gaveta de la sede.
     *
     * Sin nombre, toma el que le toca a quien abre (`elQueAbre()`): el
     * suyo para la cajera, el que sigue para la administración. Nadie
     * teclea nada con el primer paciente enfrente.
     */
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
            /*
             * ─────────────────────────────────────────────────────────
             * 🔴 LA FILA DE LA SEDE ES LA GAVETA
             * ─────────────────────────────────────────────────────────
             *
             * Dos personas que abren a la vez pasarían las dos la
             * pregunta de abajo y quedarían dos turnos abiertos sobre un
             * solo montón de billetes. Bloquear la sede las pone en fila:
             * la segunda espera, mira, y encuentra el turno de la
             * primera.
             *
             * ⚠️ Se verifica en el servicio y no con un índice único por
             * sede a propósito: el día que el hospital abra una segunda
             * ventanilla con su propia gaveta, esto es una condición que
             * se afloja; un índice sería una migración con datos vivos.
             */
            Sede::query()->whereKey($sede->id)->lockForUpdate()->first();

            $abierto = $this->abiertoEn($sede);

            if ($abierto instanceof TurnoDeCaja) {
                throw CajaException::laGavetaYaEstaAbierta(
                    $abierto->etiquetaCorta(),
                    /* `->` y no `?->`: el `??` ya atrapa el nulo solo. */
                    $abierto->usuario->name ?? 'Otra persona',
                    $abierto->abierto_en->format('H:i'),
                    $this->puedeOperar($usuario, $abierto),
                );
            }

            /*
             * El índice parcial `turnos_de_caja_uno_abierto_por_usuario`
             * sigue en pie: quien ya tiene un turno abierto en OTRA sede
             * no abre un segundo. La pregunta atrapa el caso normal con
             * un mensaje que se entiende; el índice, la carrera.
             */
            $propio = $this->abiertoPor($usuario);

            if ($propio instanceof TurnoDeCaja) {
                throw CajaException::yaTenesUnTurnoAbierto($propio->numero);
            }

            /*
             * 🔴 La cajera abre SU turno, no el que le parezca: abrir el
             * de otra es dejarle a otra persona una gaveta que no contó,
             * o quedarse con un arqueo que no es suyo. Se pregunta antes
             * de pedir el correlativo, para no gastar un número en un
             * turno que no se abre.
             */
            $nombre = TextoCanonico::mayusculas($nombre) ?? $this->elQueAbre($usuario, $sede);

            if (! $this->puedeAbrir($usuario, $nombre)) {
                throw CajaException::soloAbrisTuTurno($nombre, $usuario->turno);
            }

            $ahora = now();

            return TurnoDeCaja::query()->create([
                'sede_id'    => $sede->id,
                'numero'     => $this->correlativos->siguiente($sede, TipoCorrelativo::TurnoDeCaja),
                'nombre'     => $nombre,
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

            /*
             * 🔴 Cuenta la gaveta quien responde por ella: quien es de ese
             * turno o la administración. La cajera del turno B no cierra
             * el A — firmaría un arqueo de plata que no pasó por sus
             * manos—, y tampoco lo cierra por haberlo abierto ella.
             */
            $quienCierra = $cerradoPor === null ? null : User::query()->find($cerradoPor);

            if ($quienCierra instanceof User && ! $this->puedeOperar($quienCierra, $bloqueado)) {
                throw CajaException::noEsTuTurno($bloqueado->etiquetaCorta(), $quienCierra->turno);
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
     * El turno abierto de la gaveta de esa sede, sea de quien sea.
     */
    public function abiertoEn(Sede|int $sede): ?TurnoDeCaja
    {
        $sedeId = $sede instanceof Sede ? $sede->id : $sede;

        return TurnoDeCaja::query()
            ->with('usuario:id,name')
            ->where('sede_id', $sedeId)
            ->where('estado', EstadoTurnoDeCaja::Abierto->value)
            ->orderBy('id')
            ->first();
    }

    /**
     * El turno abierto en el que esta persona trabaja: el de la gaveta de
     * la sede, cuando le toca operarlo.
     *
     * Es lo que pregunta todo lo que mueve plata —recibir un abono,
     * facturar, cerrar—. Sin sede (no se sabe dónde está parada) se mira
     * el que abrió ella, y también tiene que poder operarlo: haberlo
     * abierto no alcanza (31-oct-2026).
     */
    public function elQueOpera(User $usuario, Sede|int|null $sede = null): ?TurnoDeCaja
    {
        $sedeId = $sede instanceof Sede ? $sede->id : $sede;

        $turno = $sedeId === null ? $this->abiertoPor($usuario) : $this->abiertoEn($sedeId);

        return $turno instanceof TurnoDeCaja && $this->puedeOperar($usuario, $turno) ? $turno : null;
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * QUIÉN PUEDE TRABAJAR EN UN TURNO (31-oct-2026)
     * ─────────────────────────────────────────────────────────────────
     *
     * Quien tiene ese turno asignado en su usuario y los roles de
     * `sihla.caja.operan_cualquier_turno` (más el super administrador).
     * Quien lo abrió NO, por haberlo abierto: si es de otro turno, el
     * arqueo lo cuentan las de este o la administración.
     *
     * El permiso de cobrar o de cerrar se pide aparte: esto dice EN QUÉ
     * turno, no SI puede.
     */
    public function puedeOperar(User $usuario, TurnoDeCaja $turno): bool
    {
        /*
         * Sin turnos configurados no hay a quién más asignárselo: lo
         * trabaja quien lo abrió. Es la clínica de una sola cajera, y
         * abrirle la caja no debería pedir tocar código (§1.1).
         */
        $esSuyo = NombresDeTurno::enOrden() === []
            ? $turno->usuario_id === $usuario->id
            : NombresDeTurno::mismo($usuario->turno, $turno->nombre);

        return $esSuyo || $this->operaCualquierTurno($usuario);
    }

    /**
     * Si esta persona puede abrir la gaveta en ese turno: el suyo, y
     * cualquiera para la administración (Mauricio, 31-oct-2026).
     *
     * Quien no tiene turno asignado no abre: abriría una gaveta que
     * después no puede cobrar ni cerrar. Para preguntar si puede abrir
     * alguno, se le pasa el turno de su usuario.
     */
    public function puedeAbrir(User $usuario, ?string $nombre): bool
    {
        if (NombresDeTurno::enOrden() === [] || NombresDeTurno::mismo($usuario->turno, $nombre)) {
            return true;
        }

        return $this->operaCualquierTurno($usuario);
    }

    /**
     * El turno que viene marcado al abrir: el suyo para la cajera, el que
     * sigue al último para la administración —que abre el que toque—.
     */
    public function elQueAbre(User $usuario, Sede|int $sede): ?string
    {
        $suyo = TextoCanonico::mayusculas($usuario->turno);

        if ($suyo !== null && NombresDeTurno::enOrden() !== [] && ! $this->operaCualquierTurno($usuario)) {
            return $suyo;
        }

        return $this->siguienteEn($sede);
    }

    /**
     * La administración: los roles de `sihla.caja.operan_cualquier_turno`
     * y el super administrador. Abren, cobran y cierran cualquier turno.
     */
    public function operaCualquierTurno(User $usuario): bool
    {
        return $usuario->hasAnyRole([...self::rolesQueOperanCualquierTurno(), ShieldUtils::getSuperAdminName()]);
    }

    /**
     * El turno que toca abrir en esa sede: el que sigue al último que se
     * abrió, en el orden de `sihla.caja.turnos`.
     */
    public function siguienteEn(Sede|int $sede): ?string
    {
        return NombresDeTurno::siguienteDe($this->ultimoEn($sede)?->nombre);
    }

    /**
     * El último turno que se abrió en esa sede, abierto o cerrado.
     */
    public function ultimoEn(Sede|int $sede): ?TurnoDeCaja
    {
        $sedeId = $sede instanceof Sede ? $sede->id : $sede;

        return TurnoDeCaja::query()
            ->with(['usuario:id,name', 'cerradoPor:id,name'])
            ->where('sede_id', $sedeId)
            ->orderByDesc('abierto_en')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Quiénes son de cada turno en esa sede, por nombre en mayúsculas.
     * Lo usa la pantalla de abrir: quien abre ve quién va a cerrar.
     *
     * @return array<string, list<string>>
     */
    public function asignadosEn(Sede|int $sede): array
    {
        $sedeId = $sede instanceof Sede ? $sede->id : $sede;

        $usuarios = User::query()
            ->where('is_active', true)
            ->whereNotNull('turno')
            ->where(fn (Builder $consulta): Builder => $consulta->where('sede_id', $sedeId)->orWhereNull('sede_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'turno']);

        $porTurno = [];

        foreach ($usuarios as $usuario) {
            $turno = TextoCanonico::mayusculas($usuario->turno);

            if ($turno !== null) {
                $porTurno[$turno][] = $usuario->name;
            }
        }

        return $porTurno;
    }

    /**
     * El turno abierto que abrió esta persona, si tiene uno.
     */
    private function abiertoPor(User $usuario, ?int $sedeId = null): ?TurnoDeCaja
    {
        return TurnoDeCaja::query()
            ->with('usuario:id,name')
            ->where('usuario_id', $usuario->id)
            ->where('estado', EstadoTurnoDeCaja::Abierto->value)
            ->when($sedeId !== null, fn (Builder $consulta): Builder => $consulta->where('sede_id', $sedeId))
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return list<string>
     */
    private static function rolesQueOperanCualquierTurno(): array
    {
        $roles = config('sihla.caja.operan_cualquier_turno', []);

        return is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [];
    }
}
