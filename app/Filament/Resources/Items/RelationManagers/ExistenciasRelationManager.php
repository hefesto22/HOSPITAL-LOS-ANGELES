<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\RelationManagers;

use App\Models\Existencia;
use App\Models\Item;
use App\Models\Unidad;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Cuánto hay de este ítem, lote por lote y almacén por almacén.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ACÁ SE MIRA, NO SE TOCA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Ninguna acción de escritura, y no es que falte: **una existencia no se
 * edita a mano**. Se mueve con un movimiento de kardex que deja constancia
 * de qué pasó, cuándo y por qué. Un campo editable acá sería la puerta
 * para cuadrar un faltante sin explicarlo — que es exactamente lo que el
 * kardex append-only existe para impedir.
 *
 * Las entradas de compra y los ajustes llegan en el siguiente incremento,
 * cada uno con su motivo obligatorio.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL VENCIMIENTO SE VE ANTES DE QUE DUELA
 * ─────────────────────────────────────────────────────────────────────
 *
 * La columna de vencimiento se pinta sola: rojo lo vencido, ámbar lo que
 * vence dentro de noventa días. Un medicamento vencido en el estante es un
 * hallazgo de ARSA; verlo tres meses antes es lo que permite rotarlo o
 * devolverlo al proveedor mientras todavía vale algo.
 */
class ExistenciasRelationManager extends RelationManager
{
    protected static string $relationship = 'existencias';

    protected static ?string $title = 'Existencias';

    /**
     * Cuántos días antes se enciende la alerta ámbar.
     */
    private const DIAS_DE_AVISO = 90;

    protected static function getModelLabel(): ?string
    {
        return 'existencia';
    }

    protected static function getPluralModelLabel(): ?string
    {
        return 'existencias';
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('almacen.nombre')
                    ->label('Almacén')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('lote.numero')
                    ->label('Lote')
                    ->placeholder('Sin lote')
                    ->description(fn (Existencia $record): ?string => $record->lote?->proveedor),

                TextColumn::make('lote.fecha_vencimiento')
                    ->label('Vence')
                    ->date('d/m/Y')
                    ->placeholder('No vence')
                    ->badge()
                    ->color(fn (Existencia $record): string => self::colorDelVencimiento($record))
                    ->description(fn (Existencia $record): ?string => self::avisoDeVencimiento($record))
                    ->sortable(),

                TextColumn::make('cantidad')
                    ->label('Cantidad')
                    ->alignEnd()
                    ->weight('bold')
                    ->formatStateUsing(fn (string $state): string => rtrim(rtrim($state, '0'), '.'))
                    ->suffix(fn (): string => ' '.$this->unidadDeDispensacion()),
            ])
            ->defaultSort('cantidad', 'desc')
            ->paginated([10, 25, 50])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateIcon('heroicon-o-archive-box')
            ->emptyStateHeading('Sin existencias registradas')
            ->emptyStateDescription(
                'Las entradas de compra llegan en el próximo incremento. Una existencia no se '
                .'escribe a mano: se mueve con un movimiento de kardex que deja constancia.'
            );
    }

    /**
     * Solo en lo que ocupa lugar en un estante. Una consulta médica no
     * tiene existencia.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Item && $ownerRecord->mueveInventario();
    }

    // ── Textos y colores que dependen del lote ────────────────────────

    private static function colorDelVencimiento(Existencia $existencia): string
    {
        $dias = $existencia->lote?->diasParaVencerDesde(now());

        return match (true) {
            $dias === null               => 'gray',
            $dias < 0                    => 'danger',
            $dias <= self::DIAS_DE_AVISO => 'warning',
            default                      => 'success',
        };
    }

    private static function avisoDeVencimiento(Existencia $existencia): ?string
    {
        $dias = $existencia->lote?->diasParaVencerDesde(now());

        return match (true) {
            $dias === null               => null,
            $dias < 0                    => 'Vencido hace '.abs($dias).' días',
            $dias === 0                  => 'Vence hoy',
            $dias <= self::DIAS_DE_AVISO => "Vence en {$dias} días",
            default                      => null,
        };
    }

    /**
     * El CÓDIGO de la unidad y no su nombre, por lo mismo que en
     * presentaciones: «100 TABLETA» se lee mal y pluralizar en español no
     * se resuelve agregando una ese.
     */
    private function unidadDeDispensacion(): string
    {
        $duenio = $this->getOwnerRecord();

        if (! $duenio instanceof Item) {
            return 'unidades';
        }

        $unidad = $duenio->unidadDispensacion;

        return $unidad instanceof Unidad ? $unidad->codigo : 'unidades';
    }
}
