<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\TextoCanonico;
use Illuminate\Database\Eloquent\Model;

/**
 * Deja en forma canónica los campos de texto que el modelo declare.
 *
 * Se aplica en `saving`, o sea sobre TODA escritura: formulario, seeder,
 * import de padrón, comando de consola, servicio de dominio. Es el mismo
 * criterio que llevó a que `personas.nombre_busqueda` la calcule
 * PostgreSQL en vez de un observer — **el formulario no es la única
 * puerta**, y una regla que solo vive en el formulario no es una regla.
 *
 * Cada modelo declara su lista. No hay una lista global ni un "todos los
 * strings", y eso es deliberado: hay campos que NO se deben tocar y la
 * lista explícita obliga a decidir uno por uno. Ver el docblock de
 * `TextoCanonico` para cuáles y por qué.
 *
 * Uso:
 *
 *   class Persona extends Model
 *   {
 *       use GuardaEnMayusculas;
 *
 *       public static function camposEnMayusculas(): array
 *       {
 *           return ['primer_nombre', 'primer_apellido'];
 *       }
 *   }
 *
 * @phpstan-require-extends Model
 */
trait GuardaEnMayusculas
{
    public static function bootGuardaEnMayusculas(): void
    {
        static::saving(function (Model $modelo): void {
            $atributos = $modelo->getAttributes();

            foreach (static::camposEnMayusculas() as $campo) {
                /*
                 * Solo se toca lo que viene en esta escritura. Importa
                 * cuando el modelo se arma parcial —`create(['nombre' =>
                 * ...])`— y no trae el resto de los campos: sin la
                 * guarda, se les asignaría null.
                 */
                if (! array_key_exists($campo, $atributos)) {
                    continue;
                }

                $valor = $atributos[$campo];

                if ($valor !== null && ! is_string($valor)) {
                    continue;
                }

                $modelo->setAttribute($campo, TextoCanonico::mayusculas($valor));
            }
        });
    }

    /**
     * Campos de este modelo que se guardan en forma canónica.
     *
     * @return array<int, string>
     */
    abstract public static function camposEnMayusculas(): array;
}
