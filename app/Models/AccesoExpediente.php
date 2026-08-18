<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\AccionDeLectura;
use App\Domain\Enums\MotivoBreakTheGlass;
use App\Domain\Exceptions\SihlaException;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una fila de la bitácora de LECTURA (ADR-0004, §9.L6).
 *
 * ⚠️ APPEND-ONLY, y el modelo lo hace cumplir además del diseño de la
 * tabla: `updated`, `deleting` y `saving` sobre un registro existente
 * lanzan excepción.
 *
 * Una bitácora que se puede editar no es evidencia. Y "nadie va a
 * editarla" no es una garantía: un `update()` accidental dentro de un
 * Service, un comando de limpieza mal escrito o un Resource de Filament
 * generado por costumbre bastan.
 *
 * @property int $id
 * @property int $sede_id
 * @property int $user_id
 * @property int|null $paciente_id
 * @property string $recurso_tipo
 * @property int|null $recurso_id
 * @property AccionDeLectura $accion
 * @property MotivoBreakTheGlass|null $motivo
 * @property string|null $motivo_texto
 * @property bool $es_break_the_glass
 * @property CarbonInterface|null $revisado_en
 * @property int|null $revisado_por
 * @property string|null $ip
 * @property string|null $terminal
 * @property CarbonInterface $ocurrido_en
 */
class AccesoExpediente extends Model
{
    protected $table = 'accesos_expediente';

    /**
     * La tabla no tiene created_at/updated_at: tiene `ocurrido_en`, que es
     * cuándo pasó de verdad. No son lo mismo (§8.8).
     */
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'sede_id',
        'user_id',
        'paciente_id',
        'recurso_tipo',
        'recurso_id',
        'accion',
        'motivo',
        'motivo_texto',
        'es_break_the_glass',
        'ip',
        'terminal',
        'ocurrido_en',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accion'             => AccionDeLectura::class,
            'motivo'             => MotivoBreakTheGlass::class,
            'es_break_the_glass' => 'boolean',
            'revisado_en'        => 'datetime',
            'ocurrido_en'        => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new SihlaException(
                'La bitácora de lectura es append-only: un registro de acceso no se puede modificar. '
                .'Si hace falta corregir algo, se agrega una fila nueva.'
            );
        });

        static::deleting(function (): void {
            throw new SihlaException(
                'La bitácora de lectura es append-only: un registro de acceso no se puede borrar. '
                .'El histórico se archiva moviendo particiones frías, nunca eliminando.'
            );
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Sede, $this>
     */
    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    /**
     * Cola de revisión del oficial de privacidad: accesos de emergencia
     * que todavía nadie miró. Usa el índice parcial de la migración.
     *
     * @param Builder<AccesoExpediente> $consulta
     *
     * @return Builder<AccesoExpediente>
     */
    public function scopePendientesDeRevision(Builder $consulta): Builder
    {
        return $consulta
            ->where('es_break_the_glass', true)
            ->whereNull('revisado_en');
    }

    /**
     * ¿Se pasó de la ventana de revisión de 72 horas? (§9.L7)
     */
    public function excedioVentanaDeRevision(): bool
    {
        if ($this->revisado_en !== null) {
            return false;
        }

        $horas = (int) config('sihla.expediente.break_the_glass.horas_para_revision', 72);

        return $this->ocurrido_en->addHours($horas)->isPast();
    }

    /**
     * Marcar como revisado NO es un update del modelo: la tabla es
     * append-only y el modelo lo bloquea a propósito. Se hace con una
     * escritura directa y acotada a estas dos columnas, que son las únicas
     * que la revisión toca — el hecho registrado no se altera nunca.
     */
    public function marcarRevisado(User $revisor): void
    {
        static::query()
            ->whereKey($this->getKey())
            ->where('ocurrido_en', $this->ocurrido_en)
            ->toBase()
            ->update([
                'revisado_en'  => now(),
                'revisado_por' => $revisor->getKey(),
            ]);
    }
}
