<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ajustes\Schemas;

use App\Domain\Enums\MotivoDeAjuste;
use App\Domain\Enums\TipoDeAjuste;
use App\Models\Almacen;
use App\Models\Item;
use App\Models\ItemPresentacion;
use App\Models\Lote;
use App\Models\User;
use App\Services\RegistradorDeAjuste;
use App\Support\AlmacenesDelUsuario;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as ConsultaCruda;
use Illuminate\Support\Str;
use Marcelorodrigo\FilamentBarcodeScannerField\Forms\Components\BarcodeInput;

/**
 * Asentar una merma, una baja por vencimiento o una corrección.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LA DIFERENCIA DE CONTEO NO SE PUEDE ELEGIR ACÁ
 * ─────────────────────────────────────────────────────────────────────
 *
 * Solo aparecen los tipos que se crean a mano. Una diferencia de conteo
 * nace del cierre de un conteo físico, con la evidencia de lo que se
 * contó detrás; poder escribirla directo sería poder declarar un faltante
 * sin haber contado nada. El servicio lo vuelve a rechazar.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EL MOTIVO ES UNA LISTA, EL TEXTO ES OBLIGATORIO, Y NO ES REDUNDANTE
 * ─────────────────────────────────────────────────────────────────────
 *
 * El motivo tipificado es lo que hace contestable «¿cuánta plata se
 * pierde por cadena de frío rota?». El texto libre es lo que hace
 * entendible ese caso concreto dentro de un año. Los dos, siempre.
 */
final class AjusteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            self::quePaso(),
            self::queProductos(),
            self::laAutorizacion(),
        ]);
    }

    private static function quePaso(): Section
    {
        return Section::make('1 · ¿Qué pasó?')
            ->columns(2)
            ->schema([
                Radio::make('tipo')
                    ->label('Tipo de ajuste')
                    ->options(fn (): array => collect(TipoDeAjuste::cases())
                        ->filter(fn (TipoDeAjuste $t): bool => $t->seCreaAMano())
                        ->mapWithKeys(fn (TipoDeAjuste $t): array => [$t->value => $t->etiqueta()])
                        ->all())
                    ->descriptions(fn (): array => collect(TipoDeAjuste::cases())
                        ->filter(fn (TipoDeAjuste $t): bool => $t->seCreaAMano())
                        ->mapWithKeys(fn (TipoDeAjuste $t): array => [$t->value => $t->descripcion()])
                        ->all())
                    ->default(TipoDeAjuste::Merma->value)
                    ->required()
                    ->live()
                    ->columnSpanFull()
                    ->afterStateUpdated(fn (Set $set) => $set('lineas', [])),

                Select::make('almacen_id')
                    ->label('Almacén')
                    ->options(fn (): array => AlmacenesDelUsuario::elegibles()
                        ->orderBy('nombre')
                        ->get()
                        ->mapWithKeys(fn (Almacen $almacen): array => [
                            $almacen->id => $almacen->etiqueta(),
                        ])
                        ->all())
                    ->searchable()
                    ->required()
                    ->native(false)
                    ->live()
                    ->helperText('Solo aparecen los almacenes de tu área.'),

                DatePicker::make('fecha_operacion')
                    ->label('¿Cuándo pasó?')
                    ->required()
                    ->default(now())
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->maxDate(now())
                    ->helperText(
                        'La merma del sábado que se digita el lunes es del sábado: los reportes '
                        .'filtran por esta fecha, no por la de captura.'
                    ),

                Textarea::make('motivo')
                    ->label('Contá qué pasó')
                    ->required()
                    ->minLength(10)
                    ->rows(2)
                    ->columnSpanFull()
                    ->placeholder('Se cayó la bandeja al trasladar del quirófano a central de equipos')
                    ->helperText(
                        'Al menos diez caracteres. El motivo de cada línea dice la categoría; '
                        .'esto dice el caso, y es lo único que va a quedar para entenderlo.'
                    ),

                TextInput::make('referencia')
                    ->label('Referencia')
                    ->maxLength(120)
                    ->columnSpanFull()
                    ->placeholder('Acta 14 · turno de la noche')
                    ->helperText('Opcional: el papel, el acta o el número interno que respalda esto.'),
            ]);
    }

    private static function queProductos(): Section
    {
        return Section::make('2 · ¿Qué productos?')
            ->schema([
                BarcodeInput::make('escaneo')
                    ->label('Escaneá el código de barras')
                    ->dehydrated(false)
                    ->live()
                    ->helperText('Cada lectura agrega una línea con el producto ya puesto.')
                    ->afterStateUpdated(fn (mixed $state, Get $get, Set $set) => self::agregarLoEscaneado($state, $get, $set)),

                Repeater::make('lineas')
                    ->label('')
                    ->addActionLabel('Agregar a mano')
                    ->reorderable(false)
                    ->defaultItems(0)
                    ->columns(4)
                    ->schema(self::camposDeLaLinea()),
            ]);
    }

    /**
     * @return array<int, mixed>
     */
    private static function camposDeLaLinea(): array
    {
        return [
            Select::make('item_id')
                ->label('Producto')
                ->options(fn (): array => Item::query()
                    ->orderBy('nombre')
                    ->limit(50)
                    ->get()
                    ->mapWithKeys(fn (Item $item): array => [$item->id => $item->etiqueta()])
                    ->all())
                ->getSearchResultsUsing(fn (string $search): array => Item::buscar($search)
                    ->mapWithKeys(fn (Item $item): array => [$item->id => $item->etiqueta()])
                    ->all())
                ->getOptionLabelUsing(fn (mixed $value): ?string => self::itemDe($value)?->etiqueta())
                ->searchable()
                ->required()
                ->native(false)
                ->live()
                ->columnSpan(2)
                ->afterStateUpdated(fn (Set $set) => $set('lote_id', null)),

            Select::make('lote_id')
                ->label('Lote')
                ->options(fn (Get $get): array => self::lotesDe($get('item_id'), $get('../../almacen_id')))
                ->searchable()
                ->native(false)
                ->columnSpan(2)
                ->required(fn (Get $get): bool => self::itemDe($get('item_id'))?->requiere_lote === true)
                ->helperText('El que se rompió, el que venció: sin lote no hay trazabilidad.'),

            Select::make('motivo')
                ->label('Motivo')
                ->options(fn (Get $get): array => self::motivosDe($get('../../tipo')))
                ->required()
                ->native(false)
                ->live()
                ->columnSpan(2),

            Radio::make('direccion')
                ->label('¿Suma o resta?')
                ->options([
                    'sale'  => 'Resta existencia',
                    'entra' => 'Suma existencia',
                ])
                ->default('sale')
                ->inline()
                ->columnSpan(2)
                ->visible(fn (Get $get): bool => self::motivoDe($get('motivo'))?->admiteEntrada() === true)
                ->helperText('Un error de registro va en las dos direcciones; una rotura, solo resta.'),

            TextInput::make('cantidad')
                ->label('¿Cuánto?')
                ->numeric()
                ->required()
                /*
                 * Entre comillas: un `0.0001` suelto es un literal float,
                 * y aunque acá no llegue a bcmath, es la clase de literal
                 * que el §8.6.2 saca del código de cantidades para que
                 * nadie lo copie a un lugar donde sí importe.
                 */
                ->minValue('0.0001')
                ->step('0.0001')
                ->columnSpan(2)
                ->helperText('Siempre en positivo: el signo lo pone el motivo.'),

            TextInput::make('texto')
                ->label('Detalle de esta línea')
                ->maxLength(200)
                ->columnSpan(2)
                ->placeholder('Opcional'),
        ];
    }

    private static function laAutorizacion(): Section
    {
        return Section::make('3 · Autorización')
            ->description(
                'Solo hace falta si el ajuste supera L '.RegistradorDeAjuste::tope()->redondeado(2)
                .' al costo promedio. Por debajo de ese monto, dejalo vacío.'
            )
            ->collapsed()
            ->schema([
                Select::make('autorizador_id')
                    ->label('Autoriza (dirección)')
                    ->options(fn (): array => self::posiblesAutorizadores())
                    ->searchable()
                    ->native(false)
                    ->helperText(
                        'No podés autorizarte a vos mismo: un tope que se levanta uno mismo no '
                        .'es un tope.'
                    ),
            ]);
    }

    // ── Ayudantes ─────────────────────────────────────────────────────

    /**
     * @return array<string, string>
     */
    private static function motivosDe(mixed $tipo): array
    {
        if (! is_string($tipo)) {
            return [];
        }

        $deTipo = TipoDeAjuste::tryFrom($tipo);

        if (! $deTipo instanceof TipoDeAjuste) {
            return [];
        }

        return collect($deTipo->motivos())
            ->mapWithKeys(fn (MotivoDeAjuste $m): array => [$m->value => $m->etiqueta()])
            ->all();
    }

    /**
     * Los lotes de ese producto que existen en ESE almacén.
     *
     * @return array<int, string>
     */
    private static function lotesDe(mixed $itemId, mixed $almacenId): array
    {
        if (! is_numeric($itemId) || ! is_numeric($almacenId)) {
            return [];
        }

        /** @var array<int, string> $opciones */
        $opciones = Lote::query()
            ->where('lotes.item_id', (int) $itemId)
            ->whereExists(fn (ConsultaCruda $sub): ConsultaCruda => $sub
                ->select('existencias.id')
                ->from('existencias')
                ->whereColumn('existencias.lote_id', 'lotes.id')
                ->where('existencias.almacen_id', (int) $almacenId))
            ->orderByRaw('lotes.fecha_vencimiento asc nulls last')
            ->get()
            ->mapWithKeys(fn (Lote $lote): array => [
                $lote->id => $lote->numero.($lote->fecha_vencimiento === null
                    ? ''
                    : ' · vence '.$lote->fecha_vencimiento->format('d/m/Y')),
            ])
            ->all();

        return $opciones;
    }

    /**
     * @return array<int, string>
     */
    private static function posiblesAutorizadores(): array
    {
        $roles = RegistradorDeAjuste::rolesQueAutorizan();

        /** @var array<int, string> $opciones */
        $opciones = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn (Builder $sub): Builder => $sub->whereIn('name', $roles))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();

        return $opciones;
    }

    private static function agregarLoEscaneado(mixed $state, Get $get, Set $set): void
    {
        $codigo = trim(is_string($state) ? $state : '');

        if ($codigo === '') {
            return;
        }

        $set('escaneo', null);

        $presentacion = ItemPresentacion::query()
            ->where('codigo_barras', $codigo)
            ->first();

        if (! $presentacion instanceof ItemPresentacion) {
            Notification::make()
                ->warning()
                ->title('Ese código no está en el catálogo')
                ->body("Ningún producto tiene el código {$codigo}. Buscalo por nombre.")
                ->persistent()
                ->send();

            return;
        }

        /** @var array<string, mixed> $lineas */
        $lineas = is_array($get('lineas')) ? $get('lineas') : [];

        $lineas[(string) Str::uuid()] = [
            'item_id'   => $presentacion->item_id,
            'lote_id'   => null,
            'motivo'    => null,
            'direccion' => 'sale',
            'cantidad'  => '1',
            'texto'     => null,
        ];

        $set('lineas', $lineas);
    }

    private static function itemDe(mixed $id): ?Item
    {
        return is_numeric($id) ? Item::query()->find((int) $id) : null;
    }

    private static function motivoDe(mixed $valor): ?MotivoDeAjuste
    {
        return is_string($valor) ? MotivoDeAjuste::tryFrom($valor) : null;
    }
}
