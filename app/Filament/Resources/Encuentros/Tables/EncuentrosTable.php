<?php

declare(strict_types=1);

namespace App\Filament\Resources\Encuentros\Tables;

use App\Domain\Enums\EstadoEncuentro;
use App\Domain\Enums\TipoEncuentro;
use App\Models\Encuentro;
use App\Models\Persona;
use Carbon\CarbonInterface;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * ─────────────────────────────────────────────────────────────────────
 * CADA ATENCIÓN, Y EN QUÉ PASO DEL EGRESO VA (12-oct-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * Antes eran siete columnas y tres casi siempre decían «—»: servicio,
 * alta médica y salida física. Ahora son cuatro:
 *
 *   · PACIENTE — el nombre, que es lo que se reconoce, con el número
 *     del encuentro y del expediente debajo.
 *   · TIPO — con su color y su ícono.
 *   · INGRESO — la fecha y cuánto lleva («lleva 33 días»), que en una
 *     hospitalización es lo primero que se pregunta.
 *   · ESTADO — y debajo el paso del egreso en que va, con su hora: «alta
 *     médica 12/10 10:00 · falta liquidar». Los tres tiempos de egreso
 *     del §9.K8 siguen guardados aparte; acá se lee el último que pasó y
 *     el que falta.
 *
 * Las etapas (en curso, por egresar, cerrados) son pestañas en
 * «ListEncuentros», no un filtro de estado escondido.
 */
final class EncuentrosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('abierto_en', 'desc')
            ->paginated([25, 50, 100])
            ->deferLoading()
            ->columns([
                TextColumn::make('persona')
                    ->label('Paciente')
                    ->state(fn (Encuentro $record): string => $record->persona->nombreCompleto())
                    ->description(fn (Encuentro $record): string => $record->numero.' · '.$record->expediente->numero)
                    ->weight('semibold')
                    ->wrap()
                    ->grow()
                    /*
                     * Por nombre (con el mismo parecido que admisión), o
                     * por número de encuentro o de expediente.
                     */
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('numero', 'ilike', '%'.$search.'%')
                        ->orWhereHas('expediente', fn (Builder $expediente): Builder => $expediente
                            ->where('numero', 'ilike', '%'.$search.'%'))
                        ->orWhereIn('persona_id', Persona::query()->buscarPorNombre($search)->select('id'))),

                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->icon(fn (TipoEncuentro $state): string => $state->icono())
                    ->formatStateUsing(fn (TipoEncuentro $state): string => $state->etiqueta())
                    ->color(fn (TipoEncuentro $state): string => $state->color()),

                TextColumn::make('abierto_en')
                    ->label('Ingreso')
                    ->dateTime('d/m/Y H:i')
                    ->description(fn (Encuentro $record): string => self::estancia($record))
                    ->sortable(),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (EstadoEncuentro $state): string => $state->etiqueta())
                    ->color(fn (EstadoEncuentro $state): string => $state->color())
                    ->description(fn (Encuentro $record): string => self::pasoDelEgreso($record)),

                TextColumn::make('servicio.nombre')
                    ->label('Servicio')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->options(fn (): array => TipoEncuentro::opciones()),
            ])
            ->emptyStateIcon('heroicon-o-clipboard-document-list')
            ->emptyStateHeading('No hay atenciones acá')
            ->emptyStateDescription('Las atenciones se abren desde «Cuentas abiertas», junto con su cuenta.')
            ->recordActions([ViewAction::make()->label('Ver')])
            ->toolbarActions([]);
    }

    /**
     * «Lleva 33 días» mientras está adentro; «estuvo 3 días» cuando ya
     * salió. Por debajo de un día, en horas: una consulta de la mañana no
     * «lleva 0 días».
     */
    private static function estancia(Encuentro $encuentro): string
    {
        $fin = $encuentro->salida_fisica_en ?? $encuentro->cerrado_en ?? $encuentro->anulado_en;
        $sigue = ! $fin instanceof CarbonInterface;
        $hasta = $fin ?? now();

        $dias = (int) $encuentro->abierto_en->diffInDays($hasta);

        if ($dias < 1) {
            $horas = (int) $encuentro->abierto_en->diffInHours($hasta);
            $cuanto = $horas < 1 ? 'menos de una hora' : ($horas === 1 ? '1 hora' : "{$horas} horas");
        } else {
            $cuanto = $dias === 1 ? '1 día' : "{$dias} días";
        }

        return ($sigue ? 'Lleva ' : 'Estuvo ').$cuanto;
    }

    /**
     * El último paso del egreso que pasó, con su hora, y el que falta.
     */
    private static function pasoDelEgreso(Encuentro $encuentro): string
    {
        return match ($encuentro->estado) {
            EstadoEncuentro::Abierto,
            EstadoEncuentro::EnAtencion         => 'Sin alta médica',
            EstadoEncuentro::AltaMedica         => 'Alta médica '.self::hora($encuentro->alta_medica_en).' · falta liquidar',
            EstadoEncuentro::AltaAdministrativa => 'Liquidada '.self::hora($encuentro->alta_administrativa_en).' · falta la salida',
            EstadoEncuentro::Cerrado            => 'Salió '.self::hora($encuentro->salida_fisica_en ?? $encuentro->cerrado_en),
            EstadoEncuentro::Anulado            => 'Anulado '.self::hora($encuentro->anulado_en),
        };
    }

    private static function hora(?CarbonInterface $momento): string
    {
        return $momento?->format('d/m H:i') ?? '';
    }
}
