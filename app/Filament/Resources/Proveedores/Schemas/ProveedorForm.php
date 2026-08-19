<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proveedores\Schemas;

use App\Filament\Schemas\Components\CampoMayusculas;
use App\Filament\Schemas\Components\RTNField;
use App\Filament\Schemas\Components\TelefonoHondurasField;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Alta de un proveedor — patrón §10.
 */
final class ProveedorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identificación')
                ->columns(2)
                ->schema([
                    CampoMayusculas::make('codigo')
                        ->label('Código')
                        ->required()
                        ->maxLength(20)
                        /*
                         * No se edita: viaja en cada entrada de compra ya
                         * confirmada, y cambiarlo dejaría compras
                         * apuntando a un proveedor que ya no se llama
                         * así.
                         */
                        ->disabledOn('edit')
                        ->helperText('Corto y sin espacios: DROG-CENTRAL, LAB-FINLAY.')
                        ->rule('regex:/^[A-Z0-9\-]{3,20}$/')
                        ->validationMessages([
                            'regex' => 'Solo letras mayúsculas, números y guiones, de 3 a 20 caracteres.',
                        ]),

                    CampoMayusculas::make('nombre')
                        ->label('Razón social')
                        ->required()
                        ->maxLength(160),

                    RTNField::make()
                        ->helperText(
                            'Opcional, pero si está no se puede repetir: dos fichas con el '
                            .'mismo RTN son la misma empresa cargada dos veces, y las compras '
                            .'quedarían repartidas entre las dos.'
                        ),

                    Toggle::make('activo')
                        ->label('Activo')
                        ->default(true)
                        ->helperText(
                            'Un proveedor con el que se dejó de trabajar se desactiva, no se '
                            .'borra: sus compras siguen apuntando a él.'
                        ),
                ]),

            Section::make('Contacto')
                ->description('Opcional, pero es lo que se busca cuando falta un pedido.')
                ->columns(2)
                ->collapsed()
                ->schema([
                    TextInput::make('contacto')
                        ->label('Persona de contacto')
                        ->maxLength(120)
                        ->helperText('Se guarda tal cual se escribe: un nombre en mayúsculas se lee como un grito.'),

                    TelefonoHondurasField::make('telefono', 'Teléfono'),

                    TextInput::make('correo')
                        ->label('Correo')
                        ->email()
                        ->maxLength(120),

                    Textarea::make('notas')
                        ->label('Notas')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
