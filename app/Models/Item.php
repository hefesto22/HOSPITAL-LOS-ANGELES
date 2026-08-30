<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\AmbitoCatalogo;
use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\PoliticaCargo;
use App\Domain\Enums\RegimenIsv;
use App\Domain\Enums\TipoItem;
use App\Domain\ValueObjects\Monto;
use App\Models\Concerns\GuardaEnMayusculas;
use App\Support\CatalogoDelRol;
use App\Support\NormalizadorDeTexto;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ítem del catálogo único (§8.4, ADR-0003).
 *
 * Este modelo NO sabe cuánto cuesta nada. El precio es
 * `precio(item, convenio, fecha_servicio, sede)` resuelto por vigencia
 * (§8.5) y vive en su propia tabla; el costo promedio vive en el kardex,
 * por sede y almacén. Un getter `precio` acá sería la puerta por la que
 * entra el precio-columna que el §9.H2 prohíbe.
 *
 * @property int $id
 * @property string $codigo
 * @property string $nombre
 * @property string|null $descripcion
 * @property string $nombre_busqueda
 * @property TipoItem $tipo
 * @property bool $se_almacena
 * @property bool $factura_envase_entero
 * @property int|null $categoria_id
 * @property AmbitoCatalogo|null $categoria_ambito
 * @property RegimenIsv $regimen_isv
 * @property string|null $costo_referencia
 * @property PoliticaCargo $politica_cargo
 * @property CategoriaLegalDeDescuento $categoria_legal_descuento
 * @property int|null $unidad_dispensacion_id
 * @property bool $fraccionable
 * @property int|null $unidad_fraccion_id
 * @property string|null $fracciones_por_unidad
 * @property int|null $horas_caducidad_post_apertura
 * @property bool $requiere_lote
 * @property bool $requiere_receta
 * @property bool $es_controlado
 * @property string|null $principio_activo
 * @property string|null $registro_arsa
 * @property string|null $presentacion_comercial
 * @property string|null $codigo_cie10
 * @property string|null $codigo_loinc
 * @property string|null $codigo_atc
 * @property string|null $version_codificacion
 * @property string|null $cuenta_contable
 * @property string|null $centro_de_costo
 * @property CarbonInterface $vigencia_desde
 * @property CarbonInterface|null $vigencia_hasta
 */
class Item extends Model
{
    use GuardaEnMayusculas;
    use HasAuditFields;

    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'items';

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'tipo',
        'se_almacena',
        'factura_envase_entero',
        'categoria_id',
        'regimen_isv',
        'costo_referencia',
        'politica_cargo',
        'categoria_legal_descuento',
        'unidad_dispensacion_id',
        'fraccionable',
        'unidad_fraccion_id',
        'fracciones_por_unidad',
        'horas_caducidad_post_apertura',
        'requiere_lote',
        'requiere_receta',
        'es_controlado',
        'principio_activo',
        'registro_arsa',
        'presentacion_comercial',
        'codigo_cie10',
        'codigo_loinc',
        'codigo_atc',
        'version_codificacion',
        'cuenta_contable',
        'centro_de_costo',
        'vigencia_desde',
        'vigencia_hasta',
    ];

    /**
     * Los códigos estándar quedan FUERA a propósito.
     *
     * LOINC distingue mayúsculas en algunos campos y CIE-10 usa letra
     * mayúscula con dígitos ("J18.9"), así que pasarlos por el
     * canonizador es inofensivo hoy y una bomba el día que se cargue un
     * catálogo real. No se tocan: se guardan tal como los publica quien
     * los mantiene.
     *
     * @return array<int, string>
     */
    public static function camposEnMayusculas(): array
    {
        return [
            'codigo',
            'nombre',
            'principio_activo',
            'presentacion_comercial',
        ];
    }

    /**
     * Coherencia derivada del tipo, aplicada en TODA escritura.
     *
     * ─────────────────────────────────────────────────────────────────
     * POR QUÉ ACÁ Y NO EN EL FORMULARIO
     * ─────────────────────────────────────────────────────────────────
     *
     * La base tiene CHECKs que rechazan un honorario con lote o un
     * fraccionable sin unidad de fracción. Son el backstop y tienen que
     * quedarse. Pero si lo único que los evita es el formulario, hay dos
     * caminos que igual llegan a la base rechazando:
     *
     *   · en la pantalla, al cambiar el tipo de un medicamento a
     *     servicio, la pestaña de farmacia se oculta y sus campos no se
     *     envían — así que el valor VIEJO sigue en el modelo;
     *   · un import de catálogo del sistema anterior no pasa por ninguna
     *     pestaña.
     *
     * Los dos terminan en un error de SQL crudo en la cara del usuario.
     * Esto no es "arreglar el dato en silencio": las tres banderas son
     * DERIVADAS del tipo —un honorario no tiene lote, no es controlado y
     * no se fracciona— así que ponerlas en falso no pierde información
     * que alguien haya querido guardar.
     */
    protected static function booted(): void
    {
        static::saving(function (Item $item): void {
            /*
             * ─────────────────────────────────────────────────────────
             * EL TIPO PROPONE, LA COLUMNA DECIDE
             * ─────────────────────────────────────────────────────────
             *
             * `se_almacena` es una respuesta, no una deducción: hay
             * insumos que el hospital compra y consume sin inventariar,
             * y hasta hoy eso era imposible de declarar.
             *
             * Pero si nadie la contestó —un seeder, un import, una
             * factory de una prueba vieja— se toma la del tipo, que es
             * la que regía antes de que la columna existiera. Sin esto,
             * todo lo que ya estaba escrito pasaría a no mover kardex de
             * un día para el otro, en silencio.
             */
            if (! array_key_exists('se_almacena', $item->getAttributes())) {
                $item->se_almacena = $item->tipo->mueveInventario();
            }

            /*
             * ─────────────────────────────────────────────────────────
             * 🔴 EL NUMERAL DEL ART. 30 YA NO SE PREGUNTA: SE DEDUCE
             * ─────────────────────────────────────────────────────────
             *
             * El formulario del catálogo dejó de tener el selector de
             * categoría legal: mostraba porcentajes de adulto mayor al
             * lado de los descuentos del hospital y parecían lo mismo.
             *
             * Pero la columna sigue existiendo y sigue siendo NOT NULL,
             * porque de ella sale el descuento del Artículo 30 que se
             * aplica solo — el que no depende de que alguien se acuerde
             * de marcar nada. Sin este bloque, el primer ítem creado
             * desde la pantalla nueva reventaría contra la base.
             *
             * ⚠️ Se deduce SOLO si nadie la contestó. Los seeders y las
             * pruebas la escriben a mano y esos valores mandan: hay
             * honorarios que son consulta especializada y no general, y
             * el tipo no alcanza para distinguirlos. Pisarlos acá les
             * bajaría el descuento del 30 % al 25 % en silencio.
             *
             * Y por eso mismo cambiar el tipo NO la recalcula: la fila
             * ya tiene su categoría escrita, así que la clave existe en
             * los atributos y este bloque no la toca.
             */
            /*
             * ─────────────────────────────────────────────────────────
             * EXENTO POR DEFECTO — CONFIRMADO POR EL CONTADOR DEL
             * HOSPITAL (20-ago-2026)
             * ─────────────────────────────────────────────────────────
             *
             * Casi todo lo que factura un hospital privado hondureño es
             * exento por el Art. 15 inciso d de la Ley del ISV: consulta,
             * hospitalización, laboratorio, imagen, medicamentos. Lo
             * gravado es la excepción —estética, cafetería, parqueo— y
             * se marca a mano, que es justo el orden correcto: la
             * excepción cuesta un clic, la regla no cuesta ninguno.
             *
             * El formulario ya arranca en «Exento». Esto cubre el otro
             * camino: un import del sistema viejo o un comando de
             * consola que no traiga la columna. Sin esto reventaría
             * contra un NOT NULL, y quien lo arregle rápido va a poner
             * cualquier cosa.
             *
             * ⚠️ Solo si NADIE contestó. Un seeder que escribe
             * `Gravado15` a propósito manda: pisarlo acá le borraría el
             * impuesto a lo único que sí lo paga.
             */
            if (! array_key_exists('regimen_isv', $item->getAttributes())) {
                $item->regimen_isv = RegimenIsv::Exento;
            }

            if (! array_key_exists('categoria_legal_descuento', $item->getAttributes())) {
                $item->categoria_legal_descuento = CategoriaLegalDeDescuento::sugeridaPara($item->tipo);
            }

            if (! $item->mueveInventario()) {
                $item->requiere_lote = false;
                $item->es_controlado = false;
                $item->fraccionable = false;
            }

            /*
             * Un controlado exige receta siempre. Es requisito ante ARSA,
             * no preferencia: la base también lo obliga.
             */
            if ($item->es_controlado) {
                $item->requiere_receta = true;
            }

            if (! $item->fraccionable) {
                $item->unidad_fraccion_id = null;
                $item->fracciones_por_unidad = null;
            }

            /*
             * ─────────────────────────────────────────────────────────
             * 🔴 EL ÁMBITO DE LA CATEGORÍA NO SE PREGUNTA: SE DERIVA
             * ─────────────────────────────────────────────────────────
             *
             * `items.categoria_ambito` es una columna redundante que
             * existe SOLO para que la base pueda verificar, con una FK
             * compuesta contra `categorias_item (id, ambito)`, que un
             * producto de farmacia no quede archivado bajo «Rayos X».
             *
             * Ningún formulario la escribe —sería un campo que pide
             * repetir algo que el sistema ya sabe, y que alguna vez se
             * llenaría mal—. Se copia de `se_almacena`, que a esta
             * altura del closure ya está resuelto.
             *
             * Si la categoría elegida es del otro lado, el INSERT falla
             * contra la FK. Eso es lo buscado: es el único momento en
             * que se puede atajar.
             */
            $item->categoria_ambito = $item->categoria_id === null
                ? null
                : AmbitoCatalogo::deSeAlmacena((bool) $item->se_almacena);
        });
    }

    /**
     * ¿Este servicio lo presta alguien de afuera?
     *
     * Se deriva del costo de referencia y no de una bandera aparte: si
     * hay un tercero cobrando, hay un número; si no lo hay, no. Dos
     * campos para decir lo mismo terminan contradiciéndose el día que
     * alguien llena uno y se olvida del otro.
     */
    public function loPrestaUnTercero(): bool
    {
        return $this->costo_referencia !== null;
    }

    /**
     * Lo que le queda al hospital por cada uno de estos, contra un precio
     * dado. Nulo cuando el servicio se hace adentro: ahí no hay
     * intermediación que medir, todo el precio es del hospital.
     */
    public function margenSobreElTercero(Monto $precio): ?Monto
    {
        if ($this->costo_referencia === null) {
            return null;
        }

        return $precio->restar(Monto::de($this->costo_referencia));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo'                      => TipoItem::class,
            'se_almacena'               => 'boolean',
            'factura_envase_entero'     => 'boolean',
            'categoria_ambito'          => AmbitoCatalogo::class,
            'regimen_isv'               => RegimenIsv::class,
            'politica_cargo'            => PoliticaCargo::class,
            'categoria_legal_descuento' => CategoriaLegalDeDescuento::class,
            'fraccionable'              => 'boolean',
            'requiere_lote'             => 'boolean',
            'requiere_receta'           => 'boolean',
            'es_controlado'             => 'boolean',
            'vigencia_desde'            => 'date',
            'vigencia_hasta'            => 'date',
        ];
    }

    // ── Relaciones ────────────────────────────────────────────────────

    /**
    /**
     * La hoja del tarifario en la que vive este ítem.
     *
     * Nullable porque el catálogo existía antes que las categorías: los
     * ítems ya cargados se clasifican con `CategoriasDelCatalogoSeeder`
     * y el formulario la exige de acá en adelante. Dejarla obligatoria
     * en la base habría impedido correr la migración con datos vivos.
     *
     * @return BelongsTo<CategoriaItem, $this>
     */
    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaItem::class, 'categoria_id');
    }

    /**
     * @return BelongsTo<Unidad, $this>
     */
    public function unidadDispensacion(): BelongsTo
    {
        return $this->belongsTo(Unidad::class, 'unidad_dispensacion_id');
    }

    /**
     * @return BelongsTo<Unidad, $this>
     */
    public function unidadFraccion(): BelongsTo
    {
        return $this->belongsTo(Unidad::class, 'unidad_fraccion_id');
    }

    /**
     * @return HasMany<ItemPresentacion, $this>
     */
    public function presentaciones(): HasMany
    {
        return $this->hasMany(ItemPresentacion::class);
    }

    /**
     * Todas las filas de tarifario del ítem: el precio de lista y los
     * negociados con cada pagador, vigentes y vencidos.
     *
     * Los vencidos también, y a propósito: son la explicación de las
     * facturas de ayer. Filtrar por fecha es trabajo de quien pregunta
     * por una fecha, no de la relación.
     *
     * @return HasMany<Tarifario, $this>
     */
    public function precios(): HasMany
    {
        return $this->hasMany(Tarifario::class);
    }

    /**
     * Los lotes de este ítem: mismo producto, distintos vencimientos.
     *
     * Veinte cajas que vencen en dos fechas son un ítem con dos lotes, no
     * dos ítems. Ver el encabezado de la migración de `lotes`.
     *
     * @return HasMany<Lote, $this>
     */
    public function lotes(): HasMany
    {
        return $this->hasMany(Lote::class);
    }

    /**
     * Cuánto hay, por lote y por almacén.
     *
     * @return HasMany<Existencia, $this>
     */
    public function existencias(): HasMany
    {
        return $this->hasMany(Existencia::class);
    }

    /**
     * Los descuentos del catálogo del hospital que se le marcaron.
     *
     * ⚠️ Devuelve las filas TAL COMO quedaron marcadas, incluidas las que
     * ya vencieron. Es a propósito: es lo que la pantalla muestra y lo
     * que Filament sincroniza al guardar.
     *
     * 🔴 Para saber qué descuento le toca a un paciente NO se usa esta
     * relación: se usa `ResolutorDeDescuentoLegal`, que resuelve por
     * NOMBRE contra la fecha del servicio. El pivote guarda el `id` que
     * estaba vigente el día que alguien marcó la casilla, y ese id se
     * queda viejo en cuanto cambia el porcentaje. Ver el encabezado de
     * `Descuento`.
     *
     * @return BelongsToMany<Descuento, $this>
     */
    public function descuentos(): BelongsToMany
    {
        return $this->belongsToMany(Descuento::class, 'descuento_item')->withTimestamps();
    }

    /**
     * La presentación que propone el formulario de compra.
     */
    public function presentacionPredeterminada(): ?ItemPresentacion
    {
        return $this->presentaciones()
            ->where('es_predeterminada', true)
            ->first();
    }

    // ── Vigencia ──────────────────────────────────────────────────────

    /**
     * ¿Se ofrecía en esta fecha?
     *
     * La pregunta se hace SIEMPRE contra la fecha del servicio, nunca
     * contra "hoy": un cargo de marzo se explica con el catálogo de
     * marzo. Por eso el parámetro es obligatorio.
     */
    public function vigenteEn(CarbonInterface $fecha): bool
    {
        if ($this->vigencia_desde->startOfDay()->greaterThan($fecha)) {
            return false;
        }

        return $this->vigencia_hasta === null
            || $this->vigencia_hasta->endOfDay()->greaterThanOrEqualTo($fecha);
    }

    /**
     * Ítems vigentes en una fecha dada.
     *
     * @param Builder<Item> $consulta
     *
     * @return Builder<Item>
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

    // ── Búsqueda ──────────────────────────────────────────────────────

    /**
     * Filtro tolerante por código, nombre o principio activo.
     *
     * `%>` es word_similarity: compara el término contra las PALABRAS del
     * texto, no contra el texto entero. Es lo que hace que "amoxi"
     * encuentre "AMOXICILINA 500 MG CÁPSULA" — con `%` a secas la
     * similitud global de una palabra corta contra una descripción larga
     * queda por debajo del umbral y no devuelve nada.
     *
     * El `OR ILIKE` sobre el código es a propósito: quien teclea
     * "MED-0012" espera coincidencia exacta, y los trigramas de un código
     * con guiones son ruido.
     *
     * @param Builder<Item> $consulta
     *
     * @return Builder<Item>
     */
    public function scopeBuscar(Builder $consulta, string $termino): Builder
    {
        $clave = NormalizadorDeTexto::clave($termino);

        if ($clave === '') {
            return $consulta;
        }

        $escaneado = trim($termino);

        return $consulta->where(function (Builder $sub) use ($clave, $escaneado): void {
            $sub->whereRaw('nombre_busqueda %> ?', [$clave])
                ->orWhereRaw('nombre_busqueda % ?', [$clave])
                ->orWhere('codigo', 'ilike', '%'.$clave.'%');

            /*
             * ─────────────────────────────────────────────────────────
             * ESCANEAR TAMBIÉN ENCUENTRA
             * ─────────────────────────────────────────────────────────
             *
             * Dos códigos de barras distintos llevan al mismo producto:
             * el del HOSPITAL —que es el código interno impreso en la
             * etiqueta del reenvasado, y ya lo encuentra el `ilike` de
             * arriba— y el del FABRICANTE, que viene en la caja del
             * proveedor y vive en la presentación de compra.
             *
             * ⚠️ El del fabricante se compara EXACTO y sin canonizar. Un
             * `%like%` sobre un EAN devolvería el producto equivocado
             * —los códigos comparten prefijos de país y de empresa— y
             * pasarlo por el normalizador de texto le cambiaría los
             * caracteres que el lector leyó bien.
             */
            if ($escaneado !== '') {
                $sub->orWhereHas(
                    'presentaciones',
                    fn (Builder $presentacion): Builder => $presentacion
                        ->where('codigo_barras', $escaneado),
                );
            }
        });
    }

    /**
     * Búsqueda ordenada por parecido, para el selector del mostrador.
     *
     * @return EloquentCollection<int, static>
     */
    public static function buscar(
        string $termino,
        int $limite = 20,
        bool $soloVigentes = false,
        bool $soloDelRol = false,
    ): EloquentCollection {
        $clave = NormalizadorDeTexto::clave($termino);

        $consulta = static::query()->buscar($termino);

        /*
         * ─────────────────────────────────────────────────────────────
         * 🔴 EL LÍMITE VA EN LA CONSULTA, NO DESPUÉS
         * ─────────────────────────────────────────────────────────────
         *
         * Filtrar la colección ya traída sería más simple y estaría mal:
         * el `limit` corta ANTES, así que una búsqueda amplia traería
         * veinte ítems de todo el catálogo y dejaría los tres de
         * laboratorio que hubiera entre ellos. El laboratorista vería una
         * lista casi vacía y concluiría que su examen no existe.
         *
         * ⚠️ Apagado por defecto, igual que `soloVigentes`: conteo
         * físico, ajustes y reportes buscan en el catálogo entero. Esto
         * es solo para las pantallas donde se le COBRA algo a alguien.
         */
        if ($soloDelRol) {
            CatalogoDelRol::filtrar($consulta);
        }

        /*
         * ─────────────────────────────────────────────────────────────
         * 🔴 RETIRAR UN ÍTEM NO LO SACABA DEL BUSCADOR
         * ─────────────────────────────────────────────────────────────
         *
         * El catálogo se retira con fecha de fin de vigencia y no con un
         * botón de activo — pero el buscador no la miraba. Un estudio
         * dado de baja, o el nombre mal escrito que alguien creó por
         * error, seguía apareciendo al cargar una cuenta y seguía
         * cobrándose. La regla existía y no la aplicaba nadie donde
         * importaba.
         *
         * ⚠️ Apagado por defecto A PROPÓSITO. Conteo físico y ajuste de
         * inventario SÍ tienen que encontrar lo retirado: un producto que
         * se dejó de vender puede seguir teniendo existencia en el
         * estante, y hay que poder contarla y ajustarla. Lo que no se
         * puede es cobrarla ni comprar más.
         */
        if ($soloVigentes) {
            $consulta->vigentesEn(now());
        }

        if ($clave !== '') {
            $consulta->orderByRaw('similarity(nombre_busqueda, ?) desc', [$clave]);
        }

        /** @var EloquentCollection<int, static> $resultado */
        $resultado = $consulta->limit($limite)->get();

        return $resultado;
    }

    // ── Reglas de negocio ─────────────────────────────────────────────

    /**
     * ¿Descuenta existencia del kardex?
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 ES UNA RESPUESTA DE ESTE ÍTEM, NO UNA PROPIEDAD DE SU TIPO
     * ─────────────────────────────────────────────────────────────────
     *
     * Antes se deducía del tipo: medicamento e insumo movían kardex y
     * todo lo demás no. Eso deja afuera dos casos reales:
     *
     *   · el insumo que se compra y se consume sin inventariar —el papel
     *     de la camilla, el gel del ecógrafo— y que al inventariarse a
     *     la fuerza aparece en cada conteo físico dando diferencias que
     *     nadie puede explicar;
     *   · el ítem de tipo «otro» que sí se guarda y sí hay que contar.
     *
     * Ahora lo contesta quien da de alta el ítem. El tipo sigue
     * proponiendo el valor —ver `booted()`—, pero no lo impone.
     *
     * De acá cuelga TODO lo demás: si es falso no hay almacén, ni lote,
     * ni FEFO, ni costo promedio, ni pestaña de existencias. Por eso no
     * se puede apagar en un ítem que ya tiene movimientos: dejaría stock
     * escrito que ninguna pantalla vuelve a mostrar.
     */
    /**
     * ¿Se le cobra el envase completo?
     *
     * Para un jarabe que el paciente se lleva, sí: el frasco es suyo
     * porque lo pagó. Para lo que se comparte entre pacientes —una
     * solución de la que se sacan dosis— no, y ahí manda la regla normal
     * del repartidor.
     *
     * 🔴 Es del producto y no de cada despacho a propósito: si sobre el
     * mismo producto conviven las dos reglas, al primero se le cobra el
     * frasco y al segundo los mililitros que sobraron, y la misma gota se
     * cobró dos veces.
     */
    /**
     * Lo que este producto lleva adentro.
     *
     * ⚠️ Muchos a muchos porque un antigripal lleva acetaminofén +
     * clorfenamina + fenilefrina, y amoxicilina viene con ácido
     * clavulánico. Con un solo principio por producto el segundo queda
     * invisible, y el día que alguien pregunte «¿qué tengo con
     * acetaminofén?» para no duplicar dosis, la respuesta sale
     * incompleta sin que se note.
     *
     * @return BelongsToMany<PrincipioActivo, $this>
     */
    public function principiosActivos(): BelongsToMany
    {
        return $this->belongsToMany(PrincipioActivo::class, 'item_principio_activo')
            ->withPivot('concentracion')
            ->withTimestamps();
    }

    public function seFacturaPorEnvase(): bool
    {
        return (bool) $this->factura_envase_entero;
    }

    public function mueveInventario(): bool
    {
        return (bool) $this->se_almacena;
    }

    /**
     * ¿Ya tiene algo escrito en inventario?
     *
     * Es lo que impide apagar «se almacena» en un ítem que ya se movió.
     * Apagarlo dejaría existencia, lotes y kardex escritos debajo de un
     * ítem que ninguna pantalla vuelve a mostrar como inventariable: el
     * stock no desaparece, se vuelve invisible — y el conteo físico
     * siguiente no lo encuentra para cuadrarlo.
     *
     * ⚠️ Deuda declarada: hoy esto lo cuida el formulario. Un import o
     * una consola pueden saltárselo. El guardián de verdad es un trigger
     * en `items`, que va junto con el bloque de familias.
     */
    public function tieneInventarioEscrito(): bool
    {
        return MovimientoKardex::query()->where('item_id', $this->id)->exists()
            || $this->existencias()->exists()
            || $this->lotes()->exists();
    }

    /**
     * Horas de vida útil después de abierto el envase.
     *
     * Nulo en el ítem = usar el default de la instalación. Muchos
     * multidosis vencen a las 24-48 h de abiertos sin importar la fecha
     * impresa en el frasco.
     */
    public function horasDeVidaAbierto(): int
    {
        if (is_int($this->horas_caducidad_post_apertura)) {
            return $this->horas_caducidad_post_apertura;
        }

        return (int) config('sihla.inventario.horas_caducidad_post_apertura_por_defecto', 24);
    }

    /**
     * ¿El descuento de adulto mayor de este ítem exige receta?
     *
     * Art. 34 de la Ley del Adulto Mayor: para medicamentos, sí — receta
     * original firmada y sellada. El ítem también puede exigir receta por
     * otra razón (controlado), y son cosas distintas: una es requisito
     * para DISPENSAR, la otra para DESCONTAR.
     */
    public function elDescuentoExigeReceta(): bool
    {
        return $this->categoria_legal_descuento->exigeReceta();
    }

    public function etiqueta(): string
    {
        return "{$this->codigo} — {$this->nombre}";
    }
}
