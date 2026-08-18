<?php

declare(strict_types=1);

namespace App\Filament\Schemas\Components;

use Filament\Forms\Components\TextInput;

/**
 * Campo de texto que siempre guarda en MAYÚSCULAS — §10.4.
 *
 * Se implementa como componente y no como macro `->mayusculas()` a
 * propósito: **PHPStan no puede analizar macros**, porque se registran en
 * runtime, y el §9.B6 prohíbe tapar errores engordando el phpstan.neon.
 * Además esta es la convención que ya usa el proyecto — RTNField,
 * MontoField, TelefonoHondurasField.
 *
 * Devuelve un TextInput, así que se encadena igual que cualquier campo:
 *
 *   CampoMayusculas::make('codigo')
 *       ->label('Código')
 *       ->required()
 *       ->maxLength(10)
 *
 * ─────────────────────────────────────────────────────────────────────
 * TRIPLE DEFENSA — cada capa tapa un hueco de la anterior
 * ─────────────────────────────────────────────────────────────────────
 *
 *  1. **CSS `text-transform`** — el usuario ve mayúsculas mientras
 *     escribe. Es SOLO visual: el valor enviado sigue siendo el tecleado.
 *  2. **`dehydrateStateUsing` con `mb_strtoupper`** — convierte de verdad
 *     antes de guardar, con UTF-8 explícito. `strtoupper()` a secas NO
 *     convierte "ñ" ni las vocales acentuadas: "peña" quedaría "PEñA".
 *  3. **Mutator en el modelo** — el formulario no es la única puerta. Un
 *     import de catálogo, un seeder o un comando escriben directo.
 *
 * ⚠️ NO USAR en nombres de personas, correos, contraseñas, códigos de
 * barras, ni códigos LOINC / ATC / CIE-10.
 *
 * Y sobre todo NO en unidades de dosis: **`mg` y `Mg` no son lo mismo, y
 * en una dosis esa diferencia mata.**
 */
final class CampoMayusculas
{
    public static function make(string $name): TextInput
    {
        return TextInput::make($name)
            ->extraInputAttributes(['style' => 'text-transform: uppercase'])
            ->dehydrateStateUsing(
                fn (?string $state): ?string => $state === null
                    ? null
                    : mb_strtoupper(trim($state), 'UTF-8')
            );
    }
}
