<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Persona;
use App\Models\PersonaVersion;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Única puerta para cambiar los datos demográficos de una persona (§11).
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ NO ALCANZA CON `$persona->save()`
 * ─────────────────────────────────────────────────────────────────────
 *
 * El registrador escribe la versión 1 al dar de alta al paciente. Si de
 * ahí en adelante el formulario guardara directo al modelo, el historial
 * se quedaría en esa única versión y el ADR-0004 quedaría a medias: se
 * sabría cómo entró el paciente y nunca más cómo fue cambiando.
 *
 * Los tres casos que ese historial resuelve, y que sin él no tienen
 * solución, están en el encabezado de la migración `persona_versiones`:
 * la señora que se casa y cambia de apellido, la fecha de nacimiento mal
 * digitada que ya facturó con descuento, y deshacer una fusión.
 *
 * ─────────────────────────────────────────────────────────────────────
 * TRES DECISIONES QUE VALE LA PENA CONOCER
 * ─────────────────────────────────────────────────────────────────────
 *
 *  1. **Si no cambió nada, no se escribe versión.** Abrir el formulario y
 *     apretar guardar no es un cambio. Un historial lleno de versiones
 *     idénticas es un historial que nadie lee, y el día que haga falta
 *     auditar de verdad no se encuentra la fila que importa.
 *
 *  2. **El motivo es obligatorio.** "Corrección de digitación" y "cambio
 *     de apellido por matrimonio" se ven igual en los datos y significan
 *     cosas distintas: uno dice que el dato anterior estaba MAL, el otro
 *     que era correcto y dejó de serlo. Eso decide si una factura vieja
 *     se reimprime como está o se corrige.
 *
 *  3. **La numeración se serializa con un lock de la fila de la persona.**
 *     Dos ediciones simultáneas del mismo paciente calcularían el mismo
 *     `max(version) + 1` y una de las dos se perdería contra el índice
 *     único. El lock va sobre `personas` y no sobre `persona_versiones`
 *     porque PostgreSQL no permite `FOR UPDATE` junto a un agregado.
 */
final class ActualizadorDePersona
{
    /**
     * Aplica cambios demográficos y deja la versión correspondiente.
     *
     * @param array<string, mixed> $cambios
     */
    public function actualizar(Persona $persona, array $cambios, string $motivo): Persona
    {
        return DB::transaction(function () use ($persona, $cambios, $motivo): Persona {
            /*
             * Serializa las ediciones de ESTA persona. Emergencia editando
             * a otro paciente no espera: el lock es de una fila (§9.H7).
             */
            Persona::query()
                ->whereKey($persona->getKey())
                ->lockForUpdate()
                ->first();

            $antes = $this->foto($persona);

            $persona->fill(array_intersect_key($cambios, array_flip($persona->getFillable())));
            $persona->save();
            $persona->refresh();

            $despues = $this->foto($persona);
            $diferencias = $this->diferencias($antes, $despues);

            if ($diferencias === []) {
                return $persona;
            }

            $this->registrarVersion($persona, $motivo, $diferencias);

            return $persona;
        });
    }

    /**
     * Escribe una version del estado ACTUAL de la persona, siempre.
     *
     * Es publica porque la fusion de duplicados tambien versiona, y la
     * numeracion con lock tiene que vivir en un solo lugar: dos servicios
     * calculando `max(version) + 1` por su cuenta chocan contra el indice
     * unico bajo concurrencia.
     *
     * A diferencia de `actualizar()`, esta NO decide si hubo cambio: una
     * fusion es un cambio aunque los datos demograficos queden iguales.
     *
     * @param array<string, mixed>|null $cambios
     */
    public function registrarVersion(Persona $persona, string $motivo, ?array $cambios = null): PersonaVersion
    {
        return DB::transaction(function () use ($persona, $motivo, $cambios): PersonaVersion {
            Persona::query()
                ->whereKey($persona->getKey())
                ->lockForUpdate()
                ->first();

            /** @var PersonaVersion $version */
            $version = PersonaVersion::query()->create([
                'persona_id'     => $persona->getKey(),
                'version'        => $this->siguienteVersion($persona),
                'datos'          => $this->foto($persona),
                'cambios'        => $cambios,
                'motivo'         => $motivo,
                'registrado_por' => Auth::id(),
                'registrado_en'  => now(),
            ]);

            return $version;
        });
    }

    /**
     * Foto de los campos versionados, ya reducida a escalares.
     *
     * El paso por JSON no es adorno: `only()` devuelve objetos Carbon y
     * enums, y comparar dos instancias distintas con `!==` da siempre
     * "cambió". El historial quedaría diciendo que se modificó la fecha
     * de nacimiento cada vez que alguien abre el formulario.
     *
     * @return array<string, mixed>
     */
    private function foto(Persona $persona): array
    {
        /** @var array<string, mixed> $datos */
        $datos = json_decode(
            (string) json_encode($persona->only(PersonaVersion::camposVersionados())),
            true,
        );

        return $datos;
    }

    /**
     * @param array<string, mixed> $antes
     * @param array<string, mixed> $despues
     *
     * @return array<string, array{antes: mixed, despues: mixed}>
     */
    private function diferencias(array $antes, array $despues): array
    {
        $cambios = [];

        foreach ($despues as $campo => $valor) {
            $anterior = $antes[$campo] ?? null;

            if ($anterior !== $valor) {
                $cambios[$campo] = ['antes' => $anterior, 'despues' => $valor];
            }
        }

        return $cambios;
    }

    private function siguienteVersion(Persona $persona): int
    {
        $ultima = PersonaVersion::query()
            ->where('persona_id', $persona->getKey())
            ->max('version');

        return (int) $ultima + 1;
    }
}
