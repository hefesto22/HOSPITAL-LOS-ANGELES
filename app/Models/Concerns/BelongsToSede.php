<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Sede;
use App\Support\ContextoSede;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alcance por sede — el patrón que se repite en TODA tabla transaccional.
 *
 * Reemplaza al trait `BelongsToEmpresa` que traía la plantilla Olympo y
 * que se borró en la Etapa 0: aquel era multi-tenant, que el ADR-0002
 * descarta. Este es jerarquía de negocio dentro de un mismo dueño.
 *
 * Hace dos cosas y las hace en un solo lugar:
 *
 *  1. **Rellena `sede_id` al crear** desde el contexto, para que nadie
 *     tenga que acordarse. Un `sede_id` nulo en una tabla clínica es un
 *     registro que después nadie puede atribuir.
 *  2. **Filtra las consultas** a las sedes que el usuario puede ver.
 *
 * ⚠️ Lo que este trait NO hace, y hay que saberlo:
 *
 * El scope global NO protege el acceso directo por ID en una ruta de
 * Filament — `->find($id)` respeta el scope, pero un Resource mal armado
 * puede saltárselo. Por eso el ADR-0002 exige un test que golpee la URL
 * de edición, no solo el listado. **El scope es comodidad; el test es la
 * garantía.**
 *
 * @phpstan-require-extends Model
 */
trait BelongsToSede
{
    public static function bootBelongsToSede(): void
    {
        static::creating(function (Model $modelo): void {
            if ($modelo->getAttribute('sede_id') === null) {
                $modelo->setAttribute('sede_id', ContextoSede::actualId());
            }
        });

        static::addGlobalScope('sede', function (Builder $consulta): void {
            $visibles = ContextoSede::idsVisibles();

            // null = ve todas (o estamos en consola). No se filtra.
            if ($visibles === null) {
                return;
            }

            $consulta->whereIn($consulta->qualifyColumn('sede_id'), $visibles);
        });
    }

    /**
     * @return BelongsTo<Sede, $this>
     */
    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    /**
     * Escotilla explícita para reportes de dirección y procesos de consola.
     *
     * Se llama a propósito y se ve en el código de quien la usa: quitar un
     * filtro de seguridad nunca debe ser el comportamiento por defecto de
     * nada.
     *
     * @param Builder<static> $consulta
     *
     * @return Builder<static>
     */
    public function scopeDeTodasLasSedes(Builder $consulta): Builder
    {
        return $consulta->withoutGlobalScope('sede');
    }
}
