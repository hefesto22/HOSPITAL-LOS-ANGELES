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

class EditPresupuesto extends EditRecord
{
    protected static string $resource = PresupuestoResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
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
     * botón de guardar no se dibuja.
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
     * @return array<int, mixed>
     */
    protected function getFormActions(): array
    {
        return $this->esBorrador() ? parent::getFormActions() : [];
    }

    /**
     * El aviso del tope, siempre a la vista mientras se cotiza.
     *
     * Va acá y no en una notificación al abrir: una notificación se
     * cierra y se olvida; esto sigue diciendo el número mientras la
     * cajera agrega renglones, que es cuando todavía se puede corregir.
     */
    public function getSubheading(): ?string
    {
        $registro = $this->getRecord();

        if (! $registro instanceof Presupuesto) {
            return null;
        }

        $tope = $registro->topeDeReferencia();

        if ($tope === null) {
            return null;
        }

        $va = number_format((float) $registro->total, 2);
        $maximo = number_format((float) $tope->redondeado(2), 2);

        return $registro->excedeElTope()
            ? "⚠️ Esta cotización va en L {$va} y {$registro->plantilla?->nombre} no debería pasar de L {$maximo}. Se puede emitir igual — revisá si el caso lo justifica."
            : "Va en L {$va}. El tope de {$registro->plantilla?->nombre} es L {$maximo}.";
    }

    private function esBorrador(): bool
    {
        $registro = $this->getRecord();

        return $registro instanceof Presupuesto
            && $registro->estado === EstadoPresupuesto::Borrador;
    }
}
