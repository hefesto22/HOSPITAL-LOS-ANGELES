<?php

declare(strict_types=1);

namespace App\Filament\Resources\Medicos\Schemas;

use App\Domain\Enums\TipoItem;
use App\Filament\Schemas\Components\CampoMayusculas;
use App\Filament\Schemas\Components\MontoField;
use App\Filament\Schemas\Components\TelefonoHondurasField;
use App\Models\Especialidad;
use App\Models\Item;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
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
                        'Solo los honorarios en los que este doctor cobra distinto del tarifario. '
                        .'Lo que no esté acá se cobra al precio de la lista del hospital, y eso es '
                        .'lo normal: la lista puede quedar vacía.'
                    )
                    ->schema([
                        Repeater::make('honorarios')
                            ->hiddenLabel()
                            ->relationship()
                            ->addActionLabel('Agregar un honorario')
                            ->defaultItems(0)
                            ->columns(4)
                            ->itemLabel(fn (array $state): ?string => is_numeric($state['item_id'] ?? null)
                                ? Item::query()->find((int) $state['item_id'])?->nombre
                                : null)
                            ->schema([
                                Select::make('item_id')
                                    ->label('Honorario')
                                    ->required()
                                    ->native(false)
                                    ->searchable()
                                    ->columnSpan(2)
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

                                MontoField::make('precio', 'Cobra')
                                    ->helperText('Antes de ISV, igual que el tarifario.'),

                                DatePicker::make('vigencia_desde')
                                    ->label('Desde')
                                    ->required()
                                    ->default(now())
                                    ->native(false)
                                    ->displayFormat('d/m/Y'),

                                DatePicker::make('vigencia_hasta')
                                    ->label('Hasta')
                                    ->native(false)
                                    ->displayFormat('d/m/Y')
                                    ->afterOrEqual('vigencia_desde')
                                    ->columnSpanFull()
                                    ->helperText('Vacío mientras siga cobrando este precio.'),
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
