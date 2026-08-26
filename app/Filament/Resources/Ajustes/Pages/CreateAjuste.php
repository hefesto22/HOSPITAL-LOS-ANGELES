<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ajustes\Pages;

use App\Domain\Enums\MotivoDeAjuste;
use App\Domain\Enums\TipoDeAjuste;
use App\Domain\Exceptions\AjusteException;
use App\Domain\Exceptions\ExistenciaInsuficienteException;
use App\Domain\ValueObjects\Decimal;
use App\Domain\ValueObjects\LineaAjustada;
use App\Filament\Resources\Ajustes\AjusteResource;
use App\Models\Almacen;
use App\Models\Item;
use App\Models\Lote;
use App\Models\User;
use App\Services\RegistradorDeAjuste;
use App\Support\NumeroDeFormulario;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Guardar acá NO es guardar un formulario: es mover el kardex.
 *
 * Lo hace `RegistradorDeAjuste`, que arma los objetos del dominio, toma
 * el candado sobre el costo de cada producto, verifica el tope de
 * autorización y hace las tres escrituras por línea —movimiento, cantidad
 * base y línea congelada— en una sola transacción. Si algo falla, no
 * queda nada.
 *
 * Los errores del dominio se traducen a un error de validación y no a una
 * pantalla blanca: quien está registrando una merma a las once de la
 * noche necesita poder corregir la línea sin perder lo tecleado.
 */
class CreateAjuste extends CreateRecord
{
    protected static string $resource = AjusteResource::class;

    /**
     * La clave que hace idempotente ESTE formulario.
     *
     * Se genera una sola vez al montar la pantalla y viaja en el estado
     * de Livewire, así que el segundo envío del mismo formulario —el
     * doble clic, el «volver atrás y guardar otra vez»— trae la misma y
     * el servicio devuelve el ajuste que ya asentó en vez de asentar
     * otro. Dar de baja dos veces un lote vencido de L 8.000 no se puede
     * deshacer: todo esto es append-only.
     */
    public string $claveIdempotencia = '';

    public function mount(): void
    {
        parent::mount();

        $this->claveIdempotencia = (string) Str::uuid();
    }

    protected function handleRecordCreation(array $data): Model
    {
        $almacen = Almacen::query()->findOrFail($data['almacen_id']);
        $tipo = TipoDeAjuste::from((string) ($data['tipo'] ?? TipoDeAjuste::Merma->value));

        try {
            return app(RegistradorDeAjuste::class)->registrar(
                almacen: $almacen,
                tipo: $tipo,
                lineas: $this->lineasDelFormulario($data),
                motivo: is_string($data['motivo'] ?? null) ? $data['motivo'] : '',
                referencia: is_string($data['referencia'] ?? null) ? $data['referencia'] : null,
                ocurridoEn: isset($data['fecha_operacion'])
                    ? Carbon::parse((string) $data['fecha_operacion'])
                    : null,
                autorizador: self::autorizador($data),
                claveIdempotencia: $this->claveIdempotencia,
            );
        } catch (AjusteException|ExistenciaInsuficienteException $e) {
            Notification::make()
                ->danger()
                ->title('No se pudo asentar el ajuste')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            throw ValidationException::withMessages(['data.lineas' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<LineaAjustada>
     */
    private function lineasDelFormulario(array $data): array
    {
        $crudas = is_array($data['lineas'] ?? null) ? $data['lineas'] : [];

        $lineas = [];

        foreach ($crudas as $cruda) {
            if (! is_array($cruda)) {
                continue;
            }

            $motivo = MotivoDeAjuste::tryFrom((string) ($cruda['motivo'] ?? ''));

            if (! $motivo instanceof MotivoDeAjuste) {
                continue;
            }

            /*
             * La dirección solo se pregunta cuando el motivo admite las
             * dos. Para todos los demás el valor del formulario da igual:
             * manda lo que el motivo permite, y si alguien mandó lo
             * contrario, el value object lo rechaza con su mensaje.
             */
            $esEntrada = $motivo->admiteEntrada()
                && ($cruda['direccion'] ?? 'sale') === 'entra';

            $cantidad = NumeroDeFormulario::aDecimal($cruda['cantidad'] ?? null);

            if (! $cantidad instanceof Decimal) {
                throw ValidationException::withMessages([
                    'data.lineas' => 'Hay una cantidad que no se entiende. Escribí solo números, '
                        .'con punto o coma para los decimales.',
                ]);
            }

            $lineas[] = new LineaAjustada(
                item: Item::query()->findOrFail($cruda['item_id']),
                lote: isset($cruda['lote_id']) && is_numeric($cruda['lote_id'])
                    ? Lote::query()->find((int) $cruda['lote_id'])
                    : null,
                motivo: $motivo,
                cantidad: $cantidad,
                esEntrada: $esEntrada,
                texto: is_string($cruda['texto'] ?? null) && $cruda['texto'] !== ''
                    ? $cruda['texto']
                    : null,
            );
        }

        return $lineas;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function autorizador(array $data): ?User
    {
        $id = $data['autorizador_id'] ?? null;

        return is_numeric($id) ? User::query()->find((int) $id) : null;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Ajuste asentado. El kardex ya se movió.';
    }
}
