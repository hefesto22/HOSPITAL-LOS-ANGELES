<?php

declare(strict_types=1);

namespace App\Filament\Resources\RangosCai\Pages;

use App\Filament\Resources\RangosCai\RangoCaiResource;
use Filament\Resources\Pages\EditRecord;

class EditRangoCai extends EditRecord
{
    protected static string $resource = RangoCaiResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * 🔴 Nunca se borra un rango: se desactiva.
     *
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
