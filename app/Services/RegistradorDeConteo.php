<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Exceptions\ConteoException;
use App\Domain\ValueObjects\Decimal;
use App\Models\Conteo;
use App\Models\ConteoLinea;
use App\Models\Item;
use App\Models\Lote;
use App\Support\AlmacenesDelUsuario;
use App\Support\UsuarioAutenticado;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Teclear lo que se ve en el estante.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL CORTE SE CONGELA ACÁ, NO AL ABRIR EL CONTEO
 * ─────────────────────────────────────────────────────────────────────
 *
 * En el mismo instante en que alguien teclea «hay 95», se guarda cuánto
 * decía el sistema en ese instante. Ese par —lo contado y lo que el
 * sistema creía— es lo que después se convierte en una diferencia que
 * significa algo, aunque el conteo se cierre tres horas más tarde y en el
 * medio la farmacia haya despachado veinte veces.
 *
 * Congelarlo al abrir el documento sería más simple y estaría mal: todo
 * lo que se despache mientras se cuenta aparecería como faltante, y el
 * cierre se lo devolvería al estante.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 EL CANDADO COMPARTIDO SOBRE EL CONTEO
 * ─────────────────────────────────────────────────────────────────────
 *
 * La lectura se registra con `FOR SHARE` sobre la fila del conteo. No es
 * decorativo: el cierre toma `FOR UPDATE` sobre esa misma fila, y sin
 * este candado los dos avanzaban a la vez.
 *
 * El escenario que rompía: 11:00 alguien cierra un conteo de trescientas
 * líneas —transacción larga—; 11:00:02 el auxiliar registra el recuento
 * de una ampolla. En READ COMMITTED, el `SELECT estado` del trigger lee
 * la versión vieja y ve «abierto», así que la lectura entra... pero el
 * cierre ya había leído las líneas y asienta la diferencia VIEJA. La
 * línea queda diciendo −2 y el kardex −7, los dos documentos inmutables,
 * y ni un solo error en pantalla.
 *
 * Con `FOR SHARE`, el que llegue segundo espera al primero. Si el que
 * esperó era la lectura, al despertar ve el conteo cerrado y rebota con
 * un mensaje que se entiende.
 *
 * ─────────────────────────────────────────────────────────────────────
 * CERO SE TECLEA; VACÍO NO ES CERO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Se admite contar cero: es el estante vacío, y es un dato. Lo que no
 * existe es «no lo conté» convertido en cero por omisión — eso lo impide
 * el CHECK de la tabla y lo exige el cierre de un conteo total.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL RECUENTO REEMPLAZA AL CONTEO, Y VUELVE A CONGELAR
 * ─────────────────────────────────────────────────────────────────────
 *
 * Cuando la diferencia pasa la tolerancia, la línea exige que alguien
 * vuelva a contar (§9.G4). El segundo conteo **pisa** al primero, con su
 * propio saldo congelado y su propia hora: es una observación nueva, no
 * un parche sobre la vieja. La primera lectura queda guardada aparte
 * —con su número, su hora y su autor— porque la distancia entre las dos
 * es lo que dice si el problema estaba en el estante o en el que contaba.
 */
final class RegistradorDeConteo
{
    public function __construct(
        private readonly ConsultorDeExistencias $existencias,
    ) {}

    /**
     * @param Decimal $cantidad lo que se ve en el estante; cero es válido
     *
     * @throws ConteoException
     */
    public function contar(
        Conteo $conteo,
        Item $item,
        ?Lote $lote,
        Decimal $cantidad,
        ?string $notas = null,
        ?string $claveDeEnvio = null,
    ): ConteoLinea {
        $conteo->loadMissing('almacen');

        AlmacenesDelUsuario::exigirAcceso($conteo->almacen);

        $this->verificar($conteo, $item, $lote, $cantidad);

        $quien = UsuarioAutenticado::id();

        if ($quien === null) {
            throw ConteoException::faltaQuienCuenta();
        }

        /** @var ConteoLinea $linea */
        $linea = DB::transaction(function () use (
            $conteo,
            $item,
            $lote,
            $cantidad,
            $notas,
            $quien,
            $claveDeEnvio,
        ): ConteoLinea {
            $vigente = $this->conteoBloqueado($conteo);

            $linea = $this->lineaBloqueada($vigente, $item, $lote);

            /*
             * ─────────────────────────────────────────────────────────
             * 🔴 EL DOBLE CLIC NO PUEDE LIBERAR EL RECUENTO OBLIGATORIO
             * ─────────────────────────────────────────────────────────
             *
             * `exige_recuento` se apaga a la segunda lectura. Sin esta
             * guarda, apretar dos veces el mismo botón con el mismo
             * número contaba como «ya lo volví a contar» — y el control
             * de segunda pasada, que existe porque la mayoría de las
             * diferencias grandes son errores de la primera, quedaba
             * satisfecho por un dedo nervioso.
             *
             * El mismo token = la misma acción de la persona. La pantalla
             * lo renueva después de cada registro exitoso, así que una
             * lectura de verdad nueva siempre trae uno distinto.
             *
             * ⚠️ NO se compara contra el reloj. La versión anterior
             * miraba si `contado_en` era de hace pocos segundos, y eso
             * obliga a suponer que el viaje de ida y vuelta de un
             * `timestamptz` conserva la zona horaria — la suposición que
             * el §7.5 prohíbe. Cuando esa suposición falla, el control no
             * avisa: se apaga.
             */
            if ($this->esElMismoEnvio($linea, $claveDeEnvio)) {
                return $linea;
            }

            /*
             * EL CORTE. Se lee acá adentro y no antes: entre construir el
             * objeto y guardarlo puede haber pasado una dispensación, y
             * lo que tiene que quedar congelado es el saldo del momento
             * en que la línea se escribe.
             */
            $sistema = $this->existencias->enElLote($item, $lote, $vigente->almacen);

            $vecesAntes = $linea->veces_contado;

            /*
             * La primera lectura se guarda aparte cuando llega la
             * segunda, y solo entonces: si alguien cuenta cuatro veces,
             * `primer_conteo` sigue siendo la primera.
             */
            if ($linea->estaContada() && $vecesAntes === 1) {
                $linea->primer_conteo = $linea->cantidad_contada;
                $linea->primer_conteo_en = $linea->contado_en;
                $linea->primer_conteo_por = $linea->contado_por;
            }

            $veces = $vecesAntes + 1;

            $linea->cantidad_sistema = $sistema->paraBase(4);
            $linea->cantidad_contada = $cantidad->paraBase(4);
            $linea->contado_en = now();
            $linea->contado_por = $quien;
            $linea->veces_contado = $veces;
            $linea->exige_recuento = $this->exigeRecuento($vigente, $cantidad, $sistema, $veces);
            $linea->ultimo_envio = $claveDeEnvio;

            if ($notas !== null) {
                $linea->notas = $notas;
            }

            $linea->save();

            return $linea;
        });

        return $linea->refresh();
    }

    /**
     * Sacar una línea del conteo — solo si todavía no se contó.
     *
     * Una línea ya contada no se borra: se vuelve a contar. Borrarla
     * dejaría el conteo diciendo que ese producto nunca estuvo en la
     * planilla, que es exactamente lo que un faltante querría que dijera.
     *
     * @throws ConteoException
     */
    public function quitar(ConteoLinea $linea): void
    {
        $linea->loadMissing(['conteo', 'conteo.almacen']);

        $conteo = $linea->conteo;

        if (! $conteo->estaAbierto()) {
            throw ConteoException::noEstaAbierto($conteo->estado->etiqueta());
        }

        AlmacenesDelUsuario::exigirAcceso($conteo->almacen);

        /*
         * Lanza en vez de devolver en silencio: quien apretó «quitar»
         * sobre una línea ya contada tiene que enterarse de por qué no
         * pasó nada, y de cuál es el camino correcto.
         */
        if ($linea->estaContada()) {
            throw ConteoException::laLineaYaSeConto();
        }

        $linea->delete();
    }

    /**
     * La fila del conteo, bloqueada en modo compartido.
     *
     * `FOR SHARE` y no `FOR UPDATE`: dos personas contando a la vez no
     * tienen por qué esperarse entre ellas. Lo que sí tiene que esperar
     * —o hacer esperar— es el cierre, que toma `FOR UPDATE`.
     *
     * @throws ConteoException
     */
    private function conteoBloqueado(Conteo $conteo): Conteo
    {
        $vigente = Conteo::query()
            ->with('almacen')
            ->whereKey($conteo->id)
            ->sharedLock()
            ->first();

        if (! $vigente instanceof Conteo) {
            throw ConteoException::noEstaAbierto('borrado');
        }

        if (! $vigente->estaAbierto()) {
            throw ConteoException::noEstaAbierto($vigente->estado->etiqueta());
        }

        return $vigente;
    }

    /**
     * ¿Esta diferencia es lo bastante grande como para volver a contar?
     *
     * Solo la primera lectura lo exige. La segunda cierra el asunto: si a
     * la segunda sigue sin cuadrar, la diferencia es real y lo que hace
     * falta no es contar otra vez sino investigar — y para eso el ajuste
     * queda asentado, con nombre y motivo.
     */
    private function exigeRecuento(
        Conteo $conteo,
        Decimal $contada,
        Decimal $sistema,
        int $veces,
    ): bool {
        if ($veces >= 2) {
            return false;
        }

        $diferencia = $contada->restar($sistema);

        /*
         * En valor absoluto: sobrar veinte ampollas es tan digno de una
         * segunda mirada como que falten veinte.
         */
        if ($diferencia->esNegativo()) {
            $diferencia = $diferencia->por('-1');
        }

        return $diferencia->mayorQue($conteo->toleranciaDecimal());
    }

    /**
     * ¿Es el mismo envío otra vez, o de verdad volvieron a contar?
     *
     * Sin token —una lectura que viene de un comando o de un import— no
     * hay doble clic posible, así que no hay nada que proteger.
     */
    private function esElMismoEnvio(ConteoLinea $linea, ?string $claveDeEnvio): bool
    {
        if ($claveDeEnvio === null || trim($claveDeEnvio) === '') {
            return false;
        }

        return $linea->estaContada() && $linea->ultimo_envio === $claveDeEnvio;
    }

    /**
     * La línea de ese producto y ese lote, bloqueada, creándola si no
     * estaba.
     *
     * Que no esté es un caso normal y hasta deseable: aparece en el
     * estante algo que el sistema daba en cero, o el conteo es parcial y
     * las líneas nacen a medida que se escanea.
     *
     * ⚠️ `insertOrIgnore` y no `create()` con try/catch. Esto corre
     * DENTRO de una transacción, y en PostgreSQL un `INSERT` que revienta
     * contra un índice único aborta la transacción entera: el `catch` no
     * podría ni consultar. `ON CONFLICT DO NOTHING` no lanza y no aborta.
     *
     * @throws ConteoException
     */
    private function lineaBloqueada(Conteo $conteo, Item $item, ?Lote $lote): ConteoLinea
    {
        $buscar = fn (): ?ConteoLinea => ConteoLinea::query()
            ->where('conteo_id', $conteo->id)
            ->where('item_id', $item->id)
            ->where(fn (Builder $sub): Builder => $lote instanceof Lote
                ? $sub->where('lote_id', $lote->id)
                : $sub->whereNull('lote_id'))
            ->lockForUpdate()
            ->first();

        $linea = $buscar();

        if ($linea instanceof ConteoLinea) {
            return $linea;
        }

        DB::table('conteo_lineas')->insertOrIgnore([
            'conteo_id' => $conteo->id,
            'item_id'   => $item->id,
            'lote_id'   => $lote?->id,

            /*
             * Explícitos aunque la base los tenga por defecto: §9.C6 —
             * los defaults de PostgreSQL no llegan al modelo en memoria,
             * y acá `veces_contado` se incrementa enseguida.
             */
            'veces_contado'  => 0,
            'exige_recuento' => false,
            'ultimo_envio'   => null,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $linea = $buscar();

        if ($linea instanceof ConteoLinea) {
            return $linea;
        }

        throw ConteoException::laLineaDesaparecio();
    }

    /**
     * @throws ConteoException
     */
    private function verificar(Conteo $conteo, Item $item, ?Lote $lote, Decimal $cantidad): void
    {
        if (! $conteo->estaAbierto()) {
            throw ConteoException::noEstaAbierto($conteo->estado->etiqueta());
        }

        if ($cantidad->esNegativo()) {
            throw ConteoException::laCantidadNoPuedeSerNegativa($item->etiqueta());
        }

        if ($item->requiere_lote && ! $lote instanceof Lote) {
            throw ConteoException::faltaElLote($item->etiqueta());
        }

        if ($lote instanceof Lote && $lote->item_id !== $item->id) {
            throw ConteoException::elLoteNoEsDelItem($item->etiqueta(), $lote->numero);
        }
    }
}
