<?php

declare(strict_types=1);

namespace App\Filament\Resources\Recepciones\Pages;

use App\Filament\Resources\Recepciones\RecepcionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRecepciones extends ListRecords
{
    protected static string $resource = RecepcionResource::class;

    public function getSubheading(): string
    {
        return 'Lo que entró al estante. Se guarda una vez y el kardex ya se movió: no hay '
            .'confirmación que esperar. Lo que sí queda es la lista de las que nadie revisó '
            .'todavía, y esa la tiene que mirar alguien todos los días.';
    }

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Recibir mercadería'),
        ];
    }
}
