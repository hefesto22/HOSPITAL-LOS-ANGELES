<?php

declare(strict_types=1);

namespace App\Filament\Resources\Presupuestos\Actions;

use App\Filament\Schemas\Components\CampoMayusculas;
use App\Models\PlantillaPresupuesto;
use App\Models\Presupuesto;
use App\Services\GeneradorDePlantilla;
use App\Support\TextoCanonico;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * Guarda esta cotización como plantilla para la próxima cirugía igual.
 *
 * 🔴 UNA CIRUGÍA, UNA PLANTILLA. Si el código ya existe, REEMPLAZA sus
 * renglones — no crea una segunda. El aviso sale antes de confirmar.
 */
class GuardarComoPlantillaAction
{
    public static function make(): Action
    {
        return Action::make('guardar_como_plantilla')
            /*
             * 🔴 LA ETIQUETA CAMBIA SEGÚN DE DÓNDE VINO EL PRESUPUESTO.
             *
             * Si salió de una plantilla, este botón NO crea otra: le
             * devuelve a ESA los renglones que este caso enseñó. Decirle
             * «guardar como plantilla» cuando ya existe una invita a
             * crear la segunda APENDICECTOMIA, que es justo lo que la
             * regla de «una cirugía, una plantilla» evita.
             */
            ->label(fn (Presupuesto $record): string => $record->plantilla_id === null
                ? 'Guardar como plantilla'
                : 'Actualizar plantilla')
            ->icon(Heroicon::OutlinedBookmarkSquare)
            ->color('info')
            ->visible(fn (Presupuesto $record): bool => $record->detalle()->count() > 0)
            ->modalHeading(fn (Presupuesto $record): string => $record->plantilla_id === null
                ? 'Guardar esta cotización como plantilla'
                : "Actualizar «{$record->plantilla?->nombre}» con esta cotización")
            ->modalDescription(
                'Se guardan los ítems y las cantidades, NO los precios: la próxima vez se recotizan con el tarifario del pagador que corresponda.'
            )
            ->modalSubmitActionLabel('Guardar')
            /*
             * ⚠️ Con plantilla se precarga SU código, no uno derivado del
             * título. Derivarlo producía `CX-APENDICECTOMIA` para un
             * presupuesto salido de `CX-APENDICE`: en vez de reemplazar,
             * creaba la duplicada.
             */
            ->fillForm(fn (Presupuesto $record): array => $record->plantilla !== null
                ? [
                    'codigo'        => $record->plantilla->codigo,
                    'nombre'        => $record->plantilla->nombre,
                    'descripcion'   => $record->plantilla->descripcion,
                    'dias_vigencia' => $record->plantilla->dias_vigencia,
                ]
                : [
                    'codigo' => 'CX-'.mb_substr(TextoCanonico::mayusculas($record->titulo) ?? 'NUEVA', 0, 20),
                    'nombre' => $record->titulo,
                ])
            ->schema([
                CampoMayusculas::make('codigo')
                    ->label('Código')
                    ->required()
                    ->maxLength(30)
                    ->live(onBlur: true)
                    ->helperText(function (mixed $state): string {
                        $existente = self::plantillaCon($state);

                        if (! $existente instanceof PlantillaPresupuesto) {
                            return 'Código nuevo: se crea una plantilla.';
                        }

                        $cuantos = $existente->lineas()->count();

                        return "⚠️ Ya existe «{$existente->nombre}» con {$cuantos} renglones. Guardar REEMPLAZA sus renglones por los de esta cotización.";
                    }),

                CampoMayusculas::make('nombre')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(150)
                    ->helperText('Como lo va a buscar quien cotice la próxima.'),

                Textarea::make('descripcion')
                    ->label('Descripción')
                    ->maxLength(300)
                    ->rows(2)
                    ->helperText('Qué caso cubre y qué da por supuesto — «sin complicaciones, tres días de estancia».'),

                TextInput::make('dias_vigencia')
                    ->label('Días que vale el presupuesto')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(365)
                    ->default(15)
                    ->required(),
            ])
            ->action(function (Presupuesto $record, array $data): void {
                abort_unless(Gate::allows('create', PlantillaPresupuesto::class), 403);

                $dias = $data['dias_vigencia'] ?? null;

                $resultado = app(GeneradorDePlantilla::class)->desdePresupuesto(
                    presupuesto: $record,
                    codigo: TextoCanonico::mayusculas(is_string($data['codigo'] ?? null) ? $data['codigo'] : '') ?? '',
                    nombre: is_string($data['nombre'] ?? null) ? $data['nombre'] : $record->titulo,
                    descripcion: is_string($data['descripcion'] ?? null) ? $data['descripcion'] : null,
                    diasVigencia: is_numeric($dias) ? (int) $dias : null,
                );

                $titulo = $resultado->reemplazo
                    ? "Plantilla «{$resultado->plantilla->nombre}» actualizada"
                    : "Plantilla «{$resultado->plantilla->nombre}» creada";

                /*
                 * ⚠️ Los omitidos se AVISAN, no se esconden. Son los
                 * renglones sin ítem del catálogo —el honorario escrito a
                 * mano, la holgura— y si nadie los nombra, la próxima
                 * cirugía se cotiza sin ellos.
                 */
                if (! $resultado->hayOmitidos()) {
                    Notification::make()
                        ->success()
                        ->title($titulo)
                        ->body("{$resultado->copiados} renglones guardados.")
                        ->send();

                    return;
                }

                $lista = implode(', ', array_slice($resultado->omitidos, 0, 3));
                $mas = count($resultado->omitidos) > 3
                    ? ' y '.(count($resultado->omitidos) - 3).' más'
                    : '';

                Notification::make()
                    ->warning()
                    ->title($titulo)
                    ->body(
                        "{$resultado->copiados} renglones guardados. QUEDARON FUERA por no tener ítem del catálogo: {$lista}{$mas}. "
                        .'Para que entren la próxima vez, hay que darlos de alta en el catálogo.'
                    )
                    ->persistent()
                    ->send();
            });
    }

    private static function plantillaCon(mixed $codigo): ?PlantillaPresupuesto
    {
        if (! is_string($codigo) || trim($codigo) === '') {
            return null;
        }

        return PlantillaPresupuesto::query()
            ->where('codigo', TextoCanonico::mayusculas($codigo))
            ->first();
    }
}
