<?php

declare(strict_types=1);

namespace App\Filament\Resources\Almacenes\Schemas;

use App\Domain\Enums\TipoAlmacen;
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

                CampoMayusculas::make('codigo')
                    ->label('Código')
                    ->required()
                    ->maxLength(20)
                    ->disabledOn('edit')
                    ->helperText('Único dentro de la sede.'),

                CampoMayusculas::make('nombre')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255)
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
            Select::make('tipo')
                ->label('Tipo')
                ->options(fn (): array => collect(TipoAlmacen::cases())
                    ->reject(fn (TipoAlmacen $t): bool => $t->esUnico())
                    ->mapWithKeys(fn (TipoAlmacen $t): array => [$t->value => $t->etiqueta()])
                    ->all())
                ->required()
                ->native(false)
                ->helperText('La bodega central no dispensa a paciente: traslada.'),

            Select::make('servicio_id')
                ->label('Servicio dueño')
                ->relationship('servicio', 'nombre')
                ->searchable()
                ->preload()
                ->placeholder('Ninguno — no cuelga de un área')
                ->columnSpanFull()
                ->helperText(
                    'Dejar vacío para bodega central y farmacia de venta, que no cuelgan de '
                    .'ningún área. Se elige un servicio cuando el almacén es SUYO: el carro de '
                    .'paro de emergencia o el stock de piso de hospitalización.'
                ),
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
                             * Si el hospital guarda todo en un solo almacén,
                             * los controlados también están ahí. Viene
                             * marcado por defecto y se puede desmarcar; lo
                             * contrario es que el libro de ARSA arranque
                             * apagado sin que nadie se entere.
                             */
                            ->default(self::modoUnico())
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
