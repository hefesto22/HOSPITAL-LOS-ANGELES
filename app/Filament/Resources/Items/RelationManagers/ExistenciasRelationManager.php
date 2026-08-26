<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\RelationManagers;

use App\Domain\ValueObjects\Decimal;
use App\Models\Existencia;
use App\Models\Item;
use App\Models\ItemPresentacion;
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

                /*
                 * ─────────────────────────────────────────────────────
                 * DE QUÉ ENVASE SALIÓ ESTE LOTE
                 * ─────────────────────────────────────────────────────
                 *
                 * Sin esta columna, tres compras del mismo jarabe —diez
                 * frascos de 60, diez de 80 y diez de 120— se leen como
                 * tres filas de mililitros que no se distinguen entre sí.
                 * Con ella, cada fila dice qué hay que ir a buscar al
                 * estante.
                 */
                TextColumn::make('lote.presentacion.nombre')
                    ->label('Presentación')
                    ->placeholder('Sin envase declarado')
                    ->description(fn (Existencia $record): ?string => $record->lote?->presentacion?->unidad?->codigo),

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
                    ->suffix(fn (): string => ' '.$this->unidadDeDispensacion())
                    ->description(fn (Existencia $record): ?string => $this->enEnvases($record), position: 'below'),
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
     * ─────────────────────────────────────────────────────────────────
     * 🔴 LO MISMO, CONTADO EN ENVASES
     * ─────────────────────────────────────────────────────────────────
     *
     * El kardex se lleva en unidad de dispensación y eso no se toca: es
     * lo que permite cobrar media dosis. Pero en el estante no hay 600
     * mililitros sueltos, hay diez frascos de 60 — y si el paciente
     * necesita 100 ml, «600 ML» dice que alcanza mientras que «10 frascos
     * de 60» dice que hay que abrir dos.
     *
     * ⚠️ Se TRUNCA, no se redondea. 580 ml en frascos de 60 son NUEVE
     * frascos y 40 ml sueltos, no diez: redondear declararía cerrado un
     * frasco que está abierto y a medias, que es justo lo que esta línea
     * existe para mostrar.
     *
     * Dice «envases» y no «frascos» a propósito. El código del envase va
     * en su propia columna; pluralizar uno arbitrario en español no se
     * resuelve agregando una ese —BLÍSTER, CAJA, AMPOLLA— y una etiqueta
     * mal conjugada en una pantalla de inventario se lee como un error
     * del sistema.
     */
    private function enEnvases(Existencia $existencia): ?string
    {
        $presentacion = $existencia->lote?->presentacion;

        if (! $presentacion instanceof ItemPresentacion) {
            return null;
        }

        $porEnvase = Decimal::de($presentacion->unidades_por_presentacion);

        if ($porEnvase->esCero() || $porEnvase->esNegativo()) {
            return null;
        }

        $cantidad = Decimal::de($existencia->cantidad);
        $enteros = (int) explode('.', $cantidad->entre($porEnvase)->exacto())[0];

        if ($enteros < 1) {
            return null;
        }

        $unidad = $this->unidadDeDispensacion();
        $porEnvaseLegible = rtrim(rtrim($porEnvase->redondeado(4), '0'), '.');
        $envases = $enteros === 1 ? '1 envase' : $enteros.' envases';

        $sueltas = $cantidad->restar($porEnvase->por($enteros));

        if ($sueltas->esCero()) {
            return $envases.' de '.$porEnvaseLegible.' '.$unidad;
        }

        return $envases.' y '.rtrim(rtrim($sueltas->redondeado(4), '0'), '.').' '.$unidad;
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
