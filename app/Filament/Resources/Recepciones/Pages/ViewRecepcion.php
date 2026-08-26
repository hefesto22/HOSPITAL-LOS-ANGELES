<?php

declare(strict_types=1);

namespace App\Filament\Resources\Recepciones\Pages;

use App\Filament\Resources\Recepciones\Actions\MarcarRevisadaAction;
use App\Filament\Resources\Recepciones\RecepcionResource;
use App\Models\Recepcion;
use Filament\Resources\Pages\ViewRecord;

class ViewRecepcion extends ViewRecord
{
    protected static string $resource = RecepcionResource::class;

    public function getSubheading(): ?string
    {
        $recepcion = $this->getRecord();

        if (! $recepcion instanceof Recepcion) {
            return null;
        }

        return $recepcion->estaRevisada()
            ? 'Revisada por '.($recepcion->revisadaPor->name ?? 'alguien')
            : 'Todavía no la revisó nadie. La mercadería YA está en el kardex.';
    }

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            MarcarRevisadaAction::make(),
        ];
    }
}
