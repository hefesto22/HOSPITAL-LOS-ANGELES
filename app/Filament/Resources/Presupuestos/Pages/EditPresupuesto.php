<?php

declare(strict_types=1);

namespace App\Filament\Resources\Presupuestos\Pages;

use App\Domain\Enums\EstadoPresupuesto;
use App\Filament\Resources\Presupuestos\Actions\AgregarALaCuentaAction;
use App\Filament\Resources\Presupuestos\Actions\AnularPresupuestoAction;
use App\Filament\Resources\Presupuestos\Actions\GuardarComoPlantillaAction;
use App\Filament\Resources\Presupuestos\PresupuestoResource;
use App\Filament\Resources\Presupuestos\Schemas\PresupuestoForm;
use App\Models\Presupuesto;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\On;

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

    private function esBorrador(): bool
    {
        $registro = $this->getRecord();

        return $registro instanceof Presupuesto
            && $registro->estado === EstadoPresupuesto::Borrador;
    }
}
