<?php

declare(strict_types=1);

namespace App\Filament\Resources\Fusiones\Tables;

use App\Domain\Enums\EstadoFusion;
use App\Domain\Exceptions\FusionInvalidaException;
use App\Models\FusionDePersona;
use App\Models\User;
use App\Services\FusionadorDePersonas;
use Carbon\CarbonInterface;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * La bandeja de la segunda persona.
 *
 * Las tres acciones —aprobar, rechazar, deshacer— pasan por
 * `FusionadorDePersonas`. La pantalla no toca `merged_into` ni el estado:
 * si lo hiciera, la fila diría "aplicada" sin que el puntero exista, o al
 * revés, y habría dos versiones distintas de la verdad sobre si dos
 * pacientes son el mismo.
 *
 * Aprobar y rechazar solo aparecen para quien NO propuso. Es el control
 * de cuatro ojos del §9.D4, y esto es la capa cómoda: la que de verdad
 * lo impone es un CHECK en la base.
 */
final class FusionesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('duplicada.primer_apellido')
                    ->label('Se fusiona')
                    ->state(fn (FusionDePersona $record): string => $record->duplicada->nombreParaListado())
                    ->description(fn (FusionDePersona $record): string => self::nacimiento($record->duplicada->fecha_nacimiento))
                    ->wrap(),

                TextColumn::make('sobreviviente.primer_apellido')
                    ->label('En')
                    ->state(fn (FusionDePersona $record): string => $record->sobreviviente->nombreParaListado())
                    ->description(fn (FusionDePersona $record): string => self::nacimiento($record->sobreviviente->fecha_nacimiento))
                    ->wrap(),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (EstadoFusion $state): string => $state->etiqueta())
                    ->color(fn (EstadoFusion $state): string => $state->color()),

                TextColumn::make('motivo')
                    ->label('Motivo')
                    ->wrap()
                    ->limit(80)
                    ->tooltip(fn (FusionDePersona $record): string => $record->motivo),

                TextColumn::make('propuestaPor.name')
                    ->label('La propuso')
                    ->description(fn (FusionDePersona $record): string => $record->propuesta_en->format('d/m/Y H:i')),

                TextColumn::make('resueltaPor.name')
                    ->label('La resolvió')
                    ->placeholder('Esperando')
                    ->description(fn (FusionDePersona $record): ?string => $record->resuelta_en?->format('d/m/Y H:i')),
            ])
            ->defaultSort('propuesta_en', 'desc')
            ->paginated([25, 50])
            ->filters([
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(fn (): array => collect(EstadoFusion::cases())
                        ->mapWithKeys(fn (EstadoFusion $e): array => [$e->value => $e->etiqueta()])
                        ->all()),
            ], layout: FiltersLayout::AboveContent)
            ->emptyStateIcon('heroicon-o-check-circle')
            ->emptyStateHeading('No hay fusiones que revisar')
            ->emptyStateDescription(
                'Las fusiones se proponen desde la ficha del paciente, donde quien propone está '
                .'mirando los datos de los dos.'
            )
            ->recordActions([
                self::aprobar(),
                self::rechazar(),
                self::deshacer(),
            ])
            ->toolbarActions([]);
    }

    private static function aprobar(): Action
    {
        return Action::make('aprobar')
            ->label('Aprobar')
            ->icon('heroicon-o-check')
            ->color('success')
            ->visible(fn (FusionDePersona $record): bool => self::puedeResolver($record))
            ->modalHeading('Aprobar y aplicar la fusión')
            ->modalDescription(
                'Al aprobar, los dos expedientes pasan a ser uno. Es reversible: se puede deshacer '
                .'desde esta misma bandeja. Aun así, revisá que sean de verdad la misma persona — '
                .'dos pacientes unidos comparten alergias y medicación.'
            )
            ->schema([
                Textarea::make('nota')
                    ->label('Cómo lo verificaste')
                    ->placeholder('Comparé el DNI y la fecha de nacimiento contra el documento físico.')
                    ->rows(2),
            ])
            ->action(function (FusionDePersona $record, array $data): void {
                self::ejecutar(
                    fn (FusionadorDePersonas $fusionador) => $fusionador->aprobar(
                        $record,
                        self::texto($data, 'nota'),
                    ),
                    'Fusión aplicada',
                );
            });
    }

    private static function rechazar(): Action
    {
        return Action::make('rechazar')
            ->label('Rechazar')
            ->icon('heroicon-o-x-mark')
            ->color('gray')
            ->visible(fn (FusionDePersona $record): bool => self::puedeResolver($record))
            ->modalHeading('Rechazar la propuesta')
            ->modalDescription('No se une nada. La persona queda libre para que se proponga otra fusión.')
            ->schema([
                Textarea::make('nota')
                    ->label('Por qué no son la misma persona')
                    ->placeholder('Son dos hermanos con el mismo nombre; fechas de nacimiento distintas.')
                    ->required()
                    ->rows(2),
            ])
            ->action(function (FusionDePersona $record, array $data): void {
                self::ejecutar(
                    fn (FusionadorDePersonas $fusionador) => $fusionador->rechazar(
                        $record,
                        (string) ($data['nota'] ?? ''),
                    ),
                    'Propuesta rechazada',
                );
            });
    }

    private static function deshacer(): Action
    {
        return Action::make('deshacer')
            ->label('Deshacer')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(fn (FusionDePersona $record): bool => $record->estado === EstadoFusion::Aplicada)
            ->modalHeading('Separar a las dos personas')
            ->modalDescription(
                'Vuelven a ser dos pacientes distintos. Nada se pierde: la fusión solo escribió un '
                .'puntero y deshacerla lo borra.'
            )
            ->schema([
                Textarea::make('motivo')
                    ->label('Por qué se deshace')
                    ->placeholder('Se confirmó con el RNP que son dos personas distintas.')
                    ->required()
                    ->minLength(10)
                    ->rows(2)
                    ->helperText('Mínimo 10 caracteres: la base lo exige.'),
            ])
            ->action(function (FusionDePersona $record, array $data): void {
                self::ejecutar(
                    fn (FusionadorDePersonas $fusionador) => $fusionador->deshacer(
                        $record,
                        (string) ($data['motivo'] ?? ''),
                    ),
                    'Fusión deshecha',
                );
            });
    }

    /**
     * Corre la operación y traduce el error de dominio a un aviso legible.
     *
     * Los mensajes de FusionInvalidaException están escritos para quien
     * está en el mostrador, así que se muestran tal cual en vez de
     * envolverlos en un "ocurrió un error".
     *
     * @param Closure(FusionadorDePersonas): mixed $operacion
     */
    private static function ejecutar(Closure $operacion, string $exito): void
    {
        try {
            $operacion(app(FusionadorDePersonas::class));
        } catch (FusionInvalidaException $e) {
            Notification::make()
                ->danger()
                ->persistent()
                ->title('No se pudo completar')
                ->body($e->getMessage())
                ->send();

            throw new Halt;
        }

        Notification::make()->success()->title($exito)->send();
    }

    private static function puedeResolver(FusionDePersona $fusion): bool
    {
        $usuario = auth()->user();

        return $usuario instanceof User && $fusion->puedeResolverla($usuario);
    }

    private static function nacimiento(?CarbonInterface $fecha): string
    {
        return $fecha === null ? 'Sin fecha de nacimiento' : 'Nació el '.$fecha->format('d/m/Y');
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function texto(array $data, string $campo): ?string
    {
        $valor = $data[$campo] ?? null;

        if (! is_string($valor)) {
            return null;
        }

        $valor = trim($valor);

        return $valor === '' ? null : $valor;
    }
}
