<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\GuardaEnMayusculas;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\ItemPresentacionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * Presentación de compra de un ítem.
 *
 * "CAJA X 100 AMPOLLAS" es una fila de acá: unidad CAJA, contenido 100
 * ampollas. El kardex del ítem sigue llevándose en ampollas.
 *
 * @property int $id
 * @property int $item_id
 * @property int $unidad_id
 * @property string $nombre
 * @property string $unidades_por_presentacion
 * @property string|null $codigo_barras
 * @property bool $es_predeterminada
 * @property CarbonInterface $vigencia_desde
 * @property CarbonInterface|null $vigencia_hasta
 */
class ItemPresentacion extends Model
{
    use GuardaEnMayusculas;
    use HasAuditFields;

    /** @use HasFactory<ItemPresentacionFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * Cuántos sufijos se prueban antes de rendirse al proponer un código
     * del hospital. Noventa y nueve presentaciones del mismo producto no
     * existen; el tope está para que el ciclo termine siempre, no porque
     * alguien vaya a llegar.
     */
    private const SUFIJOS_POSIBLES = 99;

    protected $table = 'item_presentaciones';

    /** @var list<string> */
    protected $fillable = [
        'item_id',
        'unidad_id',
        'nombre',
        'unidades_por_presentacion',
        'codigo_barras',
        'es_predeterminada',
        'vigencia_desde',
        'vigencia_hasta',
    ];

    /**
     * El código de barras NO se canoniza. Es una cadena que tiene que
     * coincidir carácter por carácter con lo que lee el escáner; algunos
     * GS1 llevan minúsculas y tocarlas rompe la lectura.
     *
     * @return array<int, string>
     */
    public static function camposEnMayusculas(): array
    {
        return ['nombre'];
    }

    /**
     * Una sola presentación habitual por ítem.
     *
     * ─────────────────────────────────────────────────────────────────
     * POR QUÉ SE DESMARCA LA ANTERIOR EN VEZ DE RECHAZAR
     * ─────────────────────────────────────────────────────────────────
     *
     * La base tiene un índice único parcial que impide dos marcadas a la
     * vez, y tiene que quedarse: con dos, la que gana depende del ORDER
     * BY. Pero sin esto, marcar una segunda termina en un error de SQL
     * crudo en la cara de quien carga el catálogo, cuando lo que quiso
     * decir es evidente — «ahora compramos en caja de 50».
     *
     * Va en el modelo y no en el formulario por lo de siempre: la
     * pantalla no es la única puerta. Un import del catálogo del sistema
     * anterior escribe directo.
     *
     * ⚠️ El desmarcado ocurre ANTES del guardado, así que si el guardado
     * falla después, el ítem queda sin presentación habitual. Es un
     * estado que el sistema tolera —el formulario de compra simplemente
     * no propone ninguna— y que el siguiente guardado corrige. La
     * alternativa era el error de SQL, que es peor.
     */
    protected static function booted(): void
    {
        /*
         * ─────────────────────────────────────────────────────────────
         * LA PRIMERA PRESENTACIÓN ES LA HABITUAL, SIN PREGUNTARLO
         * ─────────────────────────────────────────────────────────────
         *
         * El formulario dejó de tener el interruptor: con una sola
         * presentación —que es el caso de casi todos los productos— la
         * respuesta correcta es una sola, y preguntarla es un trámite.
         *
         * Sin esto, `presentacionPredeterminada()` devolvería null y el
         * formulario de compra abriría sin proponer nada. Cambiarla
         * cuando hay varias es la acción «Marcar habitual» del listado.
         */
        static::creating(function (ItemPresentacion $presentacion): void {
            if ($presentacion->es_predeterminada) {
                return;
            }

            $presentacion->es_predeterminada = ! static::query()
                ->where('item_id', $presentacion->item_id)
                ->exists();
        });

        static::saving(function (ItemPresentacion $presentacion): void {
            /*
             * Un código de barras vacío es NULL y no cadena vacía. Con
             * cadena vacía el índice único deja pasar una sola —la
             * segunda presentación sin código explotaría con un error de
             * SQL— y además `where('codigo_barras', '')` encontraría algo
             * cuando el lector manda ruido.
             */
            if (is_string($presentacion->codigo_barras) && trim($presentacion->codigo_barras) === '') {
                $presentacion->codigo_barras = null;
            }

            if (! $presentacion->es_predeterminada) {
                return;
            }

            /*
             * `update()` masivo a propósito: no dispara eventos de
             * modelo, así que no se llama a sí mismo.
             */
            static::query()
                ->where('item_id', $presentacion->item_id)
                ->where('es_predeterminada', true)
                ->when(
                    $presentacion->exists,
                    fn (Builder $consulta): Builder => $consulta->whereKeyNot($presentacion->getKey()),
                )
                ->update(['es_predeterminada' => false]);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'es_predeterminada' => 'boolean',
            'vigencia_desde'    => 'date',
            'vigencia_hasta'    => 'date',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<Unidad, $this>
     */
    public function unidad(): BelongsTo
    {
        return $this->belongsTo(Unidad::class);
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * EL CÓDIGO QUE EL HOSPITAL LE PROPONE A UNA PRESENTACIÓN
     * ─────────────────────────────────────────────────────────────────
     *
     * Sale del código del ítem más un sufijo de dos dígitos —`MED-0708-01`,
     * `MED-0708-02`— y no de un contador global. Es a propósito: quien
     * tiene la caja en la mano sabe de qué producto es leyendo la
     * etiqueta, sin escáner y sin sistema. El día que el servidor no
     * esté, eso es lo único que hay.
     *
     * ⚠️ Cuenta también las BORRADAS. Una presentación se da de baja del
     * sistema, pero la etiqueta impresa sigue pegada en una caja del
     * estante: si su código se reasignara, esa caja pasaría a escanear
     * como otra cosa. Un código impreso no se recicla nunca.
     *
     * Devuelve null cuando el ítem no tiene código utilizable o cuando
     * los noventa y nueve sufijos están tomados. Las dos cosas se
     * resuelven escribiendo el código a mano, así que quien llama tiene
     * que decirlo y no callarlo.
     */
    public static function codigoSugeridoPara(Item $item): ?string
    {
        $base = trim($item->codigo);

        if ($base === '') {
            return null;
        }

        for ($n = 1; $n <= self::SUFIJOS_POSIBLES; $n++) {
            $candidato = $base.'-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT);

            $tomado = static::withTrashed()
                ->where('codigo_barras', $candidato)
                ->exists();

            if (! $tomado) {
                return $candidato;
            }
        }

        return null;
    }

    /**
     * Convierte una cantidad comprada en esta presentación a unidades de
     * dispensación, que es la única unidad en la que se mueve el kardex.
     *
     * ⚠️ Devuelve string y opera con bcmath, no con float. §8.6.2 lo
     * prohíbe: 3 cajas × 0.1 ml en punto flotante no da 0.3, y ese error
     * se acumula movimiento tras movimiento hasta que el inventario deja
     * de cuadrar con contabilidad.
     *
     * @param numeric-string $cantidad
     *
     * @return numeric-string
     */
    public function aUnidadesDeDispensacion(string $cantidad): string
    {
        return bcmul($cantidad, $this->contenido(), 4);
    }

    /**
     * Y al revés: cuántas presentaciones representa una cantidad en
     * unidades de dispensación. Sirve para mostrar "1.5 cajas" en el
     * reporte de compras, nunca para escribir en el kardex.
     *
     * @param numeric-string $cantidad
     *
     * @return numeric-string
     */
    public function desdeUnidadesDeDispensacion(string $cantidad): string
    {
        return bcdiv($cantidad, $this->contenido(), 4);
    }

    /**
     * El contenido de la presentación, garantizado numérico.
     *
     * La columna es `NUMERIC(14,4) NOT NULL` con un CHECK de mayor que
     * cero, así que en la práctica siempre lo es. La verificación existe
     * igual por dos razones:
     *
     *  1. `numeric-string` es una promesa que el analizador no puede
     *     comprobar solo sobre un atributo de Eloquent — sin esto, la
     *     alternativa era declararlo por docblock, que es afirmarlo sin
     *     respaldo.
     *  2. Si alguien cambia el tipo de la columna, esto avisa. bcmath con
     *     un operando no numérico no explota: lo trata como cero, y una
     *     conversión que devuelve cero en silencio es un faltante de
     *     inventario que aparece meses después en el conteo físico.
     *
     * @return numeric-string
     */
    private function contenido(): string
    {
        $valor = $this->unidades_por_presentacion;

        if (! is_numeric($valor)) {
            throw new RuntimeException(
                "La presentación {$this->id} tiene un contenido no numérico: «{$valor}»."
            );
        }

        return $valor;
    }

    /**
     * @param Builder<ItemPresentacion> $consulta
     *
     * @return Builder<ItemPresentacion>
     */
    public function scopeVigentesEn(Builder $consulta, CarbonInterface $fecha): Builder
    {
        $dia = $fecha->toDateString();

        return $consulta
            ->whereDate('vigencia_desde', '<=', $dia)
            ->where(function (Builder $sub) use ($dia): void {
                $sub->whereNull('vigencia_hasta')
                    ->orWhereDate('vigencia_hasta', '>=', $dia);
            });
    }

    /**
     * La frase que dice lo mismo que los tres campos juntos: «1 CAJA =
     * 100 TABLETA».
     *
     * ─────────────────────────────────────────────────────────────────
     * PARA QUÉ SIRVE UNA FRASE QUE REPITE LO QUE YA ESTÁ EN PANTALLA
     * ─────────────────────────────────────────────────────────────────
     *
     * Para que el error más caro de esta pantalla se vea antes de
     * guardar. «Unidad del envase: CAJA» y «Cuánto trae: 100» son dos
     * campos separados que se pueden llenar bien cada uno y significar
     * un disparate juntos —una CAJA que trae 100 CAJA— y eso no da
     * ningún error: entra al kardex y recién se descubre en el primer
     * conteo físico, con meses de movimientos encima.
     *
     * Leído como oración, el disparate salta.
     */
    public function comoSeLee(): string
    {
        $envase = $this->unidad;
        $item = $this->item;
        $dispensacion = $item instanceof Item ? $item->unidadDispensacion : null;

        return sprintf(
            '1 %s = %s %s',
            $envase instanceof Unidad ? $envase->codigo : '?',
            self::sinCerosDeMas($this->unidades_por_presentacion),
            $dispensacion instanceof Unidad ? $dispensacion->codigo : 'unidades',
        );
    }

    /**
     * Cómo viene envasado, en una frase: «CAJA X 100 TABLETA», «FRASCO X
     * 120 ML», «BLÍSTER X 12 TABLETA».
     *
     * ─────────────────────────────────────────────────────────────────
     * ES LA MITAD DEL NOMBRE DE TODA PRESENTACIÓN
     * ─────────────────────────────────────────────────────────────────
     *
     * El formulario arma el nombre como «PRODUCTO / esto», así que la
     * regla vive acá y no en la pantalla: el nombre que se guarda tiene
     * que salir igual venga de donde venga —del modal, de un import del
     * catálogo viejo, de un comando—.
     *
     * ⚠️ Lleva el ENVASE y no solo la cantidad. «100 TABLETA» no
     * distingue la caja de 100 de la bolsa de 100, y son dos filas
     * distintas que se compran distinto: dos presentaciones con el mismo
     * nombre en el desplegable de la compra es donde alguien elige la
     * que no era.
     *
     * ⚠️ El caso especial es el envase que ES la unidad y trae una sola:
     * una ampolla que se dispensa por ampolla saldría «AMPOLLA X 1
     * AMPOLLA». Ahí la frase es simplemente «AMPOLLA».
     */
    public static function comoSeEnvasa(string $envase, string $cantidad, string $dispensacion): string
    {
        if ($cantidad === '1' && $envase === $dispensacion) {
            return $envase;
        }

        return $envase.' X '.$cantidad.' '.$dispensacion;
    }

    /**
     * «100.0000» → «100», «0.5000» → «0.5».
     *
     * ⚠️ Solo si hay punto decimal. `rtrim('100', '0')` devuelve «1», y
     * ese error no da ninguna señal: imprime una etiqueta que dice que la
     * caja trae una tableta cuando trae cien. La columna es NUMERIC(14,4)
     * y siempre trae decimales, pero esto mismo se llama con lo que hay
     * escrito en el formulario, donde alguien acaba de teclear «100».
     */
    public static function sinCerosDeMas(string $numero): string
    {
        if (! str_contains($numero, '.')) {
            return $numero === '' ? '0' : $numero;
        }

        $limpio = rtrim(rtrim($numero, '0'), '.');

        return $limpio === '' ? '0' : $limpio;
    }

    public function etiqueta(): string
    {
        return $this->nombre;
    }
}
