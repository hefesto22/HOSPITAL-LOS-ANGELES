<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cuentas\Pages;

use App\Domain\Enums\AccionDeLectura;
use App\Filament\Resources\Cuentas\CuentaResource;
use App\Models\Cuenta;
use App\Support\BitacoraDeLectura;
use Filament\Resources\Pages\ViewRecord;

/**
 * La ficha de una cuenta: la hoja de arriba, la bitácora y los cargos
 * (ver `CuentaInfolist`).
 *
 * ─────────────────────────────────────────────────────────────────────
 * ABRIR ESTA PANTALLA QUEDA REGISTRADO (11-nov-2026)
 * ─────────────────────────────────────────────────────────────────────
 *
 * La cuenta dice qué medicamentos y qué estudios se le hicieron a una
 * persona, y quién pagó: se lleva bitácora de LECTURA (§9.L6), igual que
 * la ficha del paciente. Se hace en el `mount` y no «más adelante»: una
 * bitácora no se reconstruye hacia atrás. `BitacoraDeLectura::registrar()`
 * nunca lanza: si el registro falla, la cuenta igual se abre.
 *
 * ⚠️ Las acciones de esta página van SUELTAS en la cabecera, nunca
 * dentro de un `ActionGroup` (§9.A1): en páginas de cabecera el
 * ActionGroup no recibe `$record`, y las acciones quedan invisibles sin
 * que nada dé error.
 */
class VerCuenta extends ViewRecord
{
    protected static string $resource = CuentaResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $cuenta = $this->getRecord();

        if (! $cuenta instanceof Cuenta) {
            return;
        }

        BitacoraDeLectura::registrar(
            recursoTipo: Cuenta::class,
            recursoId: $cuenta->getKey(),
            pacienteId: $cuenta->encuentro->persona_id,
            accion: AccionDeLectura::Ver,
        );
    }

    /**
     * «Cuenta CTA-…» y no «Ver CTA-…»: el verbo sobra en el título de una
     * ficha.
     */
    public function getTitle(): string
    {
        $cuenta = $this->getRecord();

        return $cuenta instanceof Cuenta ? 'Cuenta '.$cuenta->numero : 'Cuenta';
    }
}
