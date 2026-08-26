<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cuentas\Schemas;

use App\Domain\Enums\EstadoCuenta;
use App\Models\Cuenta;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

/**
 * La ficha de la cuenta, con el patrón de pestañas ya validado (§10).
 *
 * La pestaña del dinero separa EXENTO de GRAVADO y ISV porque es
 * exactamente lo que el formato fiscal hondureño exige totalizar por
 * separado (§8.6.1-3), y porque es lo primero que alguien va a querer
 * cruzar cuando la factura no cuadre.
 */
final class CuentaInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Cuenta')
                ->persistTabInQueryString()
                ->columnSpanFull()
                ->tabs([
                    Tab::make('Identificación')
                        ->columns(3)
                        ->schema([
                            TextEntry::make('numero')->label('Número de cuenta')->copyable(),

                            TextEntry::make('encuentro.numero')->label('Encuentro'),

                            TextEntry::make('paciente')
                                ->label('Paciente')
                                ->state(fn (Cuenta $record): string => $record->encuentro->persona->nombreCompleto()),

                            TextEntry::make('encuentro.tipo')
                                ->label('Tipo de atención')
                                ->formatStateUsing(fn (Cuenta $record): string => $record->encuentro->tipo->etiqueta())
                                ->badge()
                                ->color(fn (Cuenta $record): string => $record->encuentro->tipo->color()),

                            TextEntry::make('encuentro.abierto_en')
                                ->label('Ingreso')
                                ->dateTime('d/m/Y H:i'),

                            TextEntry::make('encuentro.servicio.nombre')
                                ->label('Servicio')
                                ->placeholder('—'),
                        ]),

                    Tab::make('Pagador')
                        ->columns(3)
                        ->schema([
                            TextEntry::make('convenio.nombre')->label('Pagador')->badge(),
                            TextEntry::make('numero_poliza')->label('Póliza')->placeholder('—'),
                            TextEntry::make('numero_autorizacion')
                                ->label('Autorización')
                                ->placeholder('Sin autorización previa')
                                ->helperText(
                                    'Un cargo sin autorización nace con riesgo de rechazo. '
                                    .'Descubrirlo en la conciliación a 60 días es descubrir que el dinero ya se perdió.'
                                ),

                            TextEntry::make('convenio.cobertura_fraccion')
                                ->label('Cobertura pactada')
                                ->formatStateUsing(fn (Cuenta $record): string => number_format(
                                    (float) $record->convenio->cobertura_fraccion * 100,
                                    1
                                ).' %'),

                            TextEntry::make('convenio.tope_por_evento')
                                ->label('Tope por evento')
                                ->placeholder('Sin tope')
                                ->formatStateUsing(fn (mixed $state): string => $state === null
                                    ? 'Sin tope'
                                    : 'L '.number_format((float) $state, 2)),

                            TextEntry::make('responsable.primer_apellido')
                                ->label('Responsable económico')
                                ->placeholder('El propio paciente'),
                        ]),

                    Tab::make('Dinero')
                        ->columns(3)
                        ->schema([
                            Section::make('Totales de la cuenta')
                                ->columns(3)
                                ->columnSpanFull()
                                ->description(
                                    'La cuenta acumula; la factura es una proyección de la cuenta a un '
                                    .'instante y se emite una sola vez (§8.6.3).'
                                )
                                ->schema([
                                    TextEntry::make('total_bruto')
                                        ->label('Bruto')
                                        ->formatStateUsing(fn (mixed $state): string => 'L '.number_format((float) $state, 2)),

                                    TextEntry::make('total_descuento')
                                        ->label('Descuentos')
                                        ->formatStateUsing(fn (mixed $state): string => 'L '.number_format((float) $state, 2)),

                                    TextEntry::make('lineas')->label('Ítems'),

                                    TextEntry::make('total_exento')
                                        ->label('Importe exento')
                                        ->formatStateUsing(fn (mixed $state): string => 'L '.number_format((float) $state, 2))
                                        ->helperText('Servicios médicos y medicamentos: Art. 15 b y d de la Ley del ISV.'),

                                    TextEntry::make('total_gravado')
                                        ->label('Importe gravado')
                                        ->formatStateUsing(fn (mixed $state): string => 'L '.number_format((float) $state, 2)),

                                    TextEntry::make('total_isv')
                                        ->label('ISV')
                                        ->formatStateUsing(fn (mixed $state): string => 'L '.number_format((float) $state, 2)),

                                    TextEntry::make('total')
                                        ->label('Total de la cuenta')
                                        ->weight('bold')
                                        ->size('lg')
                                        ->formatStateUsing(fn (Cuenta $record): string => $record->saldo()->formateado()),

                                    TextEntry::make('total_paciente')
                                        ->label('Le toca al paciente')
                                        ->weight('bold')
                                        ->formatStateUsing(fn (Cuenta $record): string => $record->saldoDelPaciente()->formateado()),

                                    TextEntry::make('total_aseguradora')
                                        ->label('Le toca a la aseguradora')
                                        ->formatStateUsing(fn (Cuenta $record): string => $record->saldoDeLaAseguradora()->formateado()),
                                ]),
                        ]),

                    Tab::make('Estado')
                        ->columns(3)
                        ->schema([
                            TextEntry::make('estado')
                                ->label('Estado')
                                ->badge()
                                ->formatStateUsing(fn (EstadoCuenta $state): string => $state->etiqueta())
                                ->color(fn (EstadoCuenta $state): string => $state->color()),

                            TextEntry::make('abierta_en')->label('Abierta')->dateTime('d/m/Y H:i'),
                            TextEntry::make('congelada_en')->label('Congelada')->dateTime('d/m/Y H:i')->placeholder('—'),
                            TextEntry::make('cerrada_en')->label('Cerrada')->dateTime('d/m/Y H:i')->placeholder('—'),
                            TextEntry::make('motivo_apertura')->label('Motivo de apertura')->placeholder('—')->columnSpanFull(),
                            TextEntry::make('motivo_anulacion')->label('Motivo de anulación')->placeholder('—')->columnSpanFull(),

                            TextEntry::make('cuentaAnterior.numero')
                                ->label('Viene de la cuenta')
                                ->placeholder('—')
                                ->helperText('Cuando cambia el pagador, los cargos pendientes se trasladan y queda el rastro.'),

                            TextEntry::make('created_at')->label('Creada')->dateTime('d/m/Y H:i'),
                            TextEntry::make('updated_at')->label('Último cambio')->dateTime('d/m/Y H:i'),
                        ]),
                ]),
        ]);
    }
}
