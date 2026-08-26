<?php

declare(strict_types=1);

namespace App\Filament\Resources\Conteos\Pages;

use App\Filament\Resources\Conteos\Actions\AnularConteoAction;
use App\Filament\Resources\Conteos\Actions\CerrarConteoAction;
use App\Filament\Resources\Conteos\ConteoResource;
use App\Models\Conteo;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * La pantalla donde se revisa y se cierra.
 *
 * ⚠️ Las acciones van SUELTAS en la cabecera y no dentro de un
 * `ActionGroup` (§9.A1): en las páginas de cabecera, un ActionGroup no
 * recibe `$record`, así que las acciones quedan invisibles y `callAction`
 * falla. Es un bug que no da error en ningún lado, solo botones que no
 * aparecen.
 */
class VerConteo extends ViewRecord
{
    protected static string $resource = ConteoResource::class;

    public function getSubheading(): ?string
    {
        $conteo = $this->getRecord();

        if (! $conteo instanceof Conteo) {
            return null;
        }

        if (! $conteo->estaAbierto()) {
            return 'Este conteo ya terminó. Ni él ni sus líneas se pueden modificar: explican '
                .'movimientos de un kardex que no se edita.';
        }

        return 'Revisá las diferencias antes de cerrar. Cerrar es lo que las asienta en el '
            .'kardex, y no lo puede hacer quien abrió el conteo.';
    }

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('seguirContando')
                ->label('Seguir contando')
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->color('warning')
                ->url(fn (Conteo $record): string => ConteoResource::getUrl('contar', ['record' => $record]))
                ->visible(fn (?Conteo $record): bool => $record instanceof Conteo
                    && $record->estaAbierto()
                    && Gate::allows('update', $record)),

            CerrarConteoAction::make(),
            AnularConteoAction::make(),
        ];
    }
}
