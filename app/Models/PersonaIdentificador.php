<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\TipoIdentificador;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un documento con el que una persona se identifica.
 *
 * La regla de oro de esta clase: **lo que se guarda en `valor` es SIEMPRE
 * el valor normalizado**, y lo que se muestra se formatea al vuelo.
 *
 * Si esa regla se rompe en un solo lugar —un seeder, un import de padrón,
 * un comando— el sistema tiene el DNI guardado pero no lo encuentra al
 * buscarlo, y admisión crea el duplicado sin enterarse. Por eso la
 * normalización está en un hook de `saving` y no en un mutator de
 * atributo: el mutator depende de que `tipo` ya esté asignado cuando se
 * asigna `valor`, y el orden de asignación de un array no está garantizado.
 *
 * @property int $id
 * @property int $persona_id
 * @property TipoIdentificador $tipo
 * @property string $valor
 * @property string|null $valor_original
 * @property string|null $pais_emision
 * @property bool $es_principal
 * @property CarbonInterface|null $emitido_el
 * @property CarbonInterface|null $vence_el
 * @property CarbonInterface|null $verificado_en
 * @property int|null $verificado_por
 * @property bool $en_conflicto
 * @property string|null $conflicto_nota
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
class PersonaIdentificador extends Model
{
    use HasAuditFields;
    use SoftDeletes;

    protected $table = 'persona_identificadores';

    /** @var list<string> */
    protected $fillable = [
        'persona_id',
        'tipo',
        'valor',
        'valor_original',
        'pais_emision',
        'es_principal',
        'emitido_el',
        'vence_el',
        'verificado_en',
        'verificado_por',
        'en_conflicto',
        'conflicto_nota',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo'          => TipoIdentificador::class,
            'emitido_el'    => 'date',
            'vence_el'      => 'date',
            'verificado_en' => 'datetime',
            'es_principal'  => 'boolean',
            'en_conflicto'  => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $identificador): void {
            $tipo = $identificador->tipo;

            if (! $tipo instanceof TipoIdentificador) {
                return;
            }

            $crudo = $identificador->getAttribute('valor');

            /*
             * Si `valor` no viene o no es texto, no se toca nada: que el
             * CHECK `valor <> ''` de la base rechace la fila. Forzar el
             * cast acá convertiría un dato faltante en un TypeError, que
             * es un error 500 en vez de una validación.
             */
            if (! is_string($crudo)) {
                return;
            }

            /*
             * Se conserva lo que la persona digitó, una sola vez. Sirve
             * para que quien revise un conflicto vea con qué formato lo
             * escribió el otro turno.
             */
            if ($identificador->valor_original === null) {
                $identificador->valor_original = $crudo;
            }

            $identificador->valor = $tipo->normalizar($crudo);

            if ($identificador->pais_emision !== null) {
                $identificador->pais_emision = mb_strtoupper($identificador->pais_emision, 'UTF-8');
            }
        });
    }

    /**
     * Tal como se imprime: 0801-1990-12345.
     */
    public function formateado(): string
    {
        return $this->tipo->formatear($this->valor);
    }

    /**
     * ¿La longitud es la que corresponde al tipo de documento?
     *
     * Devuelve true cuando el tipo no tiene longitud fija. Esto NO valida
     * que el número exista: eso solo lo sabe el RNP. Atrapa el dedazo.
     */
    public function longitudEsValida(): bool
    {
        $esperada = $this->tipo->longitudExacta();

        return $esperada === null || mb_strlen($this->valor) === $esperada;
    }

    public function estaVencidoEn(CarbonInterface $fecha): bool
    {
        return $this->vence_el !== null && $this->vence_el->lessThan($fecha);
    }

    public function estaVerificado(): bool
    {
        return $this->verificado_en !== null;
    }

    /**
     * Los identificadores que valen para el índice único: los que NO están
     * marcados como conflicto.
     *
     * @param Builder<PersonaIdentificador> $consulta
     *
     * @return Builder<PersonaIdentificador>
     */
    public function scopeSinConflicto(Builder $consulta): Builder
    {
        return $consulta->where('en_conflicto', false);
    }

    /**
     * Busca por número, normalizando el término igual que al guardar.
     *
     * Es la consulta que corre ANTES de crear cualquier paciente. Si esta
     * normaliza distinto que el `saving`, el sistema no encuentra lo que
     * él mismo guardó.
     *
     * @param Builder<PersonaIdentificador> $consulta
     *
     * @return Builder<PersonaIdentificador>
     */
    public function scopeDeNumero(Builder $consulta, TipoIdentificador $tipo, string $valor): Builder
    {
        return $consulta
            ->where('tipo', $tipo->value)
            ->where('valor', $tipo->normalizar($valor));
    }

    /**
     * @return BelongsTo<Persona, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verificadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verificado_por');
    }
}
