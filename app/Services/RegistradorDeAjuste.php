<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Enums\TipoDeAjuste;
use App\Domain\Exceptions\AjusteException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaAjustada;
use App\Models\Ajuste;
use App\Models\AjusteLinea;
use App\Models\Almacen;
use App\Models\Conteo;
use App\Models\User;
use App\Support\AlmacenesDelUsuario;
use App\Support\UsuarioAutenticado;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * La única puerta por la que se ajusta una existencia.
 *
 * ─────────────────────────────────────────────────────────────────────
 * TRES ESCRITURAS POR LÍNEA, UNA SOLA TRANSACCIÓN
 * ─────────────────────────────────────────────────────────────────────
 *
 * Por cada producto: se asienta el movimiento del kardex, se vuelve a
 * poner `cantidad_base` igual a la existencia real, y se guarda la línea
 * con el costo congelado y el id del movimiento que produjo. Si
 * cualquiera falla, no queda ninguna.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL COSTO NO SE MUEVE. LA CANTIDAD BASE SÍ.
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un ajuste dice *cuántos hay*, no *cuánto valen*: nadie le pagó nada a
 * nadie por las cinco ampollas que aparecieron en el estante. Por eso el
 * promedio ponderado queda intacto.
 *
 * Pero `cantidad_base` —la cantidad contra la que se pondera la próxima
 * compra— tiene que seguir a la existencia real, o el promedio móvil deja
 * de ser móvil. Está explicado con números en
 * `CalculadoraDeCostoPromedio::sincronizarCantidadBase()`.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL TOPE, Y POR QUÉ VA EN LEMPIRAS
 * ─────────────────────────────────────────────────────────────────────
 *
 * Bodega y farmacia ajustan hasta cierto monto sin pedirle permiso a
 * nadie; por encima, el documento exige el nombre de alguien de dirección
 * —y no puede ser el mismo que lo registra—.
 *
 * En unidades el tope no serviría: 500 gasas y 2 ampollas de
 * inmunoglobulina son el mismo número y no son el mismo hecho. Un tope en
 * unidades deja pasar exactamente el ajuste que había que mirar.
 *
 * El monto y los roles que autorizan son configuración (§1.1): otra
 * clínica cambia `config/sihla.php` y no toca una línea de código.
 */
final class RegistradorDeAjuste
{
    public function __construct(
        private readonly RegistradorDeMovimiento $movimientos,
        private readonly CalculadoraDeCostoPromedio $costos,
    ) {}

    /**
     * Asienta un ajuste completo: documento, kardex y costo, o nada.
     *
     * @param list<LineaAjustada> $lineas
     *
     * @throws AjusteException
     */
    public function registrar(
        Almacen $almacen,
        TipoDeAjuste $tipo,
        array $lineas,
        string $motivo,
        ?string $referencia = null,
        ?CarbonInterface $ocurridoEn = null,
        ?User $autorizador = null,
        ?Conteo $conteo = null,
        ?string $notas = null,
        ?string $claveIdempotencia = null,
    ): Ajuste {
        /*
         * Cada quien ajusta el estante del que responde. Va acá y no
         * solo en la pantalla porque un comando o un import llaman
         * directo al servicio (§9.L5).
         *
         * Dirección no tiene restricción, y por eso siempre hay alguien
         * que puede cerrar un conteo de cualquier almacén sin romper el
         * control de cuatro ojos.
         */
        AlmacenesDelUsuario::exigirAcceso($almacen);

        $this->verificar($tipo, $lineas, $motivo, $autorizador, $conteo);

        /*
         * El mismo formulario enviado dos veces trae la misma clave. Se
         * devuelve el ajuste que ya se asentó en vez de asentar otro: dar
         * de baja dos veces un lote vencido de L 8.000 no se puede
         * deshacer, porque todo esto es append-only.
         */
        $yaAsentado = $this->porClave($claveIdempotencia);

        if ($yaAsentado instanceof Ajuste) {
            return $yaAsentado;
        }

        $ocurridoEn ??= now();

        try {
            /** @var Ajuste $ajuste */
            $ajuste = DB::transaction(function () use (
                $almacen,
                $tipo,
                $lineas,
                $motivo,
                $referencia,
                $ocurridoEn,
                $autorizador,
                $conteo,
                $notas,
                $claveIdempotencia,
            ): Ajuste {
                /*
                 * ─────────────────────────────────────────────────────
                 * 🔴 EL ORDEN DE LOS CANDADOS ES CANÓNICO, NO EL DEL
                 * FORMULARIO
                 * ─────────────────────────────────────────────────────
                 *
                 * El candado se toma ANTES de mover nada, sobre la fila
                 * de costo de cada ítem: serializa los ajustes
                 * simultáneos del mismo producto y garantiza que el valor
                 * con el que se mide el tope sea el mismo con el que se
                 * asientan las líneas.
                 *
                 * Pero si el orden fuera el del repeater, dos personas
                 * ajustando los mismos dos productos en distinto orden
                 * —bodega teclea [gasas, heparina] y farmacia [heparina,
                 * gasas]— se bloquean mutuamente y PostgreSQL mata a una
                 * con `deadlock detected`. Ordenar por (ítem, lote) hace
                 * imposible el ciclo: las dos toman los candados en la
                 * misma secuencia y la segunda simplemente espera.
                 *
                 * Con el candado del costo ya tomado, el de `existencias`
                 * del segundo bucle no necesita orden propio: dos
                 * documentos que comparten algún ítem ya quedaron
                 * serializados acá.
                 */

                /** @var array<int, Decimal> $costos */
                $costos = [];

                $total = Decimal::cero();

                foreach (self::enOrdenDeCandado($lineas) as $indice) {
                    $linea = $lineas[$indice];

                    $costo = $this->costos->vigenteBloqueado($linea->item, $almacen);

                    $costos[$indice] = $costo;
                    $total = $total->sumar($linea->valorAl($costo));
                }

                $this->exigirAutorizacionSiPasaElTope($total, $autorizador);

                $ajuste = Ajuste::query()->create([
                    'almacen_id'         => $almacen->id,
                    'conteo_id'          => $conteo?->id,
                    'tipo'               => $tipo,
                    'referencia'         => $referencia,
                    'clave_idempotencia' => $claveIdempotencia,
                    'fecha_operacion'    => $ocurridoEn->toDateString(),
                    'ocurrido_en'        => $ocurridoEn,
                    'motivo'             => trim($motivo),
                    'valor_absoluto'     => $total->paraBase(2),
                    'autorizado_por'     => $autorizador?->id,
                    'autorizado_en'      => $autorizador instanceof User ? now() : null,
                    'notas'              => $notas,
                    'created_by'         => UsuarioAutenticado::id(),
                ]);

                foreach ($lineas as $indice => $linea) {
                    $this->asentar($ajuste, $almacen, $linea, $costos[$indice], $ocurridoEn);
                }

                return $ajuste;
            });
        } catch (QueryException $e) {
            /*
             * El índice único de la clave ganó la carrera contra un envío
             * simultáneo. El catch está FUERA de la transacción —que ya
             * revirtió—, así que consultar acá es seguro.
             */
            $yaAsentado = $this->porClave($claveIdempotencia);

            if ($yaAsentado instanceof Ajuste) {
                return $yaAsentado;
            }

            throw $e;
        }

        return $ajuste->refresh();
    }

    /**
     * El ajuste que ya se asentó con esa clave, si lo hay.
     */
    private function porClave(?string $clave): ?Ajuste
    {
        if ($clave === null || trim($clave) === '') {
            return null;
        }

        return Ajuste::query()->where('clave_idempotencia', $clave)->first();
    }

    /**
     * Los índices de las líneas, ordenados por (ítem, lote).
     *
     * Se ordenan los ÍNDICES y no las líneas: las líneas se asientan
     * después en el orden en que la persona las tecleó, que es el que
     * espera ver en el documento. Lo único que necesita orden canónico es
     * la secuencia en que se toman los candados.
     *
     * @param list<LineaAjustada> $lineas
     *
     * @return list<int>
     */
    private static function enOrdenDeCandado(array $lineas): array
    {
        $indices = array_keys($lineas);

        usort($indices, static function (int $a, int $b) use ($lineas): int {
            $uno = $lineas[$a];
            $otro = $lineas[$b];

            /*
             * `->` y no `?->` aunque `lote` sea nulable: a la izquierda
             * de `??`, PHP evalúa la cadena con semántica de `isset`, así
             * que `$nulo->id ?? 0` devuelve 0 sin error. El nullsafe ahí
             * no agrega seguridad y PHPStan lo marca (`nullsafe.neverNull`).
             */
            return [$uno->item->id, $uno->lote->id ?? 0]
                <=> [$otro->item->id, $otro->lote->id ?? 0];
        });

        return $indices;
    }

    /**
     * Una línea: movimiento del kardex, cantidad base y la fila congelada.
     */
    private function asentar(
        Ajuste $ajuste,
        Almacen $almacen,
        LineaAjustada $linea,
        Decimal $costo,
        CarbonInterface $ocurridoEn,
    ): void {
        $movimiento = $this->movimientos->registrar(
            item: $linea->item,
            lote: $linea->lote,
            almacen: $almacen,
            tipo: $linea->movimiento(),
            cantidad: $linea->cantidad,
            motivo: $linea->motivoParaElKardex(),
            referencia: $ajuste->referencia ?? "Ajuste #{$ajuste->id}",
            ocurridoEn: $ocurridoEn,

            /*
             * Las dos columnas de costo del kardex llevan el MISMO
             * número, y eso no es un descuido: en un ajuste no hay costo
             * propio —no se le compró a nadie— así que se valoriza al
             * promedio vigente, y ese promedio queda igual después del
             * movimiento. Es exactamente lo que dice la migración que
             * agregó esas columnas.
             */
            costoUnitario: $costo,
            costoPromedioDespues: $costo,
        );

        /*
         * DESPUÉS del movimiento, porque lee la existencia ya movida.
         * La fila del costo sigue bloqueada desde `vigenteBloqueado()`,
         * así que nadie la tocó en el medio.
         */
        $this->costos->sincronizarCantidadBase($linea->item, $almacen);

        AjusteLinea::query()->create([
            'ajuste_id'       => $ajuste->id,
            'conteo_linea_id' => $linea->conteoLineaId,
            'movimiento_id'   => $movimiento->id,
            'item_id'         => $linea->item->id,
            'lote_id'         => $linea->lote?->id,
            'motivo'          => $linea->motivo,
            'cantidad'        => $linea->cantidadFirmada()->paraBase(4),
            'costo_unitario'  => $costo->paraBase(6),
            'valor'           => $linea->valorAl($costo)->paraBase(2),
            'texto'           => $linea->texto,
        ]);
    }

    /**
     * Lo que se puede verificar sin tocar la base.
     *
     * @param list<LineaAjustada> $lineas
     *
     * @throws AjusteException
     */
    private function verificar(
        TipoDeAjuste $tipo,
        array $lineas,
        string $motivo,
        ?User $autorizador,
        ?Conteo $conteo,
    ): void {
        if ($lineas === []) {
            throw AjusteException::sinLineas();
        }

        /*
         * El mismo largo mínimo que exige el CHECK del kardex y el de la
         * tabla. Verificarlo acá es lo que convierte un error de SQL sin
         * contexto en una frase que quien registra puede corregir.
         */
        if (mb_strlen(trim($motivo)) < 10) {
            throw AjusteException::faltaElMotivo();
        }

        /*
         * Una diferencia de conteo nace del cierre de un conteo, con la
         * evidencia detrás. Escribirla a mano sería declarar un faltante
         * sin haber contado nada.
         */
        if (! $conteo instanceof Conteo && ! $tipo->seCreaAMano()) {
            throw AjusteException::noSeCreaAMano($tipo->etiqueta());
        }

        foreach ($lineas as $linea) {
            if ($linea->motivo->tipo() !== $tipo) {
                throw AjusteException::elMotivoNoEsDeEseTipo(
                    $linea->motivo->etiqueta(),
                    $tipo->etiqueta(),
                );
            }
        }

        $quien = UsuarioAutenticado::id();

        if ($quien === null) {
            throw AjusteException::faltaQuienAjusta();
        }

        if (! $autorizador instanceof User) {
            return;
        }

        if ($autorizador->id === $quien) {
            throw AjusteException::noSeAutorizaUnoMismo();
        }

        if ($autorizador->hasAnyRole(self::rolesQueAutorizan())) {
            return;
        }

        /*
         * `User` no declara `@property string $name`, así que para el
         * analizador es `mixed`. Se estrecha explícitamente en vez de
         * confiar: es la misma disciplina que `UsuarioAutenticado::id()`.
         */
        $nombre = $autorizador->getAttribute('name');

        throw AjusteException::elAutorizadorNoPuede(
            is_string($nombre) ? $nombre : 'El usuario que elegiste',
        );
    }

    /**
     * @throws AjusteException
     */
    private function exigirAutorizacionSiPasaElTope(Decimal $total, ?User $autorizador): void
    {
        if ($autorizador instanceof User) {
            return;
        }

        $tope = self::tope();

        if (! $total->mayorQue($tope)) {
            return;
        }

        throw AjusteException::exigeAutorizacion($total->redondeado(2), $tope->redondeado(2));
    }

    /**
     * El tope, leído como TEXTO y no como número.
     *
     * `config()` devuelve `mixed`, y un `1000.00` escrito como literal en
     * el archivo es un float — justo lo que el §8.6.2 prohíbe para
     * dinero. Por eso en `config/sihla.php` el valor está entre comillas
     * y acá se lee como cadena: nunca pasa por punto flotante.
     */
    public static function tope(): Decimal
    {
        $valor = config('sihla.inventario.tope_ajuste_sin_autorizacion', '1000.00');

        return Decimal::de(is_string($valor) || is_int($valor) ? $valor : '1000.00');
    }

    /**
     * Qué roles pueden autorizar por encima del tope.
     *
     * Configuración y no una constante en el código: es una regla de
     * negocio, y la clínica siguiente tiene otra estructura de mando
     * (§1.1).
     *
     * @return list<string>
     */
    public static function rolesQueAutorizan(): array
    {
        $roles = config('sihla.inventario.roles_que_autorizan_ajuste', ['direccion']);

        if (! is_array($roles)) {
            return ['direccion'];
        }

        return array_values(array_filter($roles, 'is_string'));
    }
}
