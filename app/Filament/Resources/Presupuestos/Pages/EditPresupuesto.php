<?php

declare(strict_types=1);

namespace App\Filament\Resources\Presupuestos\Pages;

use App\Domain\Enums\EstadoPresupuesto;
use App\Domain\ValueObjects\Decimal;
use App\Filament\Resources\Presupuestos\Actions\AgregarALaCuentaAction;
use App\Filament\Resources\Presupuestos\Actions\AnularPresupuestoAction;
use App\Filament\Resources\Presupuestos\Actions\GuardarComoPlantillaAction;
use App\Filament\Resources\Presupuestos\PresupuestoResource;
use App\Filament\Resources\Presupuestos\Schemas\PresupuestoForm;
use App\Filament\Schemas\Components\CampoMayusculas;
use App\Models\Presupuesto;
use App\Models\PresupuestoLinea;
use App\Services\AgregadorDePresupuestoALaCuenta;
use App\Services\CotizadorDePresupuesto;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Throwable;

class EditPresupuesto extends EditRecord
{
    protected static string $resource = PresupuestoResource::class;

    /**
     * Guardar va arriba, con las demás acciones (12-oct-2026).
     *
     * Abajo quedaba suelto entre los datos —que al editar vienen
     * plegados— y los renglones: una fila entera para un botón que se usa
     * poco, porque lo que se hace en esta pantalla es trabajar los
     * renglones, y esos se guardan solos. Sigue respondiendo a ⌘S / Ctrl+S.
     *
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->getSaveFormAction()
                ->formId('form')
                ->label('Guardar datos')
                ->icon(Heroicon::OutlinedCheck)
                ->color('gray')
                ->visible(fn (): bool => $this->esBorrador()),
            AgregarALaCuentaAction::make(),
            GuardarComoPlantillaAction::make(),
            AnularPresupuestoAction::make(),
        ];
    }

    /**
     * 🔴 UN EMITIDO SE VE, PERO NO SE TOCA.
     *
     * No se bloquea la PÁGINA —hay que poder abrir el papel que la
     * familia tiene en la mano, y el botón de revisarlo vive acá—: lo que
     * se bloquea es la EDICIÓN. El formulario entra deshabilitado y el
     * botón de guardar del encabezado no se dibuja.
     *
     * Las líneas ya las defiende un trigger de la base. El encabezado no
     * tiene trigger, y cambiarle el pagador a un presupuesto firmado
     * dejaría un documento que dice una cosa y se calculó con otra.
     */
    public function form(Schema $schema): Schema
    {
        return PresupuestoForm::configure($schema)->disabled(fn (): bool => ! $this->esBorrador());
    }

    /**
     * Sin botones al pie: guardar vive en el encabezado.
     *
     * @return array<int, mixed>
     */
    protected function getFormActions(): array
    {
        return [];
    }

    /**
     * El título del caso arriba, en grande: «APENDICECTOMIA» se reconoce
     * de un vistazo; «Editar PRE-HLA-2026-000001» obligaba a leer.
     */
    public function getHeading(): string
    {
        $registro = $this->getRecord();

        return $registro instanceof Presupuesto ? $registro->titulo : 'Presupuesto';
    }

    /**
     * El número y a quién se le cotiza. El tope y lo consumido ya no van
     * acá: viven en el resumen, a la derecha de los datos, con su barra
     * (12-oct-2026), y siguen a la vista mientras se agregan renglones.
     */
    public function getSubheading(): ?string
    {
        $registro = $this->getRecord();

        if (! $registro instanceof Presupuesto) {
            return null;
        }

        $persona = $registro->persona;

        return $registro->numero.' · '.$persona->nombreCompleto();
    }

    /**
     * Los renglones viven en su propio componente (`RenglonesRelationManager`):
     * cuando agrega, ajusta o quita uno, avisa, y acá se relee el
     * presupuesto para que el resumen —total, tope, consumo— diga el
     * número nuevo sin recargar la página.
     */
    #[On('renglones-del-presupuesto-cambiaron')]
    public function alCambiarLosRenglones(): void
    {
        $registro = $this->getRecord();

        if ($registro instanceof Presupuesto) {
            $registro->refresh();
        }
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * CUÁNTO SE LE COBRA A LA FAMILIA (12-oct-2026)
     * ─────────────────────────────────────────────────────────────────
     *
     * Lo que contó el doctor: los renglones suman L 645, a la familia se
     * le cobran L 1,000, y el resto va en holgura o agregando más cosas.
     * Se fija acá y la holgura completa sola la diferencia
     * (`CotizadorDePresupuesto::acordarMonto()`).
     *
     * Se abre desde el botón del resumen de arriba —`mountAction()`—, por
     * eso vive en un método `…Action` de la página y no en el encabezado,
     * que ya lleva cuatro botones.
     */
    public function acordarMontoAction(): Action
    {
        return Action::make('acordarMonto')
            ->modalHeading('¿Cuánto se le cobra?')
            ->modalDescription(fn (): string => $this->explicacionDelAcuerdo())
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel('Guardar')
            ->visible(fn (): bool => $this->puedeAcordar())
            ->fillForm(function (): array {
                $presupuesto = $this->presupuesto();

                return [
                    'monto'  => $presupuesto?->monto_acordado,
                    'nombre' => $this->nombreDeLaHolguraQueCompleta(),
                ];
            })
            ->schema([
                TextInput::make('monto')
                    ->label('Se le cobrará')
                    ->numeric()
                    ->minValue(0)
                    ->prefix('L')
                    ->autofocus()
                    ->helperText('Vacío o en cero: sin monto acordado. La holgura se queda como está y ya no se ajusta sola.'),

                CampoMayusculas::make('nombre')
                    ->label('Nombre de la holgura que completa')
                    ->required()
                    ->minLength(2)
                    ->maxLength(200)
                    ->helperText('Como sale en el papel: IMPREVISTOS, MARGEN POR COMPLICACIONES.'),
            ])
            ->action(function (array $data): void {
                $presupuesto = $this->presupuesto();

                if (! $presupuesto instanceof Presupuesto) {
                    return;
                }

                abort_unless(Gate::allows('update', $presupuesto), 403);

                try {
                    app(CotizadorDePresupuesto::class)->acordarMonto(
                        presupuesto: $presupuesto,
                        monto: self::montoDe($data['monto'] ?? null),
                        nombre: is_string($data['nombre'] ?? null) ? $data['nombre'] : null,
                    );
                } catch (Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title('No se guardó')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                $presupuesto->refresh();
                $this->dispatch('lo-que-se-cobra-cambio');
                $this->seguirLaCuenta($presupuesto);
            });
    }

    private function presupuesto(): ?Presupuesto
    {
        $registro = $this->getRecord();

        return $registro instanceof Presupuesto ? $registro : null;
    }

    private function puedeAcordar(): bool
    {
        $presupuesto = $this->presupuesto();

        return $presupuesto instanceof Presupuesto
            && $presupuesto->estado->esEditable()
            && Gate::allows('update', $presupuesto);
    }

    private function explicacionDelAcuerdo(): string
    {
        $presupuesto = $this->presupuesto();

        if (! $presupuesto instanceof Presupuesto) {
            return '';
        }

        $complemento = $presupuesto->complemento();
        $renglones = Decimal::de($presupuesto->total)
            ->restar($complemento instanceof PresupuestoLinea ? $complemento->total : '0');

        return 'Los renglones suman L '.number_format((float) $renglones->redondeado(2), 2).'. '
            .'La diferencia hasta lo que se le cobra la completa una holgura que se ajusta sola: '
            .'si agregás renglones se achica, si quitás crece.';
    }

    /**
     * El nombre que propone el formulario: el de la holgura que ya
     * completa, o el de la que puso la plantilla, o el de siempre.
     */
    private function nombreDeLaHolguraQueCompleta(): string
    {
        $presupuesto = $this->presupuesto();

        if (! $presupuesto instanceof Presupuesto) {
            return CotizadorDePresupuesto::HOLGURA_POR_DEFECTO;
        }

        $holgura = $presupuesto->complemento()
            ?? $presupuesto->detalle()->where('origen', 'holgura')->reorder()->orderByDesc('orden')->first();

        return $holgura instanceof PresupuestoLinea ? $holgura->texto : CotizadorDePresupuesto::HOLGURA_POR_DEFECTO;
    }

    /**
     * 🔴 Si el paquete ya está en la cuenta, el monto de la cuenta sigue
     * al presupuesto en el acto (ADR-0009). El Service no hace nada si el
     * total no cambió.
     */
    private function seguirLaCuenta(Presupuesto $presupuesto): void
    {
        if ($presupuesto->estado !== EstadoPresupuesto::Agregado) {
            return;
        }

        try {
            app(AgregadorDePresupuestoALaCuenta::class)->sincronizar($presupuesto);
        } catch (Throwable $e) {
            Notification::make()
                ->danger()
                ->title('Se guardó, pero la cuenta no se pudo actualizar')
                ->body($e->getMessage())
                ->persistent()
                ->send();
        }
    }

    /**
     * ⚠️ Un `<input type="number">` llega por Livewire como float o como
     * texto: se normaliza a texto numérico, o nulo si no dice nada.
     *
     * @return numeric-string|null
     */
    private static function montoDe(mixed $valor): ?string
    {
        $texto = match (true) {
            is_int($valor)    => (string) $valor,
            is_float($valor)  => number_format($valor, 2, '.', ''),
            is_string($valor) => trim($valor),
            default           => '',
        };

        return is_numeric($texto) ? $texto : null;
    }

    private function esBorrador(): bool
    {
        $registro = $this->getRecord();

        return $registro instanceof Presupuesto
            && $registro->estado === EstadoPresupuesto::Borrador;
    }
}
