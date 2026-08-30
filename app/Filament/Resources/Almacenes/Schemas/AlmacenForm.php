<?php

declare(strict_types=1);

namespace App\Filament\Resources\Almacenes\Schemas;

use App\Domain\Enums\TipoAlmacen;
use App\Domain\Enums\TipoServicio;
use App\Filament\Schemas\Components\CampoMayusculas;
use App\Filament\Schemas\Components\SedeField;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Formulario de Almacén — patrón §10.
 *
 * ⚠️ MODO ALMACÉN ÚNICO (`sihla.inventario.modo_almacen_unico`)
 *
 * Hospital Los Ángeles no divide el inventario: hay UN almacén y ahí se
 * guarda todo. Con la bandera encendida, «Tipo» y «Servicio dueño»
 * desaparecen de la pantalla y el almacén nace como `AlmacenUnico`.
 * Crear un almacén queda en: sede (ya viene puesta) + código + nombre.
 *
 * No se borran ni el campo ni los otros tipos: la clínica siguiente sí
 * separa bodega de farmacia (§1.1) y apagar la bandera se los devuelve
 * sin tocar código ni migración.
 */
final class AlmacenForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('almacen')
                ->persistTabInQueryString()
                ->columnSpanFull()
                ->tabs([
                    self::identificacion(),
                    self::control(),
                    self::estado(),
                ]),
        ]);
    }

    public static function modoUnico(): bool
    {
        return (bool) config('sihla.inventario.modo_almacen_unico', false);
    }

    private static function identificacion(): Tab
    {
        return Tab::make('Identificación')
            ->icon('heroicon-o-archive-box')
            ->schema([
                SedeField::make(),

                /*
                 * 🔴 NO SE PIDE AL CREAR. Lo pone
                 * `AsignadorDeCodigoDeAlmacen` en el momento de guardar:
                 * BOD-01, FAR-01, SRV-01, SRV-02.
                 *
                 * Pedirlo era pedirle a quien crea el carrito que invente
                 * una convención y después la recuerde — y a la tercera
                 * persona que crea uno ya no existe convención: aparecen
                 * AM-1, CARRITO2 y CR_ROJO_1 en la misma lista.
                 *
                 * Al editar se ve pero no se toca: está escrito en cada
                 * movimiento del kardex de ese estante.
                 */
                CampoMayusculas::make('codigo')
                    ->label('Código')
                    ->hiddenOn('create')
                    ->disabledOn('edit')
                    ->maxLength(20)
                    ->helperText('Se genera solo al crear el almacén y ya no cambia: queda escrito en cada movimiento del kardex.'),

                CampoMayusculas::make('nombre')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Es lo que el personal lee al elegir «¿de dónde sale?»: CARRITO ROJO 1, FARMACIA, BODEGA.')
                    ->columnSpan(self::modoUnico() ? 2 : 1),

                ...self::tipoYServicio(),
            ])
            ->columns(2);
    }

    /**
     * El par «Tipo + Servicio dueño», o el Hidden que lo reemplaza.
     *
     * Se decide acá en PHP y no con `->visible()` a propósito: dos
     * componentes vivos apuntando al mismo `tipo` es la clase de cosa que
     * en Filament no revienta, solo se comporta raro (§9).
     *
     * @return list<Hidden|Select>
     */
    private static function tipoYServicio(): array
    {
        if (self::modoUnico()) {
            /*
             * Va como Hidden y no como Select escondido porque un campo
             * invisible no manda valor y `almacenes.tipo` es NOT NULL.
             *
             * `default()` solo pisa en create: un almacén viejo que ya era
             * bodega central conserva su tipo cuando se edita.
             */
            return [
                Hidden::make('tipo')
                    ->default(TipoAlmacen::AlmacenUnico->value),
            ];
        }

        return [
            /*
             * `live()` porque el campo de abajo cambia con esto: un stock
             * de servicio SIN servicio dueño es un carrito del que nadie
             * responde, y eso no se descubre hasta que falta algo adentro.
             */
            Select::make('tipo')
                ->label('Tipo')
                ->options(fn (): array => collect(TipoAlmacen::cases())
                    ->reject(fn (TipoAlmacen $t): bool => $t->esUnico())
                    ->mapWithKeys(fn (TipoAlmacen $t): array => [$t->value => $t->etiqueta()])
                    ->all())
                ->required()
                ->native(false)
                ->live()
                ->helperText(
                    'FARMACIA DE VENTA es de donde se le entrega al paciente. BODEGA CENTRAL '
                    .'guarda y traslada, no dispensa. STOCK DEL SERVICIO es un carro de paro o '
                    .'un carrito de piso — «CARRITO ROJO 1» va acá, con su servicio abajo.'
                ),

            /*
             * ⚠️ SIN `searchable()`. Un hospital tiene ocho áreas, no
             * ochocientas, y con la búsqueda encendida el desplegable
             * mostraba la única opción cargada Y debajo «No se
             * encontraron coincidencias con su búsqueda» — porque la
             * búsqueda va contra el servidor y con término vacío no
             * devuelve nada. Las dos cosas juntas se leen como que el
             * sistema está roto.
             */
            Select::make('servicio_id')
                ->label('Servicio dueño')
                ->relationship('servicio', 'nombre')
                ->preload()
                ->native(false)
                ->columnSpanFull()
                /*
                 * 🔴 OBLIGATORIO EN UN STOCK DE SERVICIO, y solo ahí.
                 *
                 * El tipo ya dice que es el estante de un área; sin el
                 * área, la frase queda a medias. «CARRITO ROJO 1» sin
                 * servicio es un almacén más en la lista, y cuando falte
                 * una ampolla adentro no hay a quién preguntarle.
                 *
                 * Bodega central y farmacia de venta NO cuelgan de
                 * ninguna área: ahí queda vacío y está bien.
                 */
                ->required(fn (Get $get): bool => $get('tipo') === TipoAlmacen::StockDeServicio->value)
                ->placeholder(fn (Get $get): string => $get('tipo') === TipoAlmacen::StockDeServicio->value
                    ? '¿De qué área es este carrito?'
                    : 'Ninguno — no cuelga de un área')
                ->helperText(
                    'Dejar vacío para bodega central y farmacia de venta, que no cuelgan de '
                    .'ningún área. Se elige un servicio cuando el almacén es SUYO: el carro de '
                    .'paro de emergencia, el carrito de piso de hospitalización. Es lo que '
                    .'después contesta «¿de quién es este carrito?» sin preguntarle a nadie.'
                )
                /*
                 * ─────────────────────────────────────────────────────
                 * EL ÁREA SE CREA DESDE ACÁ
                 * ─────────────────────────────────────────────────────
                 *
                 * La lista trae los servicios que el hospital ya cargó, y
                 * al principio son pocos —o uno—. Mandar a quien está
                 * creando el carrito a otra pantalla, cargar el área,
                 * volver y empezar el formulario de nuevo es la clase de
                 * viaje que termina en «le pongo cualquiera».
                 *
                 * Son los mismos campos que exige la tabla: sin `tipo` no
                 * hay censo de camas, y sin `vigencia_desde` la fila no
                 * entra.
                 */
                ->createOptionForm([
                    SedeField::make(),

                    CampoMayusculas::make('codigo')
                        ->label('Código')
                        ->required()
                        ->maxLength(20)
                        ->helperText('Corto: EMERG, HOSP, QX.'),

                    CampoMayusculas::make('nombre')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(255),

                    Select::make('tipo')
                        ->label('Tipo de área')
                        ->options(fn (): array => collect(TipoServicio::cases())
                            ->mapWithKeys(fn (TipoServicio $t): array => [$t->value => $t->etiqueta()])
                            ->all())
                        ->required()
                        ->native(false)
                        ->helperText('Determina si el área tiene camas y entra en el censo. No es cosmético.'),

                    DatePicker::make('vigencia_desde')
                        ->label('Vigente desde')
                        ->required()
                        ->default(now())
                        ->native(false)
                        ->displayFormat('d/m/Y'),
                ])
                ->createOptionModalHeading('Nueva área del hospital'),
        ];
    }

    private static function control(): Tab
    {
        return Tab::make('Control')
            ->icon('heroicon-o-lock-closed')
            ->schema([
                Section::make('Estupefacientes y psicotrópicos')
                    ->description(
                        'Marcar esto activa obligaciones ante ARSA: recetario especial autorizado, '
                        .'libro de control con saldo corrido actualizado a diario, y reporte mensual '
                        .'dentro de los primeros 5 días del mes siguiente.'
                    )
                    ->icon('heroicon-o-exclamation-triangle')
                    ->schema([
                        Toggle::make('maneja_controlados')
                            ->label('Este almacén maneja controlados')
                            /*
                             * 🔴 ENCENDIDO POR DEFECTO, SIEMPRE.
                             *
                             * Antes seguía a `modoUnico()`, y al partir los
                             * estantes eso pasó a `false` sin que nadie lo
                             * pidiera: el primer CARRITO ROJO habría nacido
                             * con el libro de ARSA apagado — y el carro de
                             * paro es justamente donde vive el fentanilo.
                             *
                             * El riesgo no es simétrico. Marcado de más son
                             * anotaciones que sobran; marcado de menos es un
                             * libro que nadie llevó, que es hallazgo de ARSA
                             * y no se puede reconstruir hacia atrás. Se
                             * desmarca a mano en el almacén que de verdad no
                             * guarda controlados.
                             */
                            ->default(true)
                            ->helperText(
                                'Es propiedad del ALMACÉN, no del producto: el mismo medicamento '
                                .'controlado puede estar bajo llave en un almacén y en anaquel en otro.'
                            ),
                    ]),
            ]);
    }

    private static function estado(): Tab
    {
        return Tab::make('Estado')
            ->icon('heroicon-o-signal')
            ->schema([
                Section::make('Vigencia')
                    ->description(
                        'Un almacén que se cierra deja de recibir movimientos y su kardex histórico '
                        .'sigue siendo consultable.'
                    )
                    ->schema([
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
                            ->afterOrEqual('vigencia_desde'),
                    ])
                    ->columns(2),

                Section::make('Información del registro')
                    ->icon('heroicon-o-information-circle')
                    ->visibleOn('edit')
                    ->schema([
                        TextEntry::make('created_at')
                            ->label('Creado')
                            ->dateTime('d/m/Y H:i'),

                        TextEntry::make('createdBy.name')
                            ->label('Creado por')
                            ->placeholder('Sistema'),

                        TextEntry::make('updated_at')
                            ->label('Última modificación')
                            ->dateTime('d/m/Y H:i'),
                    ])
                    ->columns(3),
            ]);
    }
}
