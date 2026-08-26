<?php

declare(strict_types=1);

namespace App\Filament\Resources\Convenios\Pages;

use App\Domain\ValueObjects\Decimal;
use App\Filament\Pages\BasesDePrecios;
use App\Filament\Resources\Convenios\ConvenioResource;
use App\Models\Convenio;
use App\Services\CopiadorDeBaseDePrecios;
use App\Support\NumeroDeFormulario;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

/**
 * Alta de un pagador, con su base de precios ya armada.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ LA COPIA VA ACÁ Y NO EN UNA PANTALLA APARTE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Firmar con una aseguradora y cargarle sus precios son el MISMO acto
 * para quien lo hace. Separarlos deja el hueco clásico: el convenio
 * existe, nadie cargó los precios, y el primer paciente que llega con
 * esa póliza se atiende con «este ítem no tiene precio para este
 * pagador» a las once de la noche.
 *
 * Heredar es opcional —se puede empezar vacío— pero está a un campo de
 * distancia en el mismo formulario, y por eso se hace.
 */
class CreateConvenio extends CreateRecord
{
    protected static string $resource = ConvenioResource::class;

    /**
     * De dónde heredar. Se guarda entre el `mutate` y el `after` porque
     * son dos momentos distintos del ciclo de Filament y el dato no
     * pertenece al modelo.
     */
    protected ?string $heredarDe = null;

    protected string $porcentajeHeredado = '100';

    /**
     * Si la copia llegó a correr, el destino natural es la base recién
     * armada y no el listado: quien acaba de firmar quiere VER los
     * precios que quedaron para revisarlos antes de que entre el primer
     * paciente.
     */
    protected bool $heredoPrecios = false;

    protected function getRedirectUrl(): string
    {
        $destino = $this->getRecord();

        if ($this->heredoPrecios && $destino instanceof Convenio && BasesDePrecios::canAccess()) {
            return BasesDePrecios::getUrl(['base' => $destino->id]);
        }

        return $this->getResource()::getUrl('index');
    }

    /**
     * Los dos campos de inicialización NO son columnas del convenio.
     * Se sacan antes de crear; si llegaran al `create()`, Laravel los
     * descartaría en silencio por no estar en `$fillable` y la copia
     * nunca ocurriría — sin ningún error a la vista.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->heredarDe = is_string($data['heredar_de'] ?? null)
            ? $data['heredar_de']
            : null;

        $this->porcentajeHeredado = is_scalar($data['porcentaje_heredado'] ?? null)
            ? (string) $data['porcentaje_heredado']
            : '100';

        unset($data['heredar_de'], $data['porcentaje_heredado']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $copiador = app(CopiadorDeBaseDePrecios::class);

        if ($copiador->noCopiaNada($this->heredarDe)) {
            return;
        }

        $destino = $this->getRecord();

        if (! $destino instanceof Convenio) {
            return;
        }

        $porcentaje = NumeroDeFormulario::aDecimal($this->porcentajeHeredado);

        if (! $porcentaje instanceof Decimal || $porcentaje->esCero() || $porcentaje->esNegativo()) {
            Notification::make()
                ->warning()
                ->title('El pagador se creó, pero sin precios')
                ->body('El porcentaje no se entendió. Armá la base desde Bases de precios.')
                ->persistent()
                ->send();

            return;
        }

        $origen = $copiador->origenDesde($this->heredarDe);

        $resultado = $copiador->copiar(
            origen: $origen,
            destino: $destino,
            factor: $porcentaje->entre('100'),
            motivo: sprintf(
                'Heredado de %s al %s %% al dar de alta a %s.',
                $origen->nombre ?? 'el precio de lista',
                $porcentaje->redondeado(2),
                $destino->nombre,
            ),
        );

        $this->heredoPrecios = true;

        Notification::make()
            ->success()
            ->title($destino->nombre.' quedó con '.$resultado['creados'].' precios')
            ->body($resultado['sinPrecioEnElOrigen'] > 0
                ? $resultado['sinPrecioEnElOrigen'].' ítems quedaron sin precio porque tampoco lo tenían en el origen. Se cargan desde Bases de precios.'
                : 'Todo el catálogo quedó con precio. Ajustalos desde Bases de precios.')
            ->persistent()
            ->send();
    }
}
