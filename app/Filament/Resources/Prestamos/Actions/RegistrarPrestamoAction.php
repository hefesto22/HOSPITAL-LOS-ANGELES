<?php

declare(strict_types=1);

namespace App\Filament\Resources\Prestamos\Actions;

use App\Domain\Enums\FormaDeSaldo;
use App\Domain\Enums\QuienPresta;
use App\Domain\Exceptions\PrestamoException;
use App\Domain\ValueObjects\Decimal;
use App\Filament\Schemas\Components\CampoMayusculas;
use App\Models\Almacen;
use App\Models\Item;
use App\Models\Lote;
use App\Services\RegistradorDePrestamo;
use App\Support\NumeroDeFormulario;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Registrar que alguien nos prestó algo.
 *
 * Normalmente esto se abre solo desde la pantalla de cobro, cuando no hay
 * existencia. Acá existe para el otro caso real: se pide prestado para
 * reponer el estante, sin paciente de por medio.
 *
 * ⚠️ El préstamo SUBE la existencia en el acto. No es un apunte: es
 * inventario que está físicamente en el estante y se va a dispensar.
 */
final class RegistrarPrestamoAction
{
    public static function make(): Action
    {
        return Action::make('registrarPrestamo')
            ->label('Registrar un préstamo')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->modalHeading('Alguien nos prestó')
            ->modalDescription(
                'Sube la existencia en el acto y queda la deuda con quien prestó. Lo que trae el '
                .'médico o la familia del paciente se registra igual, pero no cuenta como deuda.'
            )
            ->modalSubmitActionLabel('Registrar')
            ->modalWidth('2xl')
            ->schema([
                Select::make('item_id')
                    ->label('Qué prestaron')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->options(fn (): array => Item::query()
                        ->where('se_almacena', true)
                        ->orderBy('nombre')
                        ->limit(200)
                        ->pluck('nombre', 'id')
                        ->all())
                    ->getSearchResultsUsing(fn (string $search): array => Item::query()
                        ->where('se_almacena', true)
                        ->where(function (Builder $query) use ($search): void {
                            $query->where('nombre', 'ilike', "%{$search}%")
                                ->orWhere('codigo', 'ilike', "%{$search}%");
                        })
                        ->orderBy('nombre')
                        ->limit(50)
                        ->pluck('nombre', 'id')
                        ->all())
                    ->live()
                    ->columnSpan(2),

                /*
                 * 🔴 Solo bodega o farmacia — el mismo criterio que el
                 * modal del mostrador, y por eso vive en el enum y no
                 * repetido en las dos pantallas.
                 *
                 * Los estantes de consumo interno no reciben préstamos:
                 * lo que entra ahí se CONSUME, y una deuda parada en el
                 * carro de paro no se puede devolver sin trasladarla
                 * primero. El día que llegue la compra, el aviso pediría
                 * devolver algo que ya se usó.
                 */
                Select::make('almacen_id')
                    ->label('A qué almacén entra')
                    ->required()
                    ->native(false)
                    ->options(fn (): array => self::estantesQueRecibenPrestamo())
                    ->helperText(
                        'Bodega o farmacia: donde va a quedar físicamente, y de donde va a salir '
                        .'cuando se devuelva.'
                    ),

                TextInput::make('cantidad')
                    ->label('Cuánto')
                    ->required()
                    ->rule('regex:/^\d{1,10}(\.\d{1,4})?$/')
                    ->validationMessages([
                        'regex' => 'Escribí solo el número, con hasta cuatro decimales.',
                    ])
                    ->helperText('En la unidad del kardex: si prestaron una caja de 100, van 100.'),

                /*
                 * El lote solo aparece cuando el ítem lo exige — ARSA lo
                 * obliga en medicamentos. Preguntarlo siempre enseña a
                 * saltear el campo, y el día que sí importa también se
                 * saltea.
                 */
                Select::make('lote_id')
                    ->label('Lote')
                    ->visible(fn (Get $get): bool => self::exigeLote($get('item_id')))
                    ->required(fn (Get $get): bool => self::exigeLote($get('item_id')))
                    ->options(fn (Get $get): array => Lote::query()
                        ->where('item_id', $get('item_id'))
                        ->orderByDesc('fecha_vencimiento')
                        ->pluck('numero', 'id')
                        ->all())
                    ->searchable()
                    ->helperText('El de la caja que prestaron. Si no está en la lista, cargalo acá mismo.')
                    ->createOptionForm([
                        CampoMayusculas::make('numero')
                            ->label('Número de lote')
                            ->required()
                            ->maxLength(60),

                        DatePicker::make('fecha_vencimiento')
                            ->label('Vence')
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                    ])
                    ->createOptionUsing(function (array $data, Get $get): ?int {
                        $itemId = $get('item_id');

                        if (! is_numeric($itemId)) {
                            return null;
                        }

                        $lote = Lote::query()->create([
                            'item_id'           => (int) $itemId,
                            'numero'            => $data['numero'],
                            'fecha_vencimiento' => $data['fecha_vencimiento'] ?? null,
                        ]);

                        return $lote->id;
                    })
                    ->columnSpan(2),

                Select::make('presta_tipo')
                    ->label('Quién prestó')
                    ->required()
                    ->native(false)
                    ->default(QuienPresta::Farmacia->value)
                    ->options(QuienPresta::opciones())
                    ->live()
                    ->helperText(fn (Get $get): string => self::ayudaDelTipo($get('presta_tipo'))),

                TextInput::make('presta_nombre')
                    ->label('Nombre')
                    ->required()
                    ->minLength(3)
                    ->maxLength(160)
                    ->placeholder('FARMACIA SAN JOSE')
                    ->helperText('Sin esto no se le puede devolver a nadie.'),

                TextInput::make('presta_telefono')
                    ->label('Teléfono')
                    ->maxLength(40)
                    ->tel(),

                Select::make('forma_de_saldo')
                    ->label('Cómo se le paga')
                    ->required()
                    ->native(false)
                    ->default(FormaDeSaldo::DevolverProducto->value)
                    ->options(FormaDeSaldo::opciones())
                    ->live()
                    ->helperText(fn (Get $get): string => self::ayudaDeLaForma($get('forma_de_saldo'))),

                TextInput::make('monto_acordado')
                    ->label('Cuánto se le va a pagar')
                    ->prefix('L')
                    ->visible(fn (Get $get): bool => $get('forma_de_saldo') === FormaDeSaldo::Pagar->value)
                    ->required(fn (Get $get): bool => $get('forma_de_saldo') === FormaDeSaldo::Pagar->value)
                    ->rule('regex:/^\d{1,10}(\.\d{1,2})?$/')
                    ->validationMessages([
                        'regex' => 'Escribí solo el número, con hasta dos decimales.',
                    ]),

                Textarea::make('motivo')
                    ->label('Por qué se pidió')
                    ->rows(2)
                    ->maxLength(255)
                    ->placeholder('No había existencia y el paciente lo necesitaba en el momento.')
                    ->columnSpanFull(),
            ])
            ->action(function (array $data): void {
                /*
                 * ⚠️ El `(int)` no es cosmético: `find($mixed)` NO
                 * devuelve `Modelo|null`. Eloquent acepta un arreglo de
                 * ids y en ese caso devuelve una Collection, así que con
                 * un argumento sin tipo el retorno incluye Collection y
                 * no entra en un parámetro que espera `Lote|null`.
                 *
                 * Se hace uno por modelo y no con un ayudante genérico:
                 * sobre una `class-string<T>`, `$modelo::query()` pierde
                 * el enlace con T y el analizador ya no puede probar que
                 * lo que vuelve es del tipo prometido.
                 */
                $idDelItem = $data['item_id'] ?? null;
                $idDelAlmacen = $data['almacen_id'] ?? null;
                $idDelLote = $data['lote_id'] ?? null;

                $item = is_numeric($idDelItem) ? Item::query()->find((int) $idDelItem) : null;
                $almacen = is_numeric($idDelAlmacen) ? Almacen::query()->find((int) $idDelAlmacen) : null;
                $lote = is_numeric($idDelLote) ? Lote::query()->find((int) $idDelLote) : null;

                $cantidad = NumeroDeFormulario::aDecimal($data['cantidad'] ?? null);

                if (! $item instanceof Item || ! $almacen instanceof Almacen || ! $cantidad instanceof Decimal) {
                    Notification::make()
                        ->danger()
                        ->title('Faltan datos del préstamo')
                        ->send();

                    return;
                }

                $forma = FormaDeSaldo::from((string) $data['forma_de_saldo']);

                try {
                    $prestamo = app(RegistradorDePrestamo::class)->registrar(
                        item: $item,
                        almacen: $almacen,
                        cantidad: $cantidad,
                        quienPresta: QuienPresta::from((string) $data['presta_tipo']),
                        nombreDeQuienPresta: (string) $data['presta_nombre'],
                        forma: $forma,
                        lote: $lote,
                        montoAcordado: $forma === FormaDeSaldo::Pagar
                            ? NumeroDeFormulario::aDecimal($data['monto_acordado'] ?? null)
                            : null,
                        telefono: is_string($data['presta_telefono'] ?? null) && trim($data['presta_telefono']) !== ''
                            ? trim($data['presta_telefono'])
                            : null,
                        motivo: is_string($data['motivo'] ?? null) && trim($data['motivo']) !== ''
                            ? trim($data['motivo'])
                            : null,
                    );
                } catch (PrestamoException $e) {
                    Notification::make()
                        ->danger()
                        ->title('No se pudo registrar el préstamo')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Préstamo registrado')
                    ->body($prestamo->seDebe()
                        ? "La existencia ya subió. Se le deben {$prestamo->saldoPendiente()->redondeado(2)} a {$prestamo->presta_nombre}."
                        : 'La existencia ya subió. No genera deuda: lo trajo el paciente.')
                    ->send();
            });
    }

    /**
     * Los estantes donde puede quedar algo prestado.
     *
     * ⚠️ NUNCA VACÍO: si ningún almacén está cargado con un tipo que
     * reciba préstamos, vuelven todos. Anotar el préstamo en el estante
     * equivocado es peor que la regla, pero es infinitamente mejor que no
     * poder anotarlo.
     *
     * @see \App\Domain\Enums\TipoAlmacen::recibePrestamo()
     *
     * @return array<int, string>
     */
    private static function estantesQueRecibenPrestamo(): array
    {
        /** @var array<int, string> $estantes */
        $estantes = Almacen::query()
            ->vigentes()
            ->queRecibenPrestamo()
            ->orderBy('nombre')
            ->pluck('nombre', 'id')
            ->all();

        if ($estantes !== []) {
            return $estantes;
        }

        /** @var array<int, string> $todos */
        $todos = Almacen::query()->vigentes()->orderBy('nombre')->pluck('nombre', 'id')->all();

        return $todos;
    }

    private static function exigeLote(mixed $itemId): bool
    {
        if (! is_numeric($itemId)) {
            return false;
        }

        $item = Item::query()->find((int) $itemId);

        return $item instanceof Item && $item->requiere_lote;
    }

    private static function ayudaDelTipo(mixed $tipo): string
    {
        return is_string($tipo) && QuienPresta::tryFrom($tipo) instanceof QuienPresta
            ? QuienPresta::from($tipo)->ayuda()
            : 'De quién salió lo que el hospital no tenía.';
    }

    private static function ayudaDeLaForma(mixed $forma): string
    {
        return is_string($forma) && FormaDeSaldo::tryFrom($forma) instanceof FormaDeSaldo
            ? FormaDeSaldo::from($forma)->ayuda()
            : 'Se elige ahora porque las dos formas dejan rastros distintos.';
    }
}
