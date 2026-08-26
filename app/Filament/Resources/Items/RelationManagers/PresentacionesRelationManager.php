<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\RelationManagers;

use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Unidad;
use App\Support\CodigoDeBarras;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Marcelorodrigo\FilamentBarcodeScannerField\Forms\Components\BarcodeInput;

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
 * 🔴 EL CÓDIGO DE BARRAS VIVE ACÁ, Y NO EN LA FICHA DEL PRODUCTO
 * ─────────────────────────────────────────────────────────────────────
 *
 * «ACETAMINOFEN TABLETA 800 MG» es el nombre de un medicamento: no es
 * nada que se pueda agarrar con la mano ni pegarle una etiqueta. Lo que
 * existe en el estante es la caja de 100 y el blíster de 12, y son ellos
 * los que se escanean.
 *
 * Por eso el campo pide una de dos cosas, las dos válidas:
 *
 *   · El código del FABRICANTE, leído de la caja con la pistola. Sirve
 *     para recibir mercadería sin teclear nada.
 *   · O el del HOSPITAL, generado acá e impreso en la etiqueta, para lo
 *     que se reenvasa o viene sin código legible.
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
                 * 🔴 EL NOMBRE SE ESCRIBE SOLO, CON LO QUE YA SE
                 * CONTESTÓ ABAJO
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
                TextInput::make('nombre')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull()
                    ->default(fn (): ?string => $this->nombrePropuesto())
                    ->dehydrateStateUsing(fn (mixed $state): string => is_string($state) ? trim($state) : '')
                    ->placeholder($this->ejemploDeNombre())
                    ->helperText($this->ayudaDelNombre()),

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
                 * ESCANEAR EL DE LA CAJA, O GENERAR EL DEL HOSPITAL
                 * ─────────────────────────────────────────────────────
                 *
                 * Tres formas de llenarlo, y ninguna excluye a las otras:
                 *
                 *   · LA PISTOLA USB. Un lector de código de barras es un
                 *     teclado: el campo está esperando, se apunta y se
                 *     dispara. No hay nada que configurar.
                 *   · LA CÁMARA DEL TELÉFONO, con el ícono de la derecha.
                 *     Es la que se va a usar en el estante, donde no hay
                 *     una computadora con pistola al lado.
                 *   · A MANO, tecleando.
                 *
                 * Es `BarcodeInput` y no un `TextInput` justamente por la
                 * segunda: hereda de TextInput, así que todo lo de abajo
                 * —las reglas, el dibujo, el estado— funciona igual. Es
                 * el mismo campo que ya usan recepción, conteos, ajustes
                 * y el mostrador de cuentas.
                 *
                 * ⚠️ La cámara exige HTTPS. En `http://` —y eso incluye
                 * entrar por la IP de la red desde el teléfono— el
                 * navegador no la ofrece y el ícono abre un recuadro
                 * vacío. No es el campo: es una regla del navegador.
                 *
                 * Y aparte, «Generar» propone el del hospital para cuando
                 * la caja no trae ninguno legible o el producto se
                 * reenvasa. Ese código es el del ítem con un sufijo
                 * —`MED-0708-01`— para que la etiqueta se pueda leer con
                 * los ojos, sin escáner y sin sistema.
                 *
                 * ⚠️ «Generar» va como `hintAction` —arriba, al lado de
                 * la etiqueta— y no como `suffixAction`. La vista de
                 * `BarcodeInput` ocupa el sufijo con su propio botón de
                 * cámara y NO dibuja las acciones de sufijo: puesto ahí,
                 * el botón desaparecía sin dar ningún error.
                 *
                 * 🔴 Único en toda la tabla, incluidas las borradas. Dos
                 * presentaciones con el mismo código hacen que el lector
                 * devuelva la que el ORDER BY quiera; y reciclar el de
                 * una presentación dada de baja haría que la caja que
                 * todavía tiene esa etiqueta pegada escanee como otra
                 * cosa.
                 */
                BarcodeInput::make('codigo_barras')
                    ->label('Código de barras')
                    ->visible(fn (): bool => $this->seAlmacena())
                    ->columnSpanFull()
                    ->maxLength(64)
                    ->live(onBlur: true)
                    ->autocomplete(false)
                    ->placeholder('Disparale con la pistola, tocá la cámara, o escribilo')
                    ->dehydrateStateUsing(fn (mixed $state): ?string => self::codigoLimpio($state))
                    /*
                     * La unicidad se verifica acá y no con `->unique()`
                     * de Filament a propósito: la regla de Laravel
                     * descarta las filas borradas cuando el modelo usa
                     * SoftDeletes, y acá hace falta lo contrario —una
                     * etiqueta impresa sigue pegada en una caja aunque la
                     * presentación se haya dado de baja—.
                     */
                    ->rules([
                        fn (?ItemPresentacion $record): Closure => function (
                            string $attribute,
                            mixed $value,
                            Closure $fail,
                        ) use ($record): void {
                            self::verificarQueSePuedaImprimir($value, $fail);
                            self::verificarQueNoEsteTomado($record, $value, $fail);
                        },
                    ])
                    ->hintAction(
                        Action::make('generar_codigo')
                            ->label('Generar el del hospital')
                            ->icon('heroicon-o-sparkles')
                            ->color('warning')
                            ->action(function (Set $set): void {
                                $this->generarElCodigo($set);
                            }),
                    )
                    ->helperText(
                        'El de la caja del proveedor: disparale con la pistola, o tocá la cámara para '
                        .'leerlo con el teléfono. Si no trae código legible, o si el producto se '
                        .'reenvasa, «Generar el del hospital» propone uno para imprimir.'
                    ),

                /*
                 * El dibujo de lo que se va a imprimir, mientras todavía
                 * se puede corregir. No es adorno: Code 128-B no cubre
                 * tildes ni ñ, y un código con una tilde adentro no se
                 * imprime mal —no se imprime—, cosa que sin esto se
                 * descubre recién en la impresora.
                 */
                Placeholder::make('dibujo_del_codigo')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => $this->seAlmacena()
                        && self::codigoLimpio($get('codigo_barras')) !== null)
                    ->content(fn (Get $get): HtmlString => self::dibujo($get('codigo_barras'))),

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
                TextColumn::make('nombre')
                    ->label('Presentación')
                    ->weight('medium')
                    ->wrap()
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
                    ->getStateUsing(fn (ItemPresentacion $record): bool => $record->codigo_barras !== null)
                    ->trueIcon('heroicon-o-qr-code')
                    ->falseIcon('heroicon-o-exclamation-triangle')
                    ->trueColor('success')
                    ->falseColor('warning')
                    ->tooltip(fn (ItemPresentacion $record): string => $record->codigo_barras ?? 'Sin código: no se puede escanear.'),

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
                        ? 'Este producto se lleva en '.$this->unidadDeDispensacion()
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

    /**
     * Escribe en el campo el código que el hospital propone.
     *
     * Avisa cuando no puede en vez de dejar el campo como estaba: un
     * botón que a veces no hace nada enseña que el botón no sirve.
     */
    private function generarElCodigo(Set $set): void
    {
        $duenio = $this->getOwnerRecord();

        if (! $duenio instanceof Item) {
            return;
        }

        $codigo = ItemPresentacion::codigoSugeridoPara($duenio);

        if ($codigo === null) {
            Notification::make()
                ->title('No se pudo proponer un código')
                ->body(
                    'El producto no tiene código propio, o ya usó los noventa y nueve sufijos. '
                    .'Escribí el código a mano.'
                )
                ->warning()
                ->send();

            return;
        }

        $set('codigo_barras', $codigo);
    }

    /**
     * El código tal cual lo va a leer el escáner, o null si no hay nada.
     *
     * ⚠️ Solo se le quitan los espacios de los extremos —que es lo que
     * agrega la pistola al final de la lectura—. Ni mayúsculas ni
     * acentos ni nada: algunos GS1 llevan minúsculas, y tocarlas hace
     * que lo guardado deje de coincidir con lo que el lector manda.
     */
    private static function codigoLimpio(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $limpio = trim($valor);

        return $limpio === '' ? null : $limpio;
    }

    /**
     * Un código que no se puede dibujar en Code 128-B no sirve para nada
     * acá: la etiqueta saldría en blanco.
     *
     * Se rechaza al guardar y no al imprimir, porque al imprimir ya es
     * tarde — la presentación quedó cargada, alguien la dio por buena y
     * el problema aparece con la caja en la mano.
     */
    private static function verificarQueSePuedaImprimir(mixed $value, Closure $fail): void
    {
        $codigo = self::codigoLimpio($value);

        if ($codigo === null) {
            return;
        }

        if (CodigoDeBarras::codificable($codigo) === '') {
            $fail(
                'Ese código no se puede imprimir en barras: tiene tildes, eñes o algún carácter '
                .'fuera del ASCII imprimible. Los códigos de barras solo llevan letras sin tilde, '
                .'números y guiones.'
            );
        }
    }

    /**
     * Dos presentaciones con el mismo código de barras hacen que el
     * lector devuelva la que el ORDER BY quiera — silenciosamente y
     * distinta cada vez.
     *
     * ⚠️ Mira también las BORRADAS, y por eso no se usa `->unique()` de
     * Filament: esa regla las descarta cuando el modelo usa SoftDeletes.
     * Acá hace falta lo contrario. Una presentación se da de baja del
     * sistema, pero su etiqueta sigue pegada en una caja del estante: si
     * el código se reasignara, esa caja pasaría a escanear como otra
     * cosa.
     *
     * El índice único de la base es el que manda; esto existe para que
     * el choque se explique en el formulario y no como un error de SQL.
     */
    private static function verificarQueNoEsteTomado(
        ?ItemPresentacion $record,
        mixed $value,
        Closure $fail,
    ): void {
        $codigo = self::codigoLimpio($value);

        if ($codigo === null) {
            return;
        }

        $tomada = ItemPresentacion::withTrashed()
            ->with('item')
            ->where('codigo_barras', $codigo)
            ->when(
                $record instanceof ItemPresentacion,
                fn ($query) => $query->whereKeyNot($record?->getKey()),
            )
            ->first();

        if (! $tomada instanceof ItemPresentacion) {
            return;
        }

        $duenio = $tomada->item;
        $de = $duenio instanceof Item ? $duenio->nombre : 'otro producto';

        $fail(
            "Ese código de barras ya es de «{$tomada->nombre}», de {$de}. Dos presentaciones con "
            .'el mismo código hacen que el lector devuelva cualquiera de las dos.'
        );
    }

    /**
     * El código dibujado, para verlo antes de imprimirlo.
     *
     * Devuelve `HtmlString` porque el SVG se inserta tal cual. No hay
     * dato del usuario sin escapar acá adentro: el texto pasa por
     * `CodigoDeBarras::codificable()`, que solo deja ASCII imprimible.
     */
    private static function dibujo(mixed $valor): HtmlString
    {
        $codigo = self::codigoLimpio($valor);

        if ($codigo === null) {
            return new HtmlString('');
        }

        $svg = CodigoDeBarras::svg($codigo, modulo: 2, alto: 44);

        if ($svg === '') {
            return new HtmlString(
                '<span style="font-size:.875rem;color:#b45309">⚠️ Este código no se puede imprimir '
                .'en barras: tiene caracteres fuera del ASCII imprimible.</span>'
            );
        }

        /*
         * ⚠️ Estilos EN LÍNEA y no clases de Tailwind: este HTML se
         * inyecta como `HtmlString` y nunca pasa por el compilador de
         * CSS, así que las clases no existen en la hoja final.
         */
        return new HtmlString(
            '<div style="display:inline-block;background:#fff;color:#000;padding:.5rem;border-radius:.5rem">'
            .$svg
            .'</div>'
        );
    }

    // ── Textos que dependen del ítem ──────────────────────────────────

    /**
     * «1 CAJA = 100 TABLETA», armado con lo que hay escrito ahora mismo
     * en el formulario y no con lo guardado.
     */
    private function comoSeLee(Get $get): HtmlString
    {
        $unidadId = $get('unidad_id');
        $contenido = $get('unidades_por_presentacion');

        if (! is_numeric($unidadId) || ! is_numeric($contenido)) {
            return new HtmlString(
                '<span style="font-size:.875rem;color:#6b7280">Elegí el envase y cuánto trae, y acá '
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

        return new HtmlString(
            '<span style="font-size:.9375rem;font-weight:600">'.e($frase).'</span>'
            .'<span style="font-size:.875rem;color:#6b7280"> — así entra al kardex.</span>'
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
            ? 'Una caja de 100 ampollas lleva 100. El kardex se lleva SIEMPRE en la unidad de '
              .'dispensación del ítem —acá '.$this->unidadDeDispensacion().'—, nunca en cajas.'
            : 'Uno. Una variante que no se almacena no es un envase que contenga otra cosa: es la '
              .'misma prestación con otro precio.';
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
     * El nombre con el que abre el campo: el del producto, pelado.
     *
     * Lo que va después de la pleca todavía no se sabe —depende del
     * envase y del contenido, que están más abajo y vacíos— y se agrega
     * solo al contestarlos.
     */
    private function nombrePropuesto(): ?string
    {
        return $this->seAlmacena() ? $this->nombreBase() : null;
    }

    /**
     * Reescribe el nombre con la forma «PRODUCTO / ENVASE X n UNIDAD».
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

        if ($base === null) {
            return;
        }

        $actual = is_string($get('nombre')) ? trim($get('nombre')) : '';

        $esDelSistema = $actual === ''
            || $actual === $base
            || str_starts_with($actual, $base.' / ');

        if (! $esDelSistema) {
            return;
        }

        $sufijo = $this->sufijoDelEnvase($get);

        $set('nombre', $sufijo === null ? $base : $base.' / '.$sufijo);
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
            ? 'Se arma solo: el nombre del producto, una pleca y cómo viene envasado —«/ CAJA X 100 '
              .'TABLETA», «/ FRASCO X 120 ML»—. Se completa al contestar los dos campos de abajo. Si '
              .'querés otro nombre, escribilo encima y no se vuelve a tocar.'
            : 'Qué distingue a esta variante de las otras: el médico, la medida, la marca. '
              .'Se guarda en mayúsculas.';
    }

    private function etiquetaDeLaUnidad(): string
    {
        return $this->seAlmacena() ? 'Unidad del envase' : 'Se cobra en';
    }

    private function ayudaDeLaUnidad(): string
    {
        return $this->seAlmacena()
            ? 'CÓMO VIENE, no cómo se vende: CAJA, FRASCO, BLÍSTER. Una caja de 100 tabletas se '
              .'carga con envase CAJA y contenido 100 — el kardex la va a descontar de a tableta '
              .'igual.'
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
