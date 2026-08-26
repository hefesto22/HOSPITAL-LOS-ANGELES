<?php

declare(strict_types=1);

namespace App\Filament\Resources\CategoriasItem\Pages;

use App\Filament\Resources\CategoriasItem\CategoriaItemResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Sin acción de borrar: una categoría se retira cerrándole la vigencia.
 */
class EditCategoriaItem extends EditRecord
{
    protected static string $resource = CategoriaItemResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
