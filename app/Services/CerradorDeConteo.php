<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\EstadoConteo;
use App\Domain\Enums\MotivoDeAjuste;
use App\Domain\Enums\TipoDeAjuste;
use App\Domain\Exceptions\ConteoException;
use App\Domain\ValueObjects\LineaAjustada;
use App\Domain\ValueObjects\ResultadoDeCierre;
use App\Models\Conteo;
use App\Models\ConteoLinea;
use App\Models\User;
use App\Support\AlmacenesDelUsuario;
use App\Support\UsuarioAutenticado;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Cerrar el conteo: el momento en que la medición se vuelve inventario.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SE ASIENTA LA DIFERENCIA, NUNCA EL VALOR ABSOLUTO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Cada línea que no cuadró produce un movimiento por su **diferencia**
 * —lo contado menos lo que decía el sistema en el instante de contar—, y
 * no un «dejá el saldo en 95».
 *
 * Es lo que permite que la bodega y la farmacia sigan trabajando durante
 * el conteo. Con un valor absoluto, todo lo despachado entre el conteo y
 * el cierre se devolvería al estante sin que nadie lo pidiera.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 EL CANDADO SOBRE EL CONTEO, Y POR QUÉ LAS LÍNEAS SE LEEN ADENTRO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Lo primero que hace la transacción es `FOR UPDATE` sobre la fila del
 * conteo, y recién después lee las líneas. Las dos cosas importan:
 *
 *   · el candado hace esperar a quien esté registrando una lectura
 *     —`RegistradorDeConteo` toma `FOR SHARE` sobre la misma fila—, y a
 *     un segundo cierre simultáneo, que al despertar lee «cerrado» y
 *     sale con un mensaje del dominio en vez de un error de SQL;
 *   · leer las líneas adentro evita asentar una foto vieja. Leerlas
 *     antes dejaba una ventana en la que el auxiliar tecleaba el recuento
 *     que le pedimos, su escritura pasaba, y el cierre asentaba igual la
 *     lectura anterior: la línea decía −2, el kardex −7, los dos
 *     documentos inmutables, y ni un error en pantalla.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 LOS CONTROLADOS SE CUENTAN, PERO NO SE AJUSTAN
 * ─────────────────────────────────────────────────────────────────────
 *
 * §9.F11: la existencia de un estupefaciente o un psicotrópico **no se
 * ajusta directamente, nunca**. Si un controlado no cuadra, el cierre
 * asienta todo lo demás y deja esa diferencia **sin tocar el kardex**,
 * anotada en el conteo para que alguien la resuelva donde corresponde:
 * el libro de controlados, con folio, saldo corrido y doble firma.
 *
 * Puede parecer que el sistema queda mintiendo sobre ese producto. Es al
 * revés: lo que no puede pasar es que un descuadre de fentanilo
 * desaparezca apretando un botón.
 *
 * ─────────────────────────────────────────────────────────────────────
 * UNA LÍNEA QUE YA NO CABE NO TUMBA EL CIERRE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Si entre el conteo y el cierre se despachó todo lo que había, el ajuste
 * negativo dejaría el estante en negativo y el movimiento se rechaza. Esa
 * línea se trata igual que un controlado —no se asienta y se reporta— en
 * vez de tumbar las otras doscientas noventa y nueve.
 *
 * ─────────────────────────────────────────────────────────────────────
 * CUATRO OJOS
 * ─────────────────────────────────────────────────────────────────────
 *
 * No cierra quien abrió el conteo. Cerrar es lo que asienta los
 * faltantes, y un faltante firmado por la misma persona que dijo haberlo
 * contado es un faltante que nadie verificó. La base lo vuelve a exigir
 * con un CHECK.
 */
final class CerradorDeConteo
{
    public function __construct(
        private readonly RegistradorDeAjuste $ajustes,
        private readonly ConsultorDeExistencias $existencias,
    ) {}

    /**
     * @throws ConteoException
     */
    public function cerrar(Conteo $conteo, ?User $autorizador = null): ResultadoDeCierre
    {
        $conteo->loadMissing('almacen');

        AlmacenesDelUsuario::exigirAcceso($conteo->almacen);

        $quien = $this->verificar($conteo);

        /** @var ResultadoDeCierre $resultado */
        $resultado = DB::transaction(function () use ($conteo, $quien, $autorizador): ResultadoDeCierre {
            /*
             * El candado primero, todo lo demás después. Ver el
             * encabezado: sin esto, una lectura simultánea se pierde en
             * silencio y un segundo cierre revienta con SQL crudo.
             */
            $vigente = $this->conteoBloqueado($conteo);

            $lineas = $this->lineasQueNoCuadraron($vigente);

            /** @var list<string> $controlados */
            $controlados = [];

            /** @var list<string> $noCaben */
            $noCaben = [];

            /** @var list<LineaAjustada> $asentables */
            $asentables = [];

            foreach ($lineas as $linea) {
                if ($linea->item->es_controlado) {
                    $controlados[] = $linea->item->etiqueta();

                    continue;
                }

                if (! $this->todaviaCabe($vigente, $linea)) {
                    $noCaben[] = $linea->item->etiqueta();

                    continue;
                }

                $asentables[] = $this->comoAjuste($linea);
            }

            $ajuste = $asentables === []
                ? null
                : $this->ajustes->registrar(
                    almacen: $vigente->almacen,
                    tipo: TipoDeAjuste::DiferenciaDeConteo,
                    lineas: $asentables,
                    motivo: $this->motivoDelCierre($vigente),
                    referencia: "Conteo #{$vigente->id}",
                    ocurridoEn: $this->cuandoSeConto($vigente),
                    autorizador: $autorizador,
                    conteo: $vigente,
                );

            /*
             * El hallazgo se guarda EN EL CONTEO y no en el ajuste,
             * porque el ajuste puede no existir: un conteo de controlados
             * por turno donde el único descuadre es de controlados no
             * genera ninguno — y ese es justo el caso donde el hallazgo no
             * se puede perder. Una notificación en pantalla muere con la
             * sesión.
             */
            $vigente->notas_del_cierre = $this->notaDelCierre($controlados, $noCaben);
            $vigente->estado = EstadoConteo::Cerrado;
            $vigente->cerrado_en = now();
            $vigente->cerrado_por = $quien;
            $vigente->save();

            return new ResultadoDeCierre(
                conteo: $vigente,
                ajuste: $ajuste,
                lineasAsentadas: count($asentables),
                controladosSinAsentar: $controlados,
                noAsentadasPorExistencia: $noCaben,
            );
        });

        return $resultado;
    }

    /**
     * Anular un conteo que se abrió por error o se abandonó.
     *
     * No mueve nada y no borra nada: el conteo queda visible, anulado y
     * con su explicación. Borrarlo dejaría sin sentido la tarde que
     * alguien pasó contando el estante — y, peor, permitiría hacer
     * desaparecer un conteo que estaba mostrando un faltante incómodo.
     *
     * @throws ConteoException
     */
    public function anular(Conteo $conteo, string $motivo): Conteo
    {
        $conteo->loadMissing('almacen');

        AlmacenesDelUsuario::exigirAcceso($conteo->almacen);

        if (! $conteo->estaAbierto()) {
            throw ConteoException::noEstaAbierto($conteo->estado->etiqueta());
        }

        if (mb_strlen(trim($motivo)) < 10) {
            throw ConteoException::faltaElMotivoDeAnulacion();
        }

        DB::transaction(function () use ($conteo, $motivo): void {
            $vigente = $this->conteoBloqueado($conteo);

            $vigente->estado = EstadoConteo::Anulado;
            $vigente->anulado_en = now();
            $vigente->motivo_anulacion = trim($motivo);
            $vigente->save();
        });

        return $conteo->refresh();
    }

    /**
     * La fila del conteo, bloqueada en exclusiva y revalidada.
     *
     * @throws ConteoException
     */
    private function conteoBloqueado(Conteo $conteo): Conteo
    {
        $vigente = Conteo::query()
            ->with('almacen')
            ->whereKey($conteo->id)
            ->lockForUpdate()
            ->first();

        if (! $vigente instanceof Conteo) {
            throw ConteoException::noEstaAbierto('borrado');
        }

        if ($vigente->estaCerrado()) {
            throw ConteoException::yaSeCerro();
        }

        if (! $vigente->estaAbierto()) {
            throw ConteoException::noEstaAbierto($vigente->estado->etiqueta());
        }

        return $vigente;
    }

    /**
     * Todo lo que hay que poder decir ANTES de mover un solo movimiento.
     *
     * Se vuelve a verificar el estado dentro de la transacción, con la
     * fila bloqueada: esto de acá afuera es para dar un mensaje temprano,
     * no para decidir.
     *
     * @return int el id de quien cierra
     *
     * @throws ConteoException
     */
    private function verificar(Conteo $conteo): int
    {
        if ($conteo->estaCerrado()) {
            throw ConteoException::yaSeCerro();
        }

        if (! $conteo->estaAbierto()) {
            throw ConteoException::noEstaAbierto($conteo->estado->etiqueta());
        }

        $quien = UsuarioAutenticado::id();

        if ($quien === null) {
            throw ConteoException::faltaQuienCierra();
        }

        if ($conteo->created_by !== null && $quien === $conteo->created_by) {
            throw ConteoException::noSeCierraSolo();
        }

        $total = $conteo->lineas()->count();
        $maximo = AbridorDeConteo::maximoDeLineas();

        if ($total > $maximo) {
            throw ConteoException::demasiadasLineas($total, $maximo);
        }

        /*
         * 🔴 Nada queda en cero por omisión. En un conteo total, una
         * línea sin contar bloquea el cierre: declararla en cero es un
         * acto explícito de una persona, con su nombre y su hora.
         */
        if ($conteo->alcance->exigeContarTodo()) {
            $faltan = $conteo->cuantasFaltan();

            if ($faltan > 0) {
                throw ConteoException::faltanLineasPorContar($faltan);
            }
        }

        $recuentos = $conteo->cuantasExigenRecuento();

        if ($recuentos > 0) {
            throw ConteoException::faltanRecuentos($recuentos);
        }

        return $quien;
    }

    /**
     * ¿La diferencia todavía cabe en lo que hay hoy en el estante?
     *
     * Una lectura simple y sin candado, a propósito. Bloquear la fila de
     * existencia acá invertiría el orden de candados respecto de
     * `RegistradorDeAjuste` —que toma primero el del costo— y produciría
     * un abrazo mortal entre un cierre y un ajuste simultáneos.
     *
     * Queda una ventana angosta: que la existencia baje entre esta
     * lectura y el movimiento. En ese caso el `UPDATE ... WHERE cantidad
     * >= ?` del registrador afecta cero filas, el cierre entero revierte,
     * y la pantalla lo dice con todas las letras. Prefiero esa ventana
     * —rara, ruidosa y sin daño— a un deadlock silencioso a las once de
     * la noche.
     */
    private function todaviaCabe(Conteo $conteo, ConteoLinea $linea): bool
    {
        $diferencia = $linea->diferenciaDecimal();

        if (! $diferencia->esNegativo()) {
            return true;
        }

        $hay = $this->existencias->enElLote($linea->item, $linea->lote, $conteo->almacen);

        return ! $hay->sumar($diferencia)->esNegativo();
    }

    /**
     * Las líneas contadas que no cuadraron, con su producto y su lote.
     *
     * El `with()` no es adorno: sin él, un conteo con cincuenta
     * diferencias haría cien consultas más para leer el nombre y el lote
     * de cada una — el N+1 del §13.2, en el peor momento posible, que es
     * mientras la transacción tiene la fila del conteo bloqueada.
     *
     * `array_values()` porque el contrato es `list<>`: `Collection::all()`
     * devuelve `array<int, X>`, que para PHPStan no es lo mismo.
     *
     * @return list<ConteoLinea>
     */
    private function lineasQueNoCuadraron(Conteo $conteo): array
    {
        return array_values(
            $conteo->lineas()
                ->with(['item', 'lote'])
                ->conDiferencia()
                ->orderBy('id')
                ->get()
                ->all()
        );
    }

    /**
     * Traduce una línea del conteo a una línea de ajuste.
     */
    private function comoAjuste(ConteoLinea $linea): LineaAjustada
    {
        $diferencia = $linea->diferenciaDecimal();
        $sobra = ! $diferencia->esNegativo();

        return new LineaAjustada(
            item: $linea->item,
            lote: $linea->lote,
            motivo: $sobra ? MotivoDeAjuste::SobranteDeConteo : MotivoDeAjuste::FaltanteDeConteo,
            cantidad: $sobra ? $diferencia : $diferencia->por('-1'),
            esEntrada: $sobra,
            texto: $this->textoDeLaLinea($linea),
            conteoLineaId: $linea->id,
        );
    }

    /**
     * Lo que quedará escrito en el kardex de por vida: contra qué saldo
     * se contó, qué se vio, y cuántas veces se contó.
     */
    private function textoDeLaLinea(ConteoLinea $linea): string
    {
        $sistema = $linea->cantidadSistemaDecimal()->redondeado(4);
        $contado = $linea->cantidadContadaDecimal()->redondeado(4);

        $texto = "sistema {$sistema}, contado {$contado}";

        return $linea->veces_contado >= 2
            ? $texto." (contado {$linea->veces_contado} veces)"
            : $texto;
    }

    /**
     * Cuándo se contó, que NO es cuándo se cierra.
     *
     * §7.5-4 y §8.7-9: la fecha de operación es la del hecho. Un conteo
     * hecho el 31 de agosto y cerrado el 1 de septiembre tiene que
     * asentar su merma en agosto, o el costo de ventas del mes cambia
     * después de cerrado y la gerencia deja de creerle al sistema.
     */
    private function cuandoSeConto(Conteo $conteo): CarbonInterface
    {
        /*
         * ─────────────────────────────────────────────────────────────
         * ⚠️ SE LEE POR EL CAST DE ELOQUENT, NO CON `max()` + parseo
         * ─────────────────────────────────────────────────────────────
         *
         * `max('contado_en')` devuelve el `timestamptz` crudo, y
         * reinterpretarlo a mano —parsearlo y cambiarle la zona—
         * produce una fecha DISTINTA de la que ve el resto del sistema.
         * Se corría un día entero: el ajuste quedaba fechado el 18
         * mientras la línea del conteo decía 19.
         *
         * La causa de fondo es que hoy `APP_TIMEZONE` es Tegucigalpa y la
         * conexión fija la sesión de PostgreSQL en UTC, así que lo que
         * queda guardado es la hora local etiquetada como UTC. Mientras
         * eso siga así, **la única lectura correcta es la misma que hace
         * todo el mundo**: el cast del modelo. Cualquier conversión extra
         * es un módulo interpretando la etiqueta de una forma en la que
         * ningún otro la interpreta — y ahí es donde aparecen dos fechas
         * para el mismo hecho.
         *
         * Está anotado como deuda: arreglar la zona es un cambio de todo
         * el proyecto, con los datos ya escritos adentro, y no se hace de
         * paso en un módulo de inventario.
         */
        $ultima = $conteo->lineas()
            ->whereNotNull('contado_en')
            ->orderByDesc('contado_en')
            ->first();

        if ($ultima instanceof ConteoLinea && $ultima->contado_en instanceof CarbonInterface) {
            return $ultima->contado_en;
        }

        return now();
    }

    private function motivoDelCierre(Conteo $conteo): string
    {
        $descripcion = trim($conteo->descripcion ?? '');

        $base = "Diferencias del conteo físico #{$conteo->id}";

        return $descripcion === '' ? $base : $base.' — '.$descripcion;
    }

    /**
     * Lo que el cierre encontró y no pudo asentar, en una sola nota.
     *
     * @param list<string> $controlados
     * @param list<string> $noCaben
     */
    private function notaDelCierre(array $controlados, array $noCaben): ?string
    {
        $partes = [];

        if ($controlados !== []) {
            $partes[] = 'Quedaron diferencias SIN ajustar en medicamentos controlados, que van '
                .'por el libro con folio y doble firma: '.implode(', ', $controlados).'.';
        }

        if ($noCaben !== []) {
            $partes[] = 'No se asentaron estas diferencias porque la existencia bajó desde que '
                .'se contó y el ajuste dejaría el estante en negativo: '
                .implode(', ', $noCaben).'.';
        }

        return $partes === [] ? null : implode(' ', $partes);
    }
}
