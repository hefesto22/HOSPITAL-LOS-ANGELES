<?php

declare(strict_types=1);

namespace App\Filament\Resources\Facturas\Pages;

use App\Domain\Enums\EstadoFactura;
use App\Filament\Resources\Facturas\FacturaResource;
use App\Models\Factura;
use Carbon\CarbonInterface;
use Closure;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListFacturas extends ListRecords
{
    protected static string $resource = FacturaResource::class;

    public function getSubheading(): ?string
    {
        /*
         * Sin subtítulo. Decía cómo se emite una factura en la pantalla
         * donde NO se emite ninguna: quien llega acá viene a buscar una
         * que ya existe, y ese párrafo solo empujaba la tabla hacia
         * abajo. Lo que hay que saber para emitir está en la cuenta, que
         * es donde se emite.
         */
        return null;
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * LAS TRES SITUACIONES DE UNA FACTURA, ARRIBA Y A LA VISTA
     * ─────────────────────────────────────────────────────────────────
     *
     * No son tres filtros: son tres montones distintos, y quien entra
     * acá ya sabe cuál quiere ver antes de abrir la pantalla.
     *
     *   · VIGENTES — emitidas y todavía dentro del plazo. Es el único
     *     montón sobre el que se puede actuar, y por eso es el que se
     *     abre por defecto.
     *
     *   · DECLARADAS — el mes ya viajó al SAR. Quedaron firmes: se
     *     consultan y se reimprimen, no se tocan.
     *
     *   · ANULADAS — alguien las anuló y quedó el motivo escrito. El
     *     número sigue consumido; el SAR audita la secuencia.
     *
     * ⚠️ «Declarada» y «anulada» suenan parecido y no son lo mismo. Una
     * de agosto puede estar anulada y sin declarar; una de mayo está
     * declarada aunque nadie la haya tocado nunca. Por eso son pestañas
     * separadas y no un solo selector de estado.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'vigentes' => Tab::make('Vigentes')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->badge(fn (): int => $this->contar(fn (Builder $query): Builder => self::vigentes($query)))
                ->modifyQueryUsing(fn (Builder $query): Builder => self::vigentes($query)),

            'declaradas' => Tab::make('Declaradas')
                ->icon(Heroicon::OutlinedLockClosed)
                ->modifyQueryUsing(fn (Builder $query): Builder => self::declaradas($query)),

            'anuladas' => Tab::make('Anuladas')
                ->icon(Heroicon::OutlinedXCircle)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('estado', EstadoFactura::Anulada->value)),

            'todas' => Tab::make('Todas'),
        ];
    }

    /**
     * Emitidas y todavía anulables.
     *
     * @param Builder<Factura> $query
     *
     * @return Builder<Factura>
     */
    private static function vigentes(Builder $query): Builder
    {
        return $query
            ->where('estado', EstadoFactura::Emitida->value)
            ->whereDate('fecha_operacion', '>=', self::primerDiaSinDeclarar()->toDateString());
    }

    /**
     * Las que ya viajaron en una declaración, anuladas o no.
     *
     * ⚠️ El corte se calcula en PHP y se aplica como una comparación de
     * fecha. Hacerlo con aritmética de fechas en SQL sería la misma
     * regla escrita dos veces, y el día que el contador mueva el día 9
     * solo se acordaría una de las dos.
     *
     * @param Builder<Factura> $query
     *
     * @return Builder<Factura>
     */
    private static function declaradas(Builder $query): Builder
    {
        return $query->whereDate('fecha_operacion', '<', self::primerDiaSinDeclarar()->toDateString());
    }

    /**
     * Desde qué día las facturas todavía se pueden anular.
     *
     * Hasta el 9 de agosto se puede anular julio, así que ese día lo NO
     * declarado arranca el 1 de julio. Del 10 en adelante arranca el 1
     * de agosto.
     */
    private static function primerDiaSinDeclarar(): CarbonInterface
    {
        $dia = (int) config('sihla.facturacion.dia_limite_anulacion', 9);
        $hoy = now();

        return $hoy->day <= max(1, $dia)
            ? $hoy->copy()->startOfMonth()->subMonth()
            : $hoy->copy()->startOfMonth();
    }

    /**
     * El número de la pestaña de vigentes: es el único que hay que mirar
     * todos los días —lo que todavía se puede corregir— y por eso es el
     * único que lleva contador. Contar los otros dos son dos consultas
     * por pintada para números que nadie necesita al segundo.
     *
     * @param Closure(Builder<Factura>): Builder<Factura> $criterio
     */
    private function contar(Closure $criterio): int
    {
        return $criterio(Factura::query())->count();
    }
}
