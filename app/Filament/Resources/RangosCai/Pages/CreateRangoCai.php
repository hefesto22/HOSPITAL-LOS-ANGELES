<?php

declare(strict_types=1);

namespace App\Filament\Resources\RangosCai\Pages;

use App\Filament\Resources\RangosCai\RangoCaiResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRangoCai extends CreateRecord
{
    protected static string $resource = RangoCaiResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * El correlativo arranca donde arranca el rango.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! isset($data['siguiente']) || ! is_numeric($data['siguiente'])) {
            $data['siguiente'] = $data['desde'] ?? 1;
        }

        return $data;
    }
}
