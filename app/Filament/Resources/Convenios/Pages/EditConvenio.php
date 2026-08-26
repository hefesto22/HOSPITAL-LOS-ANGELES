<?php

declare(strict_types=1);

namespace App\Filament\Resources\Convenios\Pages;

use App\Filament\Pages\BasesDePrecios;
use App\Filament\Resources\Convenios\ConvenioResource;
use App\Models\Convenio;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditConvenio extends EditRecord
{
    protected static string $resource = ConvenioResource::class;

    /**
     * Sin acción de borrar: el convenio se termina poniéndole fecha de
     * fin de vigencia. Ver el docblock del Resource.
     *
     * Lo único que va acá arriba es el atajo a la base de precios. Es la
     * pregunta que sigue a «¿qué cubre este seguro?» —«¿y a qué precio?»—
     * y sin el atajo hay que salir al menú, entrar a bases de precios y
     * buscar el pagador otra vez entre veinte.
     *
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('precios')
                ->label('Ver base de precios')
                ->icon(Heroicon::OutlinedCurrencyDollar)
                ->color('gray')
                ->url(fn (): string => BasesDePrecios::getUrl(['base' => $this->obtenerConvenio()?->id]))
                ->visible(fn (): bool => BasesDePrecios::canAccess()
                    && $this->obtenerConvenio() instanceof Convenio),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * `getRecord()` devuelve el modelo genérico del Resource, y PHPStan
     * no puede saber acá que es un `Convenio`. Se comprueba en vez de
     * afirmarlo: el día que alguien cambie el modelo del Resource, el
     * botón desaparece en vez de reventar la pantalla.
     */
    private function obtenerConvenio(): ?Convenio
    {
        $registro = $this->getRecord();

        return $registro instanceof Convenio ? $registro : null;
    }
}
