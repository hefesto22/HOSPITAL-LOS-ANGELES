<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\Schemas;

use App\Domain\Enums\MagnitudDeMedida;
use App\Domain\ValueObjects\Decimal;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Unidad;
use App\Services\FormasDeCobro;
use App\Services\SincronizadorDeFormasDeVenta;
use App\Support\NumeroDeFormulario;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Size;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * «Formas de venta» en la ficha del producto.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 TODAS JUNTAS Y EN LA FICHA (Mauricio, 11-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * «Al seleccionar, que diga formas de venta: blíster, tableta y caja.
 * Tableta se sabe que es uno; al agregar blíster dirá de cuánto es el
 * blíster, y caja pues es el total de pastillas. Así en automático se
 * crean como se vende, y es más fácil marcar qué mercadería entra.»
 *
 *   Formas de venta
 *     TABLETA          1 TAB     suelta
 *     BLÍSTER  ▾   [  10 ] TAB    1 BLISTER = 10 TAB · sale también de CAJA X 100
 *     CAJA     ▾   [ 100 ] TAB    1 CAJA = 100 TAB · trae 10 BLISTER
 *     [+ Blíster] [+ Caja] [+ Frasco] [+ Otra]
 *
 * Antes cada envase era un modal propio —envase, cantidad, nombre,
 * vigencia— que solo se podía abrir con el producto ya guardado. Ahora
 * se cargan con el producto, también al crearlo, y al guardar quedan las
 * presentaciones creadas (`SincronizadorDeFormasDeVenta`).
 *
 * La unidad suelta NO es una fila: es «Se dispensa en», que ya está en
 * la ficha. Una fila de «TABLETA X 1» sería lo mismo dicho dos veces.
 *
 * ⚠️ Una forma por la que ya entró mercadería se puede cambiar de envase
 * o de tamaño (Mauricio, 13-oct-2026: «si cambio el blíster a 12, que se
 * pueda y que actualice el precio»), pero no en su fila: al guardar, el
 * servicio la REEMPLAZA desde hoy y lo que entró sigue como entró (§9.G11,
 * `SincronizadorDeFormasDeVenta`). La fila lo dice mientras se edita. Lo
 * que no tiene es botón de quitar.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA PRINCIPAL SE ELIGE CON LA ESTRELLA (11-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mauricio: «¿cómo se selecciona cuál es el envase principal? En las
 * tabletas es la caja, pero en otros es el frasco, la bolsa de suero».
 * La regla «la más grande» acierta con las tabletas y se equivoca con
 * el suero que se dispensa por BOLSA y llega en CAJA X 12: ahí la
 * principal es la bolsa, que es la unidad suelta.
 *
 * Por eso la estrella está en cada fila Y en la línea de la suelta.
 * Mientras nadie elija, la principal es la más grande —o la suelta si
 * no hay envases— y la estrella lo muestra igual, con la aclaración en
 * el globo. Guardada, es `es_predeterminada` de la presentación; si la
 * principal es la suelta, ninguna presentación la lleva.
 */
final class FormasDeVenta
{
    public const CAMPO = 'formas_de_venta';

    /**
     * Si la principal es la unidad suelta —la bolsa de suero, la
     * ampolla— y no un envase.
     */
    public const SUELTA_PRINCIPAL = 'la_suelta_es_principal';

    /**
     * La clave con que se nombra a la suelta donde se habla de filas.
     */
    private const LA_SUELTA = 'suelta';

    /**
     * Los envases que se agregan con un clic, por el código de su
     * unidad. Si el hospital no tiene esa unidad, el botón no sale.
     */
    private const DE_UN_CLIC = [
        'BLISTER' => 'Blíster',
        'CAJA'    => 'Caja',
        'FRASCO'  => 'Frasco',
    ];

    /**
     * @return list<Component>
     */
    public static function componentes(): array
    {
        return [
            Hidden::make(self::SUELTA_PRINCIPAL)
                ->default(false),

            Placeholder::make('forma_suelta')
                ->label('Formas de venta')
                ->hintIcon(
                    'heroicon-o-information-circle',
                    'Cómo sale del estante. La unidad suelta la da «Se dispensa en»; cada envase que '
                    .'agregues se recibe y se vende sin abrir. Un blíster sale también de una caja '
                    .'que lo trae. La estrella marca la principal: la caja en las tabletas, el frasco '
                    .'en un jarabe, la bolsa en un suero.',
                )
                ->columnSpanFull()
                ->content(fn (Get $get): HtmlString => self::laSuelta(
                    $get('unidad_dispensacion_id'),
                    self::principalEfectiva($get(self::CAMPO), $get(self::SUELTA_PRINCIPAL)) === self::LA_SUELTA,
                ))
                ->afterContent(self::estrella(self::LA_SUELTA)),

            Repeater::make(self::CAMPO)
                ->hiddenLabel()
                ->columnSpanFull()
                /*
                 * ⚠️ Las columnas van en el MISMO orden que los campos
                 * visibles: la tabla las empareja por posición, y los
                 * `Hidden` no cuentan.
                 */
                ->table([
                    TableColumn::make('Envase')->width('26%')->markAsRequired(),
                    TableColumn::make('Trae')->width('20%')->markAsRequired(),
                    TableColumn::make('Así se vende')->width('54%'),
                ])
                ->schema([
                    Hidden::make('presentacion_id'),
                    Hidden::make('usada'),
                    Hidden::make('principal'),
                    /*
                     * Cómo era al abrir la ficha, para decir «antes era
                     * BLISTER X 10» mientras se cambia una ya usada.
                     */
                    Hidden::make('era'),
                    Hidden::make('era_clave'),

                    Select::make('unidad_id')
                        ->hiddenLabel()
                        ->options(fn (Get $get): array => self::envasesPosibles(
                            $get('../../unidad_dispensacion_id'),
                            $get('unidad_id'),
                        ))
                        ->required()
                        ->native(false)
                        ->live(),

                    TextInput::make('unidades_por_presentacion')
                        ->hiddenLabel()
                        ->placeholder('¿Cuántas?')
                        ->required()
                        ->numeric()
                        ->minValue(0.0001)
                        ->suffix(fn (Get $get): ?string => self::codigoDe($get('../../unidad_dispensacion_id')))
                        ->live(onBlur: true)
                        /*
                         * La fila que se acaba de agregar con «+ Caja»
                         * llega con el cursor puesto acá: el envase ya
                         * está elegido y lo único que falta es el número.
                         * Solo si está vacío, para que al abrir la ficha
                         * el cursor no salte a la primera fila.
                         */
                        ->extraInputAttributes([
                            'x-init' => "\$el.value === '' && \$nextTick(() => \$el.focus())",
                        ]),

                    Placeholder::make('asi_se_vende')
                        ->hiddenLabel()
                        ->content(fn (Get $get, Placeholder $component): HtmlString => self::comoSeVende(
                            $get,
                            self::principalEfectiva($get('../../'.self::CAMPO), $get('../../'.self::SUELTA_PRINCIPAL))
                                === Str::afterLast($component->getContainer()->getStatePath(), '.'),
                        )),
                ])
                ->addable(false)
                ->reorderable(false)
                ->defaultItems(0)
                ->extraItemActions([self::estrella()])
                ->deleteAction(fn (Action $action): Action => $action
                    ->tooltip('Quitar esta forma de venta')
                    ->visible(fn (array $arguments, Repeater $component): bool => ! self::filaUsada(
                        $component->getRawState(),
                        $arguments['item'] ?? null,
                    )))
                ->rules([
                    fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        self::sinRepetidas($value, $fail);
                    },
                ]),

            Actions::make(self::botonesDeAgregar())
                ->columnSpanFull(),
        ];
    }

    /**
     * Las filas con que abre la ficha: una por envase, de la más chica a
     * la más grande —se lee igual que en el estante: tableta, blíster,
     * caja—.
     *
     * @return array<string, array{presentacion_id: int, usada: bool, principal: bool, era: string, era_clave: string, unidad_id: int, unidades_por_presentacion: string}>
     */
    public static function filasDe(Item $item): array
    {
        $presentaciones = ItemPresentacion::query()
            ->where('item_id', $item->id)
            ->orderBy('unidades_por_presentacion')
            ->orderBy('id')
            ->get();

        $usadas = app(SincronizadorDeFormasDeVenta::class)->usadas(array_map(
            static fn (ItemPresentacion $presentacion): int => $presentacion->id,
            $presentaciones->all(),
        ));

        $filas = [];

        foreach ($presentaciones as $presentacion) {
            $filas[(string) Str::uuid()] = [
                'presentacion_id'           => $presentacion->id,
                'usada'                     => in_array($presentacion->id, $usadas, true),
                'principal'                 => $presentacion->es_predeterminada,
                'era'                       => $presentacion->envase(),
                'era_clave'                 => self::clave($presentacion->unidad_id, $presentacion->unidades_por_presentacion),
                'unidad_id'                 => $presentacion->unidad_id,
                'unidades_por_presentacion' => ItemPresentacion::sinCerosDeMas($presentacion->unidades_por_presentacion),
            ];
        }

        return $filas;
    }

    /**
     * Lo que la ficha muestra al abrirse: las filas y si la principal es
     * la suelta.
     *
     * Con envases y ninguno marcado, la principal es la suelta: es lo
     * que se guardó cuando alguien le puso la estrella a la bolsa de
     * suero. Sin envases no se marca nada: la suelta es la única forma.
     *
     * @return array<string, mixed>
     */
    public static function estadoDe(Item $item): array
    {
        $filas = self::filasDe($item);

        $ningunaMarcada = array_filter($filas, static fn (array $fila): bool => $fila['principal']) === [];

        return [
            self::CAMPO            => $filas,
            self::SUELTA_PRINCIPAL => $filas !== [] && $ningunaMarcada,
        ];
    }

    /**
     * Lo que mandó la ficha, limpio para el servicio.
     *
     * @return list<array{presentacion_id: int|null, unidad_id: int|null, unidades_por_presentacion: string|null, principal: bool}>
     */
    public static function paraGuardar(mixed $filas): array
    {
        if (! is_array($filas)) {
            return [];
        }

        $limpias = [];

        foreach ($filas as $fila) {
            if (! is_array($fila)) {
                continue;
            }

            $limpias[] = [
                'presentacion_id'           => self::entero($fila['presentacion_id'] ?? null),
                'unidad_id'                 => self::entero($fila['unidad_id'] ?? null),
                'unidades_por_presentacion' => NumeroDeFormulario::aDecimal($fila['unidades_por_presentacion'] ?? null)?->exacto(),
                'principal'                 => self::esSi($fila['principal'] ?? null),
            ];
        }

        return $limpias;
    }

    // ── Lo que se lee ─────────────────────────────────────────────────

    /**
     * «TABLETA · 1 TAB · suelta». La primera forma de venta, que no se
     * agrega: es la de «Se dispensa en».
     */
    private static function laSuelta(mixed $dispensacion, bool $esLaPrincipal): HtmlString
    {
        $unidad = self::unidad($dispensacion);

        if (! $unidad instanceof Unidad) {
            return new HtmlString(
                '<span style="font-size:.8125rem;opacity:.7">Elegí «Se dispensa en» y acá aparece la '
                .'unidad suelta.</span>'
            );
        }

        return new HtmlString(
            '<div style="display:flex;gap:.75rem;align-items:baseline;font-size:.875rem">'
            .'<strong>'.e($unidad->nombre).'</strong>'
            .'<span style="opacity:.75">1 '.e($unidad->codigo).' · se vende suelta</span>'
            .($esLaPrincipal ? self::marcaDePrincipal() : '')
            .'</div>'
        );
    }

    /**
     * «1 CAJA = 100 TAB · trae 10 BLISTER», con lo que hay escrito
     * ahora mismo en la ficha.
     *
     * La relación entre envases se dice acá porque es lo que decide de
     * dónde sale un blíster sin abrir (`FormasDeCobro::cuantosTrae()`), y
     * quien carga el producto tiene que poder verla antes de guardar.
     */
    private static function comoSeVende(Get $get, bool $esLaPrincipal): HtmlString
    {
        $envase = self::unidad($get('unidad_id'));
        $trae = NumeroDeFormulario::aDecimal($get('unidades_por_presentacion'));
        $suelta = self::codigoDe($get('../../unidad_dispensacion_id')) ?? 'unidades';

        if (! $envase instanceof Unidad || ! $trae instanceof Decimal || ! $trae->mayorQue('0')) {
            return new HtmlString(
                '<span style="font-size:.8125rem;opacity:.6">Elegí el envase y cuántas trae.</span>'
            );
        }

        $partes = ['<strong>'.e(sprintf(
            '1 %s = %s %s',
            $envase->codigo,
            ItemPresentacion::sinCerosDeMas($trae->paraBase()),
            $suelta,
        )).'</strong>'];

        $formas = app(FormasDeCobro::class);
        $filas = $get('../../'.self::CAMPO);

        foreach (is_array($filas) ? $filas : [] as $otra) {
            $suUnidad = is_array($otra) ? self::unidad($otra['unidad_id'] ?? null) : null;
            $suContenido = is_array($otra) ? NumeroDeFormulario::aDecimal($otra['unidades_por_presentacion'] ?? null) : null;

            if (! $suUnidad instanceof Unidad || ! $suContenido instanceof Decimal) {
                continue;
            }

            $adentro = $formas->cuantosTrae($envase, $trae, $suUnidad->id, $suContenido);

            if ($adentro instanceof Decimal) {
                $partes[] = 'trae '.ItemPresentacion::sinCerosDeMas($adentro->paraBase()).' '.e($suUnidad->codigo);
            }

            if ($formas->cuantosTrae($suUnidad, $suContenido, $envase->id, $trae) instanceof Decimal) {
                $partes[] = 'sale también de '.e($suUnidad->codigo.' X '.ItemPresentacion::sinCerosDeMas($suContenido->paraBase()));
            }
        }

        if (self::esSi($get('usada'))) {
            $era = $get('era');
            $cambio = is_string($era) && $get('era_clave') !== self::clave($envase->id, $trae->paraBase());

            $partes[] = $cambio
                ? '<span style="color:rgb(217 119 6)">antes era '.e($era).': desde hoy se vende este y los precios se '
                    .'recalculan solos con el mismo mínimo; lo que ya entró sigue como entró</span>'
                : '<span style="opacity:.6">ya entró mercadería así; si cambia, los precios se ajustan solos</span>';
        }

        return new HtmlString(
            '<div style="font-size:.875rem;line-height:1.4">'.implode(' · ', $partes)
            .($esLaPrincipal ? self::marcaDePrincipal() : '').'</div>'
        );
    }

    // ── Agregar ───────────────────────────────────────────────────────

    /**
     * «+ Blíster», «+ Caja», «+ Frasco» y «+ Otra». Cada uno agrega una
     * fila con el envase ya elegido: falta solo el número.
     *
     * @return list<Action>
     */
    private static function botonesDeAgregar(): array
    {
        $botones = [];

        foreach (self::DE_UN_CLIC as $codigo => $rotulo) {
            $botones[] = Action::make('agregar_'.mb_strtolower($codigo))
                ->label($rotulo)
                ->icon('heroicon-m-plus')
                ->color('gray')
                ->size(Size::Small)
                /*
                 * No se ofrece si la unidad no existe, ni si es la misma
                 * en que se dispensa: «FRASCO» de un producto que se
                 * vende por frasco sería la unidad suelta otra vez.
                 */
                ->visible(function (Get $get) use ($codigo): bool {
                    $unidad = self::delCodigo($codigo);

                    return $unidad instanceof Unidad
                        && $unidad->id !== self::entero($get('unidad_dispensacion_id'));
                })
                ->action(function (Get $get, Set $set) use ($codigo): void {
                    self::agregarFila($get, $set, self::delCodigo($codigo)?->id);
                });
        }

        $botones[] = Action::make('agregar_otra')
            ->label('Otra')
            ->icon('heroicon-m-plus')
            ->color('gray')
            ->size(Size::Small)
            ->action(function (Get $get, Set $set): void {
                self::agregarFila($get, $set, null);
            });

        return $botones;
    }

    private static function agregarFila(Get $get, Set $set, ?int $unidad): void
    {
        $filas = $get(self::CAMPO);
        $filas = is_array($filas) ? $filas : [];

        $filas[(string) Str::uuid()] = [
            'presentacion_id'           => null,
            'usada'                     => false,
            'principal'                 => false,
            'era'                       => null,
            'era_clave'                 => null,
            'unidad_id'                 => $unidad,
            'unidades_por_presentacion' => null,
        ];

        $set(self::CAMPO, $filas);
    }

    // ── Validación ────────────────────────────────────────────────────

    /**
     * La misma forma dos veces —CAJA de 100 y CAJA de 100— es la misma
     * presentación cargada dos veces, y la base la rechaza con un error
     * de SQL. Acá se dice cuál está repetida.
     */
    private static function sinRepetidas(mixed $filas, Closure $fail): void
    {
        $vistas = [];

        foreach (is_array($filas) ? $filas : [] as $fila) {
            if (! is_array($fila)) {
                continue;
            }

            $unidad = self::unidad($fila['unidad_id'] ?? null);
            $trae = NumeroDeFormulario::aDecimal($fila['unidades_por_presentacion'] ?? null);

            if (! $unidad instanceof Unidad || ! $trae instanceof Decimal) {
                continue;
            }

            $clave = $unidad->id.':'.$trae->paraBase();

            if (isset($vistas[$clave])) {
                $fail(sprintf(
                    '«%s de %s» está dos veces. Quitá una de las dos filas.',
                    $unidad->codigo,
                    ItemPresentacion::sinCerosDeMas($trae->paraBase()),
                ));

                return;
            }

            $vistas[$clave] = true;
        }
    }

    // ── Unidades ──────────────────────────────────────────────────────

    /**
     * Los envases que se pueden elegir: lo que se cuenta, menos la unidad
     * suelta —que ya es la primera forma—. La que tiene la fila se deja
     * siempre, aunque no cumpla, para que no aparezca el campo en blanco.
     *
     * @return array<int, string>
     */
    private static function envasesPosibles(mixed $dispensacion, mixed $actual): array
    {
        $suelta = self::entero($dispensacion);
        $elegida = self::entero($actual);

        return self::unidades()
            ->filter(fn (Unidad $unidad): bool => $unidad->id === $elegida
                || ($unidad->magnitud === MagnitudDeMedida::Conteo && $unidad->id !== $suelta))
            ->mapWithKeys(fn (Unidad $unidad): array => [$unidad->id => $unidad->etiqueta()])
            ->all();
    }

    private static function codigoDe(mixed $unidad): ?string
    {
        return self::unidad($unidad)?->codigo;
    }

    private static function unidad(mixed $id): ?Unidad
    {
        $id = self::entero($id);

        if ($id === null) {
            return null;
        }

        $unidad = self::unidades()->firstWhere('id', $id);

        return $unidad instanceof Unidad ? $unidad : null;
    }

    private static function delCodigo(string $codigo): ?Unidad
    {
        $unidad = self::unidades()->firstWhere('codigo', $codigo);

        return $unidad instanceof Unidad ? $unidad : null;
    }

    /**
     * Todas las unidades, una vez por petición.
     *
     * Cada fila pregunta tres cosas —qué envases ofrecer, en qué se
     * cuenta y cómo se lee— y con una consulta por pregunta la ficha
     * hacía una decena por cada tecla. Son veinte y pico filas: se leen
     * enteras una vez. `once()` y no una propiedad estática, que en los
     * tests quedaba vieja de una prueba a la otra (§9.C8).
     *
     * @return Collection<int, Unidad>
     */
    private static function unidades(): Collection
    {
        return once(fn (): Collection => Unidad::query()
            ->orderBy('nombre')
            ->get(['id', 'codigo', 'nombre', 'simbolo', 'magnitud', 'contiene_envases']));
    }

    // ── La principal ──────────────────────────────────────────────────

    /**
     * La estrella: de una fila (sin clave, la pone el repetidor en
     * `$arguments`) o de la línea de la suelta.
     *
     * Llena si es la principal y vacía si no; el globo dice si la eligió
     * alguien o es la automática. Un clic la mueve acá.
     */
    private static function estrella(?string $deLaSuelta = null): Action
    {
        $cual = static fn (array $arguments): ?string => $deLaSuelta
            ?? (is_string($arguments['item'] ?? null) ? $arguments['item'] : null);

        return Action::make($deLaSuelta === null ? 'hacer_principal' : 'suelta_principal')
            ->iconButton()
            ->icon(fn (array $arguments, Get $get): string => self::laPrincipalEs($get, $cual($arguments))
                ? 'heroicon-s-star'
                : 'heroicon-o-star')
            ->color(fn (array $arguments, Get $get): string => self::laPrincipalEs($get, $cual($arguments))
                ? 'warning'
                : 'gray')
            ->tooltip(fn (array $arguments, Get $get): string => self::globoDeLaEstrella($get, $cual($arguments)))
            ->action(function (array $arguments, Get $get, Set $set) use ($cual): void {
                self::marcarLaPrincipal($get, $set, $cual($arguments));
            });
    }

    private static function laPrincipalEs(Get $get, ?string $cual): bool
    {
        return $cual !== null
            && self::principalEfectiva($get(self::CAMPO), $get(self::SUELTA_PRINCIPAL)) === $cual;
    }

    private static function globoDeLaEstrella(Get $get, ?string $cual): string
    {
        if (! self::laPrincipalEs($get, $cual)) {
            return 'Hacerla la principal';
        }

        if (self::hayUnaElegida($get(self::CAMPO), $get(self::SUELTA_PRINCIPAL))) {
            return 'Es la principal';
        }

        return $cual === self::LA_SUELTA
            ? 'Es la principal porque no hay envases.'
            : 'Es la principal porque es la más grande. Tocá la estrella de otra para cambiarla.';
    }

    private static function marcarLaPrincipal(Get $get, Set $set, ?string $cual): void
    {
        if ($cual === null) {
            return;
        }

        $filas = $get(self::CAMPO);
        $marcadas = [];

        foreach (is_array($filas) ? $filas : [] as $clave => $fila) {
            $marcadas[$clave] = is_array($fila)
                ? [...$fila, 'principal' => (string) $clave === $cual]
                : $fila;
        }

        $set(self::CAMPO, $marcadas);
        $set(self::SUELTA_PRINCIPAL, $cual === self::LA_SUELTA);
    }

    /**
     * Cuál es la principal ahora mismo: la fila marcada, la suelta si se
     * marcó, o —si nadie eligió— la más grande, y la suelta si no hay
     * envases. Es la misma regla que aplica el servicio al guardar.
     */
    private static function principalEfectiva(mixed $filas, mixed $sueltaPrincipal): string
    {
        $filas = is_array($filas) ? $filas : [];

        foreach ($filas as $clave => $fila) {
            if (is_array($fila) && self::esSi($fila['principal'] ?? null)) {
                return (string) $clave;
            }
        }

        if (self::esSi($sueltaPrincipal)) {
            return self::LA_SUELTA;
        }

        $mayor = self::LA_SUELTA;
        $mayorContenido = null;

        foreach ($filas as $clave => $fila) {
            $contenido = is_array($fila) ? NumeroDeFormulario::aDecimal($fila['unidades_por_presentacion'] ?? null) : null;

            if ($contenido instanceof Decimal
                && $contenido->mayorQue('0')
                && (! $mayorContenido instanceof Decimal || $contenido->mayorQue($mayorContenido))
            ) {
                $mayor = (string) $clave;
                $mayorContenido = $contenido;
            }
        }

        return $mayor;
    }

    private static function hayUnaElegida(mixed $filas, mixed $sueltaPrincipal): bool
    {
        if (self::esSi($sueltaPrincipal)) {
            return true;
        }

        foreach (is_array($filas) ? $filas : [] as $fila) {
            if (is_array($fila) && self::esSi($fila['principal'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private static function marcaDePrincipal(): string
    {
        return '<span style="margin-left:.5rem;color:rgb(245 158 11);font-weight:600">★ principal</span>';
    }

    /**
     * ¿La fila de esta clave es de una forma por la que ya entró
     * mercadería? Se lee del estado crudo y no de la fila armada: el
     * botón de quitar se pregunta antes de que la fila se dibuje.
     */
    private static function filaUsada(mixed $filas, mixed $clave): bool
    {
        if (! is_array($filas) || ! is_string($clave) || ! is_array($filas[$clave] ?? null)) {
            return false;
        }

        return self::esSi($filas[$clave]['usada'] ?? null);
    }

    /**
     * «7:10.0000»: el envase y lo que trae, para saber si una fila usada
     * cambió desde que se abrió la ficha.
     */
    private static function clave(mixed $unidad, mixed $contenido): string
    {
        $decimal = NumeroDeFormulario::aDecimal($contenido);

        return self::entero($unidad).':'.($decimal instanceof Decimal ? $decimal->paraBase() : '');
    }

    public static function esSi(mixed $valor): bool
    {
        return $valor === true || $valor === 1 || $valor === '1';
    }

    private static function entero(mixed $valor): ?int
    {
        return is_numeric($valor) ? (int) $valor : null;
    }
}
