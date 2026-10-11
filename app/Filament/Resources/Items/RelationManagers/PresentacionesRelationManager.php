<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\RelationManagers;

use App\Domain\ValueObjects\Decimal;
use App\Filament\Schemas\Components\CampoMayusculas;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Unidad;
use App\Support\NumeroDeFormulario;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Presentaciones de compra de un ítem.
 *
 * ─────────────────────────────────────────────────────────────────────
 * QUÉ SE CARGA ACÁ Y QUÉ NO
 * ─────────────────────────────────────────────────────────────────────
 *
 * Acá se dice CÓMO VIENE el producto del proveedor: "CAJA X 100
 * AMPOLLAS", "CAJA X 50". No se dice cuánto cuesta ni cuánto hay — el
 * costo vive en el kardex y el precio en el tarifario.
 *
 * El kardex sigue llevándose SIEMPRE en la unidad de dispensación del
 * ítem. Cada fila de acá solo declara cuántas de esas unidades trae el
 * envase, para que quien recibe la compra elija "CAJA X 50" de una lista
 * y el factor lo ponga el sistema. Con una sola equivalencia en el ítem,
 * la segunda compra se convierte a mano — y ahí nace el costo cien veces
 * más alto que nadie nota hasta el cierre.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 EL CÓDIGO DE BARRAS YA NO SE PIDE ACÁ (10-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Regla de Mauricio: se carga una vez en la ficha del producto —el de
 * la caja del fabricante, distinto por marca— y lo heredan todos sus
 * envases. Antes se pedía envase por envase, y el blíster, que casi
 * nunca trae código propio, quedaba marcado como «no se puede
 * escanear».
 *
 * Los códigos que ya tenían las presentaciones se quedan guardados:
 * siguen encontrándose al escanear y sus etiquetas se siguen
 * imprimiendo desde la tabla.
 *
 * La etiqueta del ESTANTE es otra cosa y ya tiene su lugar: es la del
 * principio activo, y al escanearla salen todos los productos que lo
 * llevan.
 */
class PresentacionesRelationManager extends RelationManager
{
    protected static string $relationship = 'presentaciones';

    /**
     * El rótulo de la pestaña cambia con la naturaleza del ítem.
     *
     * En un medicamento son presentaciones DE COMPRA: la caja de 100, el
     * blíster. En un honorario son VARIANTES: el Dr. Carlos, el Dr.
     * Miguel. Llamarle «de compra» a esto último le pide a quien carga el
     * catálogo que traduzca en la cabeza, y traducir en la cabeza es
     * donde se cargan los datos en el campo equivocado.
     */
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return $ownerRecord instanceof Item && $ownerRecord->mueveInventario()
            ? 'Presentaciones de compra'
            : 'Variantes';
    }

    protected static function getModelLabel(): ?string
    {
        return 'presentación';
    }

    protected static function getPluralModelLabel(): ?string
    {
        return 'presentaciones';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                /*
                 * ─────────────────────────────────────────────────────
                 * PRIMERO EL ENVASE Y CUÁNTO TRAE, DESPUÉS EL NOMBRE
                 * ─────────────────────────────────────────────────────
                 *
                 * El nombre se escribe solo con esas dos respuestas, así
                 * que van arriba: se contestan dos campos y el tercero
                 * aparece lleno. Con el nombre primero, había que saltarlo
                 * vacío para llenar lo de abajo y volver.
                 *
                 * El producto no es un campo: va en el encabezado del
                 * modal. Como campo de solo lectura parecía algo que se
                 * podía editar y no.
                 *
                 * 🔴 Y el código de barras ya no se pide acá (10-oct-2026):
                 * se carga una vez en la ficha del producto y lo heredan
                 * todos sus envases. Los códigos que ya tenían las
                 * presentaciones se quedan guardados y siguen
                 * encontrándose al escanear.
                 */
                Select::make('unidad_id')
                    ->label($this->etiquetaDeLaUnidad())
                    ->options(fn (): array => Unidad::query()
                        ->orderBy('nombre')
                        ->get()
                        ->mapWithKeys(fn (Unidad $u): array => [$u->getKey() => $u->etiqueta()])
                        ->all())
                    ->required()
                    ->searchable()
                    ->native(false)
                    ->live()
                    /*
                     * Propuesta y no imposición: en lo que NO se almacena
                     * la variante se mide en la misma unidad en la que se
                     * cobra —un honorario del Dr. Carlos es una consulta,
                     * no una caja— así que la del ítem es el acierto casi
                     * siempre. En lo que sí se almacena, en cambio, la
                     * presentación normal ES un envase distinto de la
                     * unidad de dispensación, y proponerla sería
                     * empujar al error más caro de esta pantalla.
                     */
                    ->default(fn (): ?int => $this->unidadPropuesta())
                    ->afterStateUpdated(function (Get $get, Set $set): void {
                        $this->proponerElNombre($get, $set);
                    })
                    ->helperText($this->ayudaDeLaUnidad()),

                TextInput::make('unidades_por_presentacion')
                    ->label($this->etiquetaDelContenido())
                    ->required()
                    ->numeric()
                    ->minValue(0.0001)
                    ->step(0.0001)
                    ->live(onBlur: true)
                    ->default($this->contenidoPropuesto())
                    ->afterStateUpdated(function (Get $get, Set $set): void {
                        $this->proponerElNombre($get, $set);
                    })
                    ->helperText($this->ayudaDelContenido())
                    ->rules([
                        fn (?ItemPresentacion $record, Get $get): Closure => function (
                            string $attribute,
                            mixed $value,
                            Closure $fail,
                        ) use ($record, $get): void {
                            $this->verificarQueNoSeRepita($record, $get, $value, $fail);
                        },
                    ]),

                /*
                 * La misma información que los dos campos de arriba,
                 * leída como oración. Lo de siempre: «CAJA» y «100» se
                 * pueden llenar bien cada uno y significar un disparate
                 * juntos, y el disparate no da error — entra al kardex y
                 * aparece meses después en el conteo físico.
                 */
                Placeholder::make('asi_se_lee')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->visible(fn (): bool => $this->seAlmacena())
                    ->content(fn (Get $get): HtmlString => $this->comoSeLee($get)),

                /*
                 * ─────────────────────────────────────────────────────
                 * 🔴 EL NOMBRE SE ESCRIBE SOLO, CON LO QUE YA SE
                 * CONTESTÓ ARRIBA
                 * ─────────────────────────────────────────────────────
                 *
                 * Antes el campo abría vacío y con el ejemplo «CAJA X 100
                 * AMPOLLAS» de fondo, así que las presentaciones quedaban
                 * cargadas como «CAJA X 100» a secas. Ese nombre es el
                 * que después aparece SOLO —en el desplegable de la
                 * compra, en la línea del lote, en la etiqueta— y ahí
                 * «CAJA X 100» no dice de qué.
                 *
                 * Ahora no hay que escribirlo: el nombre del producto ya
                 * viene puesto, y al contestar el envase y el contenido
                 * se completa solo con la pleca y la equivalencia.
                 *
                 *   ACETAMINOFEN 500 MG TABLETA / CAJA X 100 TABLETA
                 *   PROMOFOL JARABE 100 MG / FRASCO X 120 ML
                 *   DIAZEPAM 10 MG/2 ML / AMPOLLA
                 *
                 * ⚠️ Lleva el ENVASE y no solo la cantidad. «/ 100
                 * TABLETA» no distingue la caja de 100 de la bolsa de
                 * 100, y son dos filas distintas que se compran distinto:
                 * dos presentaciones con el mismo nombre en el
                 * desplegable de la compra es donde alguien elige la que
                 * no era.
                 *
                 * ⚠️ Y no se impone: es texto normal, se puede pisar. Lo
                 * que se reescribe al cambiar el envase o el contenido es
                 * solo lo que escribió el sistema mismo —lo vacío, el
                 * nombre del producto pelado, o algo que ya tiene esta
                 * forma—. Un nombre tecleado a mano no se toca.
                 *
                 * ⚠️ Solo en lo que se almacena. En una variante el
                 * nombre es lo que la DISTINGUE —«DR. CARLOS MEJÍA»— y
                 * repetirle adelante el del honorario la haría más larga
                 * y menos legible.
                 */
                CampoMayusculas::make('nombre')
                    ->label(fn (): string => $this->seAlmacena() ? 'Presentación' : 'Nombre')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull()
                    ->placeholder($this->ejemploDeNombre())
                    ->helperText($this->ayudaDelNombre()),

                DatePicker::make('vigencia_desde')
                    ->label('Vigente desde')
                    ->required()
                    ->default(now())
                    ->native(false)
                    ->displayFormat('d/m/Y'),

                DatePicker::make('vigencia_hasta')
                    ->label('Vigente hasta')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->afterOrEqual('vigencia_desde')
                    ->helperText('Dejar vacío mientras se siga ofreciendo.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('nombre')
            ->columns([
                /*
                 * ─────────────────────────────────────────────────────
                 * 🔴 SE MUESTRA EL NOMBRE ENTERO, NO EL ENVASE PELADO
                 * ─────────────────────────────────────────────────────
                 *
                 * La columna GUARDA solo «CAJA X 100 TABLETAS» —una sola
                 * copia del nombre del producto, en el producto— pero
                 * mostrar eso solo sería cambiar un problema por otro:
                 * cinco filas que dicen «CAJA X 100», «CAJA X 50»,
                 * «BLISTER X 10» no se leen como presentaciones de un
                 * medicamento, se leen como cajas sueltas.
                 *
                 * Regla de Mauricio (3-sep-2026): «el nombre en
                 * identificación es solo para que aparezca como
                 * complemento principal de las presentaciones —
                 * ACETAMINOFEN 500 MG TABLETA CAJA X 100 TABLETAS».
                 *
                 * Guardado en dos pedazos, mostrado entero: es el nombre
                 * que después va a salir SOLO —en el desplegable de la
                 * compra, en la línea del lote, en la etiqueta impresa— y
                 * es el que tiene que poder leerse sin nada al lado.
                 */
                TextColumn::make('nombre')
                    ->label('Presentación')
                    ->weight('medium')
                    ->wrap()
                    ->state(fn (ItemPresentacion $record): string => $this->seAlmacena()
                        ? $record->nombreCompleto()
                        : $record->nombre)
                    ->description(fn (ItemPresentacion $record): ?string => $record->codigo_barras),

                TextColumn::make('unidad.codigo')
                    ->label('Envase')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('unidades_por_presentacion')
                    ->label('Contiene')
                    ->formatStateUsing(fn (string $state): string => ItemPresentacion::sinCerosDeMas($state))
                    ->suffix(fn (): string => ' '.$this->codigoDeDispensacion())
                    ->tooltip(fn (): string => 'En '.$this->unidadDeDispensacion()),

                IconColumn::make('es_predeterminada')
                    ->label('Habitual')
                    ->alignCenter()
                    ->boolean()
                    ->trueIcon('heroicon-o-star')
                    ->falseIcon('heroicon-o-minus-small')
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->tooltip('La que propone el formulario de compra.'),

                /*
                 * Se ve de un vistazo cuáles quedaron sin código: son las
                 * que no se pueden escanear al recibir ni al dispensar, y
                 * un hueco visible es lo único que hace que alguien lo
                 * llene.
                 */
                IconColumn::make('codigo_barras')
                    ->label('Escaneable')
                    ->alignCenter()
                    ->visible(fn (): bool => $this->seAlmacena())
                    ->boolean()
                    /*
                     * Escaneable también por herencia: si el producto
                     * tiene su código de fábrica en la ficha, escanearlo
                     * encuentra este envase igual. El triángulo queda
                     * solo para lo que de verdad no se puede escanear.
                     */
                    ->getStateUsing(fn (ItemPresentacion $record): bool => $record->codigo_barras !== null
                        || $this->codigoDelProducto() !== null)
                    ->trueIcon('heroicon-o-qr-code')
                    ->falseIcon('heroicon-o-exclamation-triangle')
                    ->trueColor('success')
                    ->falseColor('warning')
                    ->tooltip(fn (ItemPresentacion $record): string => $record->codigo_barras
                        ?? ($this->codigoDelProducto() !== null
                            ? 'Por el código del producto: '.$this->codigoDelProducto()
                            : 'Sin código: cargá el del producto en la ficha, en Identificación.')),

                TextColumn::make('vigencia_hasta')
                    ->label('Vigencia')
                    ->date('d/m/Y')
                    ->placeholder('Sin fecha de fin')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('es_predeterminada', 'desc')
            ->paginated(false)
            ->headerActions([
                CreateAction::make()
                    ->label('Agregar presentación')
                    ->modalHeading(fn (): string => $this->seAlmacena()
                        ? 'Nueva presentación de compra'
                        : 'Nueva variante')
                    /*
                     * ⚠️ EL AVISO QUE EVITA EL ERROR MÁS CARO DE ESTA
                     * PANTALLA
                     *
                     * Una presentación siempre se convierte a la unidad de
                     * dispensación del PRODUCTO. Cargar «FRASCO DE 60 ML»
                     * dentro de un producto que se dispensa en TABLETA no
                     * da ningún error: guarda 60 tabletas que no existen,
                     * y eso recién se descubre en el primer conteo físico,
                     * cuando ya hay meses de kardex encima.
                     *
                     * Decirlo antes de empezar cuesta una línea.
                     */
                    ->modalDescription(fn (): ?string => $this->seAlmacena()
                        ? ($this->nombreBase() ?? 'Este producto').' se lleva en '.$this->unidadDeDispensacion()
                        .'. Todo lo que cargues acá se convierte a esa unidad: si el envase '
                        .'viene en otra —un frasco de jarabe, por ejemplo—, es OTRO producto.'
                        : null)
                    ->modalWidth('2xl'),
            ])
            ->recordActions([
                Action::make('marcar_habitual')
                    ->label('Marcar habitual')
                    ->icon('heroicon-o-star')
                    ->color('warning')
                    ->visible(fn (ItemPresentacion $record): bool => ! $record->es_predeterminada)
                    ->action(function (ItemPresentacion $record): void {
                        /*
                         * El modelo desmarca sola a la anterior en su
                         * `saving`, y la base además impide dos marcadas.
                         * Acá solo se declara la intención.
                         */
                        $record->update(['es_predeterminada' => true]);
                    }),

                /*
                 * ─────────────────────────────────────────────────────
                 * LOS DOS FORMATOS, CADA UNO A UN CLIC
                 * ─────────────────────────────────────────────────────
                 *
                 * No son la misma etiqueta en dos tamaños: resuelven dos
                 * problemas distintos y quien imprime ya sabe cuál
                 * necesita antes de abrir nada.
                 *
                 *   · LA GRANDE va en la gaveta o en el estante, y se
                 *     escanea de lejos sin agacharse.
                 *   · LA HOJA DE 30 va en el frasco, el blíster y la
                 *     caja, de a una por envase.
                 *
                 * Van como dos acciones sueltas y no como un menú
                 * desplegable. El §9.A1 ya dejó escrito lo que pasa
                 * cuando una acción que necesita `$record` se mete
                 * adentro de un `ActionGroup`: se queda sin registro y
                 * desaparece sin que nada dé error. Acá el contexto es
                 * otro —una fila de tabla sí lo recibe— pero dos enlaces
                 * planos no tienen forma de fallar en silencio, y este no
                 * es el lugar para averiguarlo.
                 *
                 * Se abren en pestaña nueva y se mandan solas a la
                 * impresora: imprimir es abrir una página y apretar
                 * Ctrl+P, y un modal con JavaScript que arma otra ventana
                 * se lo come el bloqueador de pop-ups justo el día que
                 * hay que usarlo.
                 */
                Action::make('etiqueta_grande')
                    ->label('Etiqueta grande')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->tooltip('Una sola, en media hoja A4. Para la gaveta o el estante.')
                    ->visible(fn (ItemPresentacion $record): bool => $this->sePuedeEtiquetar($record))
                    ->url(fn (ItemPresentacion $record): string => route('etiquetas.presentacion', [
                        'presentacion' => $record->getKey(),
                        'formato'      => 'media',
                    ]))
                    ->openUrlInNewTab(),

                Action::make('etiqueta_hoja')
                    ->label('Hoja de 30')
                    ->icon('heroicon-o-squares-2x2')
                    ->color('gray')
                    ->tooltip('Treinta chicas en una A4. Para el frasco, el blíster y la caja.')
                    ->visible(fn (ItemPresentacion $record): bool => $this->sePuedeEtiquetar($record))
                    ->url(fn (ItemPresentacion $record): string => route('etiquetas.presentacion', [
                        'presentacion' => $record->getKey(),
                        'formato'      => 'hoja',
                        'copias'       => 30,
                    ]))
                    ->openUrlInNewTab(),

                EditAction::make()
                    ->modalHeading(fn (): string => $this->seAlmacena()
                        ? 'Editar presentación'
                        : 'Editar variante')
                    ->modalDescription(fn (): ?string => $this->seAlmacena() ? $this->nombreBase() : null)
                    ->modalWidth('2xl'),
            ])
            ->toolbarActions([])
            ->emptyStateIcon('heroicon-o-cube')
            ->emptyStateHeading('Sin presentaciones cargadas')
            ->emptyStateDescription(
                'Una presentación es una variante de este mismo ítem: la caja de 100 frente a la '
                .'ampolla suelta, el tubo de 5 mm frente al de 10, el honorario del Dr. Carlos '
                .'frente al del Dr. Miguel. Cada una lleva su código de barras.'
            );
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * SE OFRECE SIEMPRE, TAMBIÉN EN LO QUE NO SE ALMACENA
     * ─────────────────────────────────────────────────────────────────
     *
     * Antes se escondía en todo lo que no movía inventario, con el
     * argumento de que «una consulta médica no viene en caja». El
     * argumento era correcto y la conclusión no: una presentación no es
     * solo un empaque, es una VARIANTE de la misma cosa.
     *
     *   · HONORARIO DE CONSULTA → «Dr. Carlos», «Dr. Miguel». Misma
     *     consulta, precio distinto según quién la da.
     *   · TUBO ENDOTRAQUEAL → «5 mm», «10 mm», «20 mm».
     *
     * Encapsular esas variantes bajo un ítem evita el catálogo con
     * cuarenta filas de honorarios que solo se distinguen por el
     * apellido al final del nombre — que es donde alguien elige el
     * equivocado con el paciente enfrente.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Item;
    }

    // ── El código de barras ───────────────────────────────────────────

    /**
     * ¿Hay algo que imprimir?
     *
     * Sin código de barras la etiqueta saldría en blanco, y la ruta
     * responde 404 a propósito. Esconder los dos botones es decirlo
     * antes: un botón que lleva a un error no es un botón, es una
     * trampa.
     */
    private function sePuedeEtiquetar(ItemPresentacion $presentacion): bool
    {
        return $this->seAlmacena() && $presentacion->codigo_barras !== null;
    }

    // ── Textos que dependen del ítem ──────────────────────────────────

    /**
     * «1 CAJA = 100 TABLETA», armado con lo que hay escrito ahora mismo
     * en el formulario y no con lo guardado.
     *
     * ─────────────────────────────────────────────────────────────────
     * Y DEBAJO, CÓMO SE VA A PODER COBRAR (10-oct-2026)
     * ─────────────────────────────────────────────────────────────────
     *
     * Que la caja se pueda vender cerrada no se configura en ningún
     * lado: sale de lo que ya está escrito acá —el envase y cuánto
     * trae— y de «Se dispensa en» en la ficha (`FormasDeCobro`). Como no
     * hay un campo que lo diga, lo dice esta línea; si no, quien carga
     * el catálogo busca dónde se marca «se vende por caja» y no lo
     * encuentra.
     */
    private function comoSeLee(Get $get): HtmlString
    {
        $unidadId = $get('unidad_id');
        $contenido = $get('unidades_por_presentacion');

        if (! is_numeric($unidadId) || ! is_numeric($contenido)) {
            return new HtmlString(
                '<span style="font-size:.8125rem;opacity:.7">Elegí el envase y cuánto trae, y acá '
                .'se lee la equivalencia completa.</span>'
            );
        }

        $envase = Unidad::query()->find((int) $unidadId);

        $frase = sprintf(
            '1 %s = %s %s',
            $envase instanceof Unidad ? $envase->codigo : '?',
            ItemPresentacion::sinCerosDeMas((string) $contenido),
            $this->codigoDeDispensacion(),
        );

        $unidad = $this->codigoDeDispensacion();
        $codigoDelEnvase = $envase instanceof Unidad ? $envase->codigo : 'envase';

        /*
         * El envase de una sola unidad no se ofrece aparte al cobrar:
         * «1 AMPOLLA = 1 AMPOLLA» sin abrir es lo mismo que una ampolla.
         */
        $trae = NumeroDeFormulario::aDecimal($contenido);

        $alCobrar = $trae instanceof Decimal && $trae->mayorQue('1')
            ? sprintf(
                'Al cobrar se elige: por %s, o %s sin abrir (%s %s de una).',
                $unidad,
                $codigoDelEnvase,
                ItemPresentacion::sinCerosDeMas((string) $contenido),
                $unidad,
            )
            : sprintf('Al cobrar se vende por %s.', $unidad);

        /*
         * En un recuadro y no suelto: es la línea que hay que leer antes
         * de guardar, y entre campos con ayuda se perdía como un texto
         * más. Colores con transparencia: se ven igual en tema claro y
         * oscuro, y el CSS del panel viene compilado (§9.A7).
         */
        return new HtmlString(
            '<div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.30);'
            .'border-radius:.625rem;padding:.625rem .875rem;line-height:1.5">'
            .'<div style="font-size:.9375rem;font-weight:600">'.e($frase)
            .'<span style="font-weight:400;opacity:.7"> — así entra al kardex</span></div>'
            .'<div style="font-size:.8125rem;opacity:.8">'.e($alCobrar).'</div>'
            .'</div>'
        );
    }

    private function unidadDeDispensacion(): string
    {
        $duenio = $this->getOwnerRecord();

        if (! $duenio instanceof Item) {
            return 'unidades';
        }

        /*
         * Sin nullsafe: el analizador tipa la relación como no nula y
         * `?->` sobra, pero la columna SÍ es nullable. `instanceof`
         * describe la realidad y no discute con nadie.
         */
        $unidad = $duenio->unidadDispensacion;

        return $unidad instanceof Unidad ? $unidad->nombre : 'unidades';
    }

    /**
     * El CÓDIGO de la unidad, no su nombre.
     *
     * Se usa en la etiqueta y en la columna a propósito: un código es un
     * símbolo y no se declina. Con el nombre salía «Cuántas tableta
     * trae» y «100 TABLETA», y pluralizar en español no se resuelve
     * agregando una ese — UNIDAD INTERNACIONAL, MILILITRO y VIAL hacen
     * cada uno lo suyo.
     */
    private function codigoDeDispensacion(): string
    {
        $duenio = $this->getOwnerRecord();

        if (! $duenio instanceof Item) {
            return 'unidades';
        }

        $unidad = $duenio->unidadDispensacion;

        return $unidad instanceof Unidad ? $unidad->codigo : 'unidades';
    }

    private function etiquetaDelContenido(): string
    {
        return $this->seAlmacena()
            ? 'Cuánto trae, en '.$this->codigoDeDispensacion()
            : 'Equivale a';
    }

    private function ayudaDelContenido(): string
    {
        return $this->seAlmacena()
            ? 'El inventario se lleva siempre en '.$this->codigoDeDispensacion().', nunca en cajas.'
            : 'Uno. Una variante que no se almacena no es un envase que contenga otra cosa: es la '
              .'misma prestación con otro precio.';
    }

    /**
     * El código de barras de la ficha, el que heredan los envases.
     */
    private function codigoDelProducto(): ?string
    {
        $duenio = $this->getOwnerRecord();

        return $duenio instanceof Item ? $duenio->codigo_barras : null;
    }

    /**
     * ¿El ítem dueño lleva existencia?
     *
     * De esto depende TODO el vocabulario de esta pantalla: en un
     * medicamento una presentación es un envase que contiene unidades;
     * en un honorario es una variante que se cobra distinto. Llamarles
     * igual a las dos cosas es lo que hace que alguien cargue «Dr.
     * Carlos» con contenido 100.
     */
    private function seAlmacena(): bool
    {
        $duenio = $this->getOwnerRecord();

        return $duenio instanceof Item && $duenio->mueveInventario();
    }

    /**
     * Reescribe la presentación con la forma «ENVASE X n UNIDAD».
     *
     * Sin el nombre del producto adelante: ese vive en la ficha y se le
     * pega al mostrar, no se copia acá.
     *
     * ─────────────────────────────────────────────────────────────────
     * PISA LO QUE ESCRIBIÓ EL SISTEMA, NUNCA LO QUE ESCRIBIÓ UNA PERSONA
     * ─────────────────────────────────────────────────────────────────
     *
     * Es la misma regla que el código propuesto del ítem: se reescribe
     * lo vacío, el nombre del producto pelado, y lo que ya tiene esta
     * forma. Cualquier otra cosa la tecleó alguien con una razón, y
     * perderla de golpe al corregir el contenido es la clase de sorpresa
     * que hace que la gente arme el catálogo en un Excel aparte.
     *
     * ⚠️ El heurístico no es perfecto: un nombre puesto a mano que
     * EMPIECE con «PRODUCTO / » se va a reescribir. Es a propósito —el
     * caso realista es corregir la cantidad, y ahí el nombre viejo quedó
     * mintiendo— y lo peor que puede pasar es volver a escribirlo.
     */
    private function proponerElNombre(Get $get, Set $set): void
    {
        if (! $this->seAlmacena()) {
            return;
        }

        $base = $this->nombreBase();

        /*
         * ⚠️ En MAYÚSCULAS antes de comparar. El campo las muestra
         * mientras se escribe, pero eso es CSS: el estado que viaja sigue
         * siendo lo tecleado. Sin esto, quien escribiera «caja x 50» veía
         * «CAJA X 50» en pantalla y el sistema lo tomaba por texto a mano
         * —porque la forma que reconoce arranca con mayúscula— y dejaba
         * de completarlo al cambiar el envase.
         */
        $actual = is_string($get('nombre')) ? mb_strtoupper(trim($get('nombre')), 'UTF-8') : '';

        /*
         * Lo que escribió el sistema y se puede pisar: lo vacío, lo que
         * quedó de la convención vieja con pleca, y cualquier cosa con la
         * forma «ENVASE X n …», que es la que propone este método.
         * Cualquier otra cosa la tecleó alguien con una razón.
         */
        $esDelSistema = $actual === ''
            || ($base !== null && ($actual === $base || str_starts_with($actual, $base.' / ')))
            || preg_match('/^\p{Lu}+ X \d/u', $actual) === 1;

        if (! $esDelSistema) {
            return;
        }

        $set('nombre', $this->sufijoDelEnvase($get) ?? '');
    }

    /**
     * Cómo viene envasado, en una sola frase: «CAJA X 100 TABLETA»,
     * «FRASCO X 120 ML», «BLÍSTER X 12 TABLETA».
     *
     * ⚠️ El caso especial es el envase que ES la unidad y trae una sola:
     * una ampolla de 2 ml que se dispensa por ampolla saldría «AMPOLLA X
     * 1 AMPOLLA». Ahí el nombre es simplemente «AMPOLLA».
     *
     * Devuelve null mientras falte contestar algo: media frase —«/ FRASCO
     * X»— es peor que ninguna.
     */
    private function sufijoDelEnvase(Get $get): ?string
    {
        $unidadId = $get('unidad_id');
        $contenido = $get('unidades_por_presentacion');

        if (! is_numeric($unidadId) || ! is_numeric($contenido)) {
            return null;
        }

        $envase = Unidad::query()->find((int) $unidadId);

        if (! $envase instanceof Unidad) {
            return null;
        }

        return ItemPresentacion::comoSeEnvasa(
            $envase->codigo,
            ItemPresentacion::sinCerosDeMas((string) $contenido),
            $this->codigoDeDispensacion(),
        );
    }

    /**
     * El nombre del producto dueño, que es con lo que arranca el de
     * todas sus presentaciones.
     */
    private function nombreBase(): ?string
    {
        $duenio = $this->getOwnerRecord();

        if (! $duenio instanceof Item) {
            return null;
        }

        $base = trim($duenio->nombre);

        return $base === '' ? null : $base;
    }

    private function ejemploDeNombre(): string
    {
        return $this->seAlmacena() ? 'CAJA X 100 AMPOLLAS' : 'DR. CARLOS MEJÍA';
    }

    private function ayudaDelNombre(): string
    {
        return $this->seAlmacena()
            ? 'Se completa solo con lo de arriba. Si querés otro texto, escribilo encima.'
            : 'Qué distingue a esta variante de las otras: el médico, la medida, la marca.';
    }

    private function etiquetaDeLaUnidad(): string
    {
        return $this->seAlmacena() ? 'Unidad del envase' : 'Se cobra en';
    }

    private function ayudaDeLaUnidad(): string
    {
        return $this->seAlmacena()
            ? 'Cómo viene: CAJA, FRASCO, BLÍSTER.'
            : 'La misma en la que se cobra el ítem. Para una consulta o un honorario, UNIDAD.';
    }

    /**
     * La unidad que se propone al abrir el formulario.
     *
     * @see El comentario del campo, que explica por qué solo se propone
     *      en lo que no se almacena.
     */
    private function unidadPropuesta(): ?int
    {
        if ($this->seAlmacena()) {
            return null;
        }

        $duenio = $this->getOwnerRecord();

        if ($duenio instanceof Item && $duenio->unidad_dispensacion_id !== null) {
            return $duenio->unidad_dispensacion_id;
        }

        /*
         * Sin unidad en el ítem se propone UNIDAD, que es la que aplica
         * a casi todo lo que no se almacena. La alternativa era dejarlo
         * vacío y obligar a buscar «UNIDAD» en una lista de AMPOLLA,
         * BLÍSTER, BOLSA y CAJA — envases, todos, para algo que no viene
         * envasado.
         */
        $unidad = Unidad::query()->where('codigo', 'UND')->first();

        return $unidad instanceof Unidad ? $unidad->id : null;
    }

    private function contenidoPropuesto(): ?int
    {
        return $this->seAlmacena() ? null : 1;
    }

    // ── Validación ────────────────────────────────────────────────────

    /**
     * Dos filas con la misma unidad y el mismo contenido son la misma
     * presentación cargada dos veces.
     *
     * La base ya lo impide con un índice único, pero ese camino termina
     * en un error de SQL crudo en la cara de quien carga el catálogo. Acá
     * se dice qué pasó y cómo arreglarlo.
     */
    private function verificarQueNoSeRepita(
        ?ItemPresentacion $record,
        Get $get,
        mixed $value,
        Closure $fail,
    ): void {
        $unidadId = $get('unidad_id');

        if (! is_numeric($value) || ! is_numeric($unidadId)) {
            return;
        }

        $duenio = $this->getOwnerRecord();

        if (! $duenio instanceof Item) {
            return;
        }

        $repetida = ItemPresentacion::query()
            ->where('item_id', $duenio->getKey())
            ->where('unidad_id', (int) $unidadId)
            ->where('unidades_por_presentacion', (string) $value)
            ->when(
                $record instanceof ItemPresentacion,
                fn ($query) => $query->whereKeyNot($record?->getKey()),
            )
            ->first();

        if ($repetida instanceof ItemPresentacion) {
            $fail("Ya existe esa presentación: «{$repetida->nombre}». Editala en vez de crear otra igual.");
        }
    }
}
