<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sedes\Schemas;

use App\Filament\Schemas\Components\CampoMayusculas;
use App\Filament\Schemas\Components\RTNField;
use App\Filament\Schemas\Components\TelefonoHondurasField;
use App\Models\Sede;
use App\Support\ImageOptimizer;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Formulario de Sede — patrón aprobado del §10.
 */
final class SedeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('sede')
                ->persistTabInQueryString()
                ->columnSpanFull()
                ->tabs([
                    self::identificacion(),
                    self::fiscalYContacto(),
                    self::estado(),
                ]),
        ]);
    }

    private static function identificacion(): Tab
    {
        return Tab::make('Identificación')
            ->icon('heroicon-o-building-office-2')
            ->schema([
                CampoMayusculas::make('codigo')
                    ->label('Código')
                    ->required()
                    ->maxLength(10)
                    ->disabledOn('edit')
                    ->helperText(
                        'Prefijo de los números visibles de esta sede: expediente, factura y '
                        .'accession de imágenes. No se puede cambiar después de emitir el primer '
                        .'documento, porque quedaría un histórico con dos prefijos distintos.'
                    ),

                CampoMayusculas::make('nombre')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255)
                    ->columnSpan(2),

                /*
                 * ─────────────────────────────────────────────────────
                 * EL MEMBRETE DE LA FACTURA
                 * ─────────────────────────────────────────────────────
                 *
                 * ⚠️ NO es el logo del panel. Ese vive en «Identidad
                 * visual» y es la marca del sistema —lo que se ve al
                 * entrar y en la pestaña del navegador—. Este es el que
                 * va impreso arriba del documento fiscal, y por eso es
                 * de la SEDE: el día que el hospital abra una segunda,
                 * cada una imprime la suya al lado de su propio RTN.
                 *
                 * 🔴 Se convierte a WebP al guardar, con el mismo
                 * `ImageOptimizer` del panel. Un logo que alguien sube
                 * desde su teléfono son tres megas de PNG que después
                 * viajan en cada impresión; en WebP son unos pocos kilos
                 * y se ve igual. El SVG se guarda tal cual: es vectorial
                 * y convertirlo sería empeorarlo.
                 */
                FileUpload::make('logo_path')
                    ->label('Logo de la factura')
                    ->columnSpanFull()
                    ->image()
                    ->imageEditor()
                    ->disk('public')
                    ->directory('sedes')
                    ->visibility('public')
                    ->maxSize(5120)
                    ->acceptedFileTypes([
                        'image/png',
                        'image/jpeg',
                        'image/svg+xml',
                        'image/webp',
                    ])
                    ->saveUploadedFileUsing(
                        static fn (TemporaryUploadedFile $file): string => ImageOptimizer::toWebp($file, 'sedes'),
                    )
                    ->helperText(
                        'Sale impreso arriba de la factura, junto al nombre. PNG, JPG, WebP o SVG, '
                        .'hasta 5 MB: se convierte solo a WebP para que no pese. Si se deja vacío, '
                        .'la factura sale con el nombre en texto, como hasta ahora.'
                    ),
            ])
            ->columns(3);
    }

    private static function fiscalYContacto(): Tab
    {
        return Tab::make('Fiscal y contacto')
            ->icon('heroicon-o-document-text')
            ->schema([
                Section::make('Identidad fiscal')
                    ->description(
                        'El SAR autoriza el CAI y los rangos POR ESTABLECIMIENTO, y SESAL habilita '
                        .'por establecimiento. Estos datos no son de la empresa: son de esta sede.'
                    )
                    ->icon('heroicon-o-receipt-percent')
                    ->schema([
                        CampoMayusculas::make('razon_social')
                            ->label('Razón social')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),

                        RTNField::make('rtn'),

                        TextInput::make('codigo_establecimiento')
                            ->label('Código de establecimiento (SAR)')
                            ->maxLength(3)
                            ->mask('999')
                            ->helperText('Los 3 primeros dígitos del correlativo: NNN-NNN-NN-NNNNNNNN.'),

                        CampoMayusculas::make('registro_sesal')
                            ->label('Registro / habilitación SESAL')
                            ->maxLength(255),
                    ])
                    ->columns(2),

                Section::make('Contacto')
                    ->icon('heroicon-o-map-pin')
                    ->schema([
                        CampoMayusculas::make('direccion')
                            ->label('Dirección')
                            ->maxLength(255)
                            ->columnSpanFull(),

                        TelefonoHondurasField::make('telefono'),

                        TextInput::make('email')
                            ->label('Correo electrónico')
                            ->email()
                            ->maxLength(255),
                    ])
                    ->columns(2),
            ]);
    }

    private static function estado(): Tab
    {
        return Tab::make('Estado')
            ->icon('heroicon-o-signal')
            ->schema([
                Section::make('Vigencia')
                    ->description(
                        'La sede no se activa ni se desactiva con un interruptor: tiene vigencia. '
                        .'Una sede que cierra deja de ser seleccionable hoy y sigue explicando una '
                        .'factura de hace dos años.'
                    )
                    ->schema([
                        DatePicker::make('vigencia_desde')
                            ->label('Vigente desde')
                            ->required()
                            ->default(now()->startOfYear())
                            ->native(false)
                            ->displayFormat('d/m/Y'),

                        DatePicker::make('vigencia_hasta')
                            ->label('Vigente hasta')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->afterOrEqual('vigencia_desde')
                            ->helperText('Dejar vacío mientras la sede siga abierta.'),
                    ])
                    ->columns(2),

                Section::make('Información del registro')
                    ->icon('heroicon-o-information-circle')
                    ->visibleOn('edit')
                    ->schema([
                        TextEntry::make('servicios_count')
                            ->label('Servicios / áreas')
                            ->state(fn (?Sede $record): int => $record?->servicios()->count() ?? 0)
                            ->badge()
                            ->color('info'),

                        TextEntry::make('almacenes_count')
                            ->label('Almacenes')
                            ->state(fn (?Sede $record): int => $record?->almacenes()->count() ?? 0)
                            ->badge()
                            ->color('info'),

                        TextEntry::make('usuarios_count')
                            ->label('Usuarios asignados')
                            ->state(fn (?Sede $record): int => $record?->usuarios()->count() ?? 0)
                            ->badge()
                            ->color('gray'),

                        TextEntry::make('created_at')
                            ->label('Creada')
                            ->dateTime('d/m/Y H:i'),

                        TextEntry::make('createdBy.name')
                            ->label('Creada por')
                            ->placeholder('Sistema'),

                        TextEntry::make('updated_at')
                            ->label('Última modificación')
                            ->dateTime('d/m/Y H:i'),
                    ])
                    ->columns(3),
            ]);
    }
}
