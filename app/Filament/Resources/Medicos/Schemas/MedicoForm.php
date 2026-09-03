<?php

declare(strict_types=1);

namespace App\Filament\Resources\Medicos\Schemas;

use App\Domain\Enums\TipoItem;
use App\Filament\Schemas\Components\CampoMayusculas;
use App\Filament\Schemas\Components\MontoField;
use App\Filament\Schemas\Components\TelefonoHondurasField;
use App\Models\Convenio;
use App\Models\Especialidad;
use App\Models\Item;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/**
 * Formulario del médico — patrón §10.
 */
final class MedicoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('medico')
                ->persistTabInQueryString()
                ->columnSpanFull()
                ->tabs([
                    self::identificacion(),
                    self::loQueCobra(),
                    self::estado(),
                ]),
        ]);
    }

    private static function identificacion(): Tab
    {
        return Tab::make('El médico')
            ->icon('heroicon-o-user-circle')
            ->schema([
                CampoMayusculas::make('nombre')
                    ->label('Nombre completo')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull()
                    ->helperText('Como tiene que aparecer en el renglón de la cuenta y en la factura.'),

                /*
                 * 🔴 ES CON LO QUE LA ASEGURADORA LO IDENTIFICA.
                 *
                 * Los tarifarios que el hospital presenta listan a cada
                 * especialista por su número de identidad, y contra ese
                 * número liquidan. Se puede dejar vacío —no vale trabar
                 * el alta de un médico por un dato que se completa
                 * después— pero sin él, ese médico no se puede incluir
                 * en la próxima propuesta.
                 */
                TextInput::make('identidad')
                    ->label('Identidad')
                    ->placeholder('0801-1990-12345')
                    ->maxLength(20)
                    ->helperText('Con este número lo identifican las aseguradoras en los tarifarios.'),

                Select::make('especialidad_id')
                    ->label('Especialidad')
                    ->required()
                    ->native(false)
                    /*
                     * ⚠️ Sin `searchable()`. Con opciones estáticas,
                     * Filament manda el término al servidor y busca un
                     * `getSearchResultsUsing` que no existe: cualquier
                     * texto tecleado contesta «no se encontraron
                     * coincidencias» aunque la opción esté en la lista.
                     */
                    ->options(fn (): array => Especialidad::query()
                        ->vigentes()
                        ->orderBy('nombre')
                        ->pluck('nombre', 'id')
                        ->all())
                    ->createOptionForm([
                        CampoMayusculas::make('codigo')
                            ->label('Código')
                            ->required()
                            ->maxLength(20),

                        CampoMayusculas::make('nombre')
                            ->label('Nombre')
                            ->required()
                            ->maxLength(255),

                        DatePicker::make('vigencia_desde')
                            ->label('Vigente desde')
                            ->required()
                            ->default(now())
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                    ])
                    ->createOptionUsing(fn (array $data): int => (int) Especialidad::create($data)->getKey()),

                CampoMayusculas::make('colegiacion')
                    ->label('Colegiación')
                    ->maxLength(30)
                    ->helperText('El número del Colegio Médico. Se puede dejar vacío y completarlo después.'),

                TelefonoHondurasField::make('telefono', 'Teléfono'),

                /*
                 * 🔴 Casi siempre vacío, y está bien. Solo se llena
                 * cuando el médico ADEMÁS entra al sistema: así el médico
                 * tratante de un encuentro y el que cobra el honorario
                 * terminan siendo la misma ficha.
                 */
                Select::make('user_id')
                    ->label('Usuario del sistema')
                    ->native(false)
                    ->options(fn (): array => User::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->helperText('Solo si este médico además entra a SIHLA. La mayoría no entra: dejalo vacío.'),
            ])
            ->columns(2);
    }

    private static function loQueCobra(): Tab
    {
        return Tab::make('Lo que cobra')
            ->icon('heroicon-o-banknotes')
            ->schema([
                Section::make('Precios propios de este médico')
                    ->description(
                        'Solo los honorarios en los que cobra distinto del tarifario; lo que no esté '
                        .'acá se cobra al precio de la lista del hospital. Los precios son antes de '
                        .'ISV. Pagador vacío vale para todos los que no tengan renglón propio, y '
                        .'«Hasta» vacío mientras siga cobrando ese precio.'
                    )
                    ->schema([
                        /*
                         * ─────────────────────────────────────────────
                         * EN TABLA, NO EN TARJETAS
                         * ─────────────────────────────────────────────
                         *
                         * Cada precio era una tarjeta con sus cinco
                         * etiquetas y sus tres textos de ayuda: un doctor
                         * con cuatro honorarios llenaba dos pantallas y
                         * el botón de Guardar quedaba abajo del pliegue.
                         * Y como cada campo traía su propia frase, los
                         * renglones terminaban a alturas distintas.
                         *
                         * En tabla los encabezados se escriben una sola
                         * vez, lo que hay que saber está en la
                         * descripción de arriba, y cinco precios ocupan
                         * lo que antes ocupaba uno.
                         *
                         * Sin reordenar: el orden de los precios de un
                         * médico no significa nada —manda la vigencia y
                         * el pagador—, y la manija de arrastre solo
                         * gastaba ancho.
                         */
                        Repeater::make('honorarios')
                            ->hiddenLabel()

                            /*
                             * ─────────────────────────────────────────
                             * 🔴 SIN ESTO EL REPEATER NI LEE NI GUARDA
                             * ─────────────────────────────────────────
                             *
                             * `honorarios` es una relación `HasMany`, no
                             * una columna. Sin `->relationship()`,
                             * Filament la trata como un campo del
                             * formulario y pasan las dos cosas a la vez:
                             *
                             *   · Al ABRIR, el estado sale de
                             *     `attributesToArray()` del médico, que
                             *     no incluye relaciones — la tabla
                             *     mostraba «1» y esta pantalla salía
                             *     vacía, sobre el mismo registro.
                             *   · Al GUARDAR, `honorarios` no está en el
                             *     `$fillable` de `Medico`, así que
                             *     Eloquent lo **descarta en silencio**:
                             *     sin excepción, sin log, y con el
                             *     cartel de «guardado» en pantalla.
                             *
                             * Lo segundo es lo grave: cargar honorarios
                             * acá no guardaba nada y nadie se enteraba.
                             * Es el mismo silencio del `update()` con
                             * campos no fillable que ya mordió en
                             * `EmisorDeFactura` con `cerrada_en`.
                             *
                             * Con `->relationship()` Filament carga la
                             * relación al abrir y la sincroniza al
                             * guardar —crea, actualiza y da de baja—.
                             */
                            ->relationship()
                            ->table([
                                TableColumn::make('Honorario')->width('34%')->markAsRequired(),
                                TableColumn::make('Pagador')->width('24%'),
                                TableColumn::make('Cobra')->width('14%')->markAsRequired(),
                                TableColumn::make('Desde')->width('14%')->markAsRequired(),
                                TableColumn::make('Hasta')->width('14%'),
                            ])
                            ->addActionLabel('Agregar un honorario')
                            ->reorderable(false)
                            ->defaultItems(0)
                            ->schema([
                                Select::make('item_id')
                                    ->hiddenLabel()
                                    ->required()
                                    ->native(false)
                                    ->searchable()
                                    /*
                                     * Solo honorarios. Un medicamento con
                                     * «precio de médico» no es una
                                     * negociación: es una fila cargada en
                                     * la pantalla equivocada, y cobrarla
                                     * saltearía el margen sobre el costo
                                     * promedio que le da precio a
                                     * farmacia.
                                     *
                                     * Acá `searchable()` SÍ va: hay
                                     * `getSearchResultsUsing`.
                                     */
                                    ->options(fn (): array => self::honorariosDelCatalogo())
                                    ->getSearchResultsUsing(fn (string $search): array => self::honorariosDelCatalogo($search))
                                    ->getOptionLabelUsing(fn (mixed $value): ?string => is_numeric($value)
                                        ? Item::query()->find((int) $value)?->etiqueta()
                                        : null),

                                /*
                                 * 🔴 EL PAGADOR, PORQUE EL PRECIO CAMBIA
                                 * CON ÉL. Un doctor no le cobra lo mismo
                                 * al paciente de la calle que al del
                                 * Hospital Militar o al de PALIG.
                                 */
                                Select::make('convenio_id')
                                    ->hiddenLabel()
                                    ->native(false)
                                    ->placeholder('Cualquiera')
                                    ->options(fn (): array => Convenio::query()
                                        ->orderBy('nombre')
                                        ->pluck('nombre', 'id')
                                        ->all()),

                                MontoField::make('precio')
                                    ->hiddenLabel(),

                                DatePicker::make('vigencia_desde')
                                    ->hiddenLabel()
                                    ->required()
                                    ->default(now())
                                    ->native(false)
                                    ->displayFormat('d/m/Y'),

                                DatePicker::make('vigencia_hasta')
                                    ->hiddenLabel()
                                    ->native(false)
                                    ->displayFormat('d/m/Y')
                                    ->afterOrEqual('vigencia_desde'),
                            ]),
                    ]),
            ]);
    }

    /**
     * Los honorarios del catálogo, para el selector de arriba.
     *
     * @return array<int, string>
     */
    private static function honorariosDelCatalogo(?string $termino = null): array
    {
        return Item::query()
            ->where('tipo', TipoItem::Honorario->value)
            ->when(
                $termino !== null && trim($termino) !== '',
                fn (Builder $query): Builder => $query->where(
                    fn (Builder $consulta): Builder => $consulta
                        ->where('nombre', 'ilike', '%'.trim($termino).'%')
                        ->orWhere('codigo', 'ilike', '%'.trim($termino).'%'),
                ),
            )
            ->orderBy('nombre')
            ->limit(30)
            ->get()
            ->mapWithKeys(fn (Item $item): array => [(int) $item->getKey() => $item->etiqueta()])
            ->all();
    }

    private static function estado(): Tab
    {
        return Tab::make('Estado')
            ->icon('heroicon-o-signal')
            ->schema([
                Section::make('Vigencia')
                    ->description(
                        'Un médico que deja de trabajar con el hospital se cierra: deja de aparecer '
                        .'en el selector de honorarios y sigue explicando los cargos de hace dos años.'
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
                            ->afterOrEqual('vigencia_desde')
                            ->helperText('Dejar vacío mientras siga atendiendo.'),
                    ])
                    ->columns(2),
            ]);
    }
}
