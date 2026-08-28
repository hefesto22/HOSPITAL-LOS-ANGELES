<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\Actions;

use App\Domain\Enums\AmbitoCatalogo;
use App\Models\CategoriaItem;
use App\Models\Item;
use App\Models\Unidad;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * «Mover al otro lado del catálogo» — la salida de la jaula.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ ESTO TIENE QUE EXISTIR
 * ─────────────────────────────────────────────────────────────────────
 *
 * Desde que el catálogo se partió en dos pantallas, `se_almacena` ya no
 * es un interruptor del formulario: lo fija la puerta por la que se
 * entra. Eso arregla el problema de fondo —nadie apaga sin querer el
 * inventario de un medicamento— y crea uno nuevo: la jeringa que alguien
 * cargó en «Catálogo» queda ahí para siempre.
 *
 * Sin esta acción, la salida sería un UPDATE a mano contra la base. Es
 * exactamente el tipo de cosa que después nadie recuerda haber hecho.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 LO QUE NO SE PUEDE MOVER
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un producto que YA tiene existencia, lotes o movimientos de kardex no
 * sale de farmacia. Apagarle `se_almacena` no borraría ese stock: lo
 * volvería invisible para las pantallas y para el conteo físico, y el
 * kardex quedaría hablando de algo que el sistema dice que no se
 * almacena. La existencia se baja primero, con su motivo y su
 * autorizador, y recién después se mueve.
 *
 * Y al revés: para entrar a farmacia hace falta unidad de dispensación,
 * porque el CHECK `items_unidad_obligatoria_si_se_almacena` la exige y
 * sin ella no hay cómo costear ni descontar. Si el ítem no la tiene, el
 * modal la pide en el mismo acto.
 *
 * ⚠️ Los closures reciben sus argumentos POR NOMBRE: `$record` y
 * `$data`. Con otro nombre llega un objeto vacío del contenedor y falla
 * en silencio.
 */
final class MoverDeAmbitoAction
{
    public static function make(): Action
    {
        return Action::make('mover_de_ambito')
            ->label(fn (Item $record): string => $record->se_almacena
                ? 'Mover al catálogo'
                : 'Mover a farmacia')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('gray')
            ->visible(fn (Item $record): bool => self::puedeVerse($record))
            ->modalHeading(fn (Item $record): string => 'Mover · '.$record->nombre)
            ->modalDescription(fn (Item $record): string => $record->se_almacena
                ? 'Deja de llevar existencia, lote y costo promedio, y desaparece de los conteos físicos. '
                .'Sus cargos y facturas anteriores no cambian.'
                : 'Pasa a llevar existencia por almacén, lote y vencimiento, costo promedio y FEFO, '
                .'y aparece en los conteos físicos.')
            ->modalSubmitActionLabel('Mover')
            ->schema([
                Select::make('categoria_id')
                    ->label('Categoría de destino')
                    ->required()
                    ->native(false)
                    ->searchable()
                    ->preload()
                    ->options(fn (Item $record): array => self::categoriasDestino($record))
                    ->helperText('La categoría vieja es del otro lado del catálogo: hay que elegir una nueva.'),

                /*
                 * Solo cuando falta y solo cuando entra a farmacia. Un
                 * campo siempre visible acá enseñaría a cambiarle la
                 * unidad a un producto de paso, que es como se
                 * multiplica o se divide un inventario por cien.
                 */
                Select::make('unidad_dispensacion_id')
                    ->label('Unidad del kardex')
                    ->required()
                    ->native(false)
                    ->searchable()
                    ->preload()
                    ->visible(fn (Item $record): bool => ! $record->se_almacena
                        && $record->unidad_dispensacion_id === null)
                    ->options(fn (): array => Unidad::query()
                        ->orderBy('nombre')
                        ->get()
                        ->mapWithKeys(fn (Unidad $u): array => [$u->getKey() => $u->etiqueta()])
                        ->all())
                    ->helperText('En qué unidad se cuenta y se descuenta. La más chica que se dispensa.'),
            ])
            /*
             * `halt()` cuando el movimiento no se hizo: sin eso, la
             * acción se da por buena y la ficha redirige al listado
             * llevándose por delante el aviso de por qué no se pudo.
             */
            ->action(function (array $data, Item $record, Action $action): void {
                if (! self::mover($record, $data)) {
                    $action->halt();
                }
            });
    }

    /**
     * Mover es modificar el ítem: mismo permiso, ni uno propio ni uno
     * menos. Lo que sí se niega siempre es sacar de farmacia algo que ya
     * tiene inventario escrito.
     */
    public static function puedeVerse(Item $record): bool
    {
        if (! Gate::allows('update', $record)) {
            return false;
        }

        return ! ($record->se_almacena && $record->tieneInventarioEscrito());
    }

    /**
     * Las categorías del OTRO lado, que es a donde va.
     *
     * @return array<int, string>
     */
    private static function categoriasDestino(Item $record): array
    {
        $destino = AmbitoCatalogo::deSeAlmacena(! $record->se_almacena);

        return CategoriaItem::query()
            ->delAmbito($destino)
            ->vigentesEn(now())
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get()
            ->mapWithKeys(fn (CategoriaItem $c): array => [$c->getKey() => $c->nombre])
            ->all();
    }

    /**
     * Las tres columnas se mueven juntas o no se mueve ninguna.
     *
     * `categoria_ambito` no se escribe acá: lo deriva `Item::booted()` de
     * `se_almacena`, y si la categoría elegida fuera del lado equivocado
     * la FK compuesta `items_categoria_fk` rechaza el UPDATE entero.
     *
     * Devuelve si se movió: `false` es «tiene inventario escrito», y
     * quien la llama tiene que frenar ahí.
     *
     * @param array<string, mixed> $data
     */
    private static function mover(Item $record, array $data): bool
    {
        $destinoEsFarmacia = ! $record->se_almacena;

        $movido = DB::transaction(function () use ($record, $data, $destinoEsFarmacia): bool {
            /*
             * Re-check DENTRO de la transacción: entre que se abrió el
             * modal y se apretó Mover, otra pantalla pudo recibir una
             * compra de este producto.
             */
            /*
             * `whereKey()->firstOrFail()` y no `findOrFail()`: el segundo
             * acepta también un array de ids, así que su tipo de retorno
             * es `Item|Collection` y el analizador no puede saber cuál
             * de los dos le tocó.
             */
            $fresco = Item::query()
                ->whereKey($record->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($fresco->se_almacena && $fresco->tieneInventarioEscrito()) {
                Notification::make()
                    ->danger()
                    ->title('No se puede mover')
                    ->body('Este producto ya tiene existencia, lotes o movimientos. Bajá primero el stock con un ajuste.')
                    ->persistent()
                    ->send();

                return false;
            }

            $fresco->se_almacena = $destinoEsFarmacia;
            $fresco->categoria_id = is_numeric($data['categoria_id'] ?? null)
                ? (int) $data['categoria_id']
                : null;

            if ($destinoEsFarmacia && isset($data['unidad_dispensacion_id']) && is_numeric($data['unidad_dispensacion_id'])) {
                $fresco->unidad_dispensacion_id = (int) $data['unidad_dispensacion_id'];
            }

            $fresco->save();

            return true;
        });

        if ($movido !== true) {
            return false;
        }

        Notification::make()
            ->success()
            ->title($destinoEsFarmacia ? 'Movido a farmacia' : 'Movido al catálogo')
            ->body($destinoEsFarmacia
                ? 'Ya lleva existencia y aparece en los conteos. Cargale la primera compra para que tome costo.'
                : 'Dejó de llevar existencia. Su precio se fija a mano en el tarifario.')
            ->send();

        return true;
    }
}
