<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pacientes\Pages;

use App\Filament\Resources\Pacientes\PacienteResource;
use App\Filament\Resources\Pacientes\Tables\PacientesTable;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;

/**
 * El buscador de admisión.
 *
 * La tabla arranca vacía a propósito: se busca primero y recién después
 * se registra. El porqué está en PacientesTable.
 *
 * Rediseño del 12-oct-2026: las bandejas de trabajo son pestañas con su
 * número, y el buscador es un solo campo grande sin la caja de «Filtros»
 * alrededor — acá no se filtra nada, se busca a una persona.
 */
class ListPacientes extends ListRecords
{
    protected static string $resource = PacienteResource::class;

    public function getSubheading(): string
    {
        return 'Buscalo siempre antes de registrarlo: dos expedientes de la misma persona terminan en una fusión.';
    }

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Registrar paciente nuevo')
                ->icon('heroicon-o-user-plus'),
        ];
    }

    /**
     * «Buscar» y las tres colas. La condición de cada cola vive en
     * `PacientesTable::aplicarBandeja()`, la misma que usa la tabla: el
     * número de la pestaña y lo que se ve al abrirla no se pueden
     * separar.
     *
     * Solo las dos colas que alguien tiene que vaciar llevan número, y
     * solo cuando hay algo: un «0» en cada pestaña se vuelve invisible
     * en dos días.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'buscar' => Tab::make('Buscar')
                ->icon(Heroicon::OutlinedMagnifyingGlass),

            'sin_identificar' => Tab::make(PacientesTable::BANDEJAS['sin_identificar'])
                ->icon(Heroicon::OutlinedQuestionMarkCircle)
                ->badge(fn (): ?int => self::cuantosEn('sin_identificar'))
                ->badgeColor('warning'),

            'en_conflicto' => Tab::make(PacientesTable::BANDEJAS['en_conflicto'])
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->badge(fn (): ?int => self::cuantosEn('en_conflicto'))
                ->badgeColor('danger'),

            'sin_expediente' => Tab::make(PacientesTable::BANDEJAS['sin_expediente'])
                ->icon(Heroicon::OutlinedFolderMinus),
        ];
    }

    /**
     * Lo de siempre, más unas líneas de estilo para esta pantalla: el
     * encabezado «Filtros» con su «Resetear» se esconde y el buscador se
     * agranda. Filament no trae la opción de esconder ese encabezado, y
     * el CSS va acotado a la página (`fi-resource-pacientes`) para no
     * tocar ninguna otra tabla.
     */
    public function content(Schema $schema): Schema
    {
        /*
         * La misma lista que `ListRecords::content()`, con el estilo
         * adelante. Se repite en vez de pedirle los componentes al padre:
         * `getComponents()` los evalúa y los deja en caché, y volver a
         * armar el esquema encima de eso es buscarse un problema raro.
         */
        return $schema->components([
            Html::make(new HtmlString(<<<'HTML'
                <style>
                    .fi-resource-list-records-page.fi-resource-pacientes .fi-ta-filters-header { display: none; }
                    .fi-resource-list-records-page.fi-resource-pacientes .fi-ta-filters .fi-input-wrp { min-height: 3rem; }
                    .fi-resource-list-records-page.fi-resource-pacientes .fi-ta-filters .fi-input { font-size: 1.05rem; }
                </style>
                HTML)),
            $this->getTabsContentComponent(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
            EmbeddedTable::make(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
        ]);
    }

    private static function cuantosEn(string $bandeja): ?int
    {
        $consulta = PacienteResource::getEloquentQuery();
        PacientesTable::aplicarBandeja($consulta, $bandeja);

        $cuantos = $consulta->count();

        return $cuantos > 0 ? $cuantos : null;
    }
}
