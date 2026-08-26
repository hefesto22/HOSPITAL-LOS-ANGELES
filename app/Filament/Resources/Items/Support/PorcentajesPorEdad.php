<?php

declare(strict_types=1);

namespace App\Filament\Resources\Items\Support;

use App\Domain\Enums\CategoriaLegalDeDescuento;
use App\Domain\Enums\RangoEdad;
use App\Domain\Exceptions\DescuentoNoFijableException;
use App\Domain\ValueObjects\Decimal;
use App\Models\DescuentoLegal;
use App\Models\Item;
use App\Services\FijadorDeDescuentoLegal;
use App\Support\NumeroDeFormulario;
use Filament\Notifications\Notification;

/**
 * El porcentaje de una edad, cargado desde la ficha del ítem.
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 EL PORCENTAJE ES DE LA CATEGORÍA, NO DE ESTE ÍTEM
 * ─────────────────────────────────────────────────────────────────────
 *
 * Es lo único importante de esta clase, y por eso está arriba de todo.
 * La ley fija el descuento por NUMERAL del Artículo 30 —«servicio
 * hospitalario», «consulta general»—, no producto por producto. Así que
 * escribir 30 % acá desde una radiografía se lo cambia también a las
 * otras cuarenta radiografías, y a la hospitalización, y a todo lo que
 * caiga en el mismo numeral.
 *
 * Eso no es un defecto: es la ley. Lo que sí sería un defecto es
 * ocultarlo. Por eso el formulario dice, con nombre y número, cuántos
 * ítems comparten la categoría antes de que alguien teclee.
 *
 * ─────────────────────────────────────────────────────────────────────
 * UNA EDAD POR VEZ, Y SOLO SI SE ELIGE
 * ─────────────────────────────────────────────────────────────────────
 *
 * Se elige la edad en un selector y recién ahí aparece el campo del
 * porcentaje. Sin ese paso, guardar el ítem por cualquier otro motivo
 * —corregir una tilde del nombre— pasaría igual por los campos de
 * descuento. Con él, no tocar el selector significa exactamente «no
 * toqué ningún porcentaje», que es lo que hace segura una pantalla que
 * puede reescribir la ley.
 */
final class PorcentajesPorEdad
{
    public const CAMPO_RANGO = 'rango_edad_a_cargar';

    public const CAMPO_PORCENTAJE = 'porcentaje_por_edad';

    public const CAMPO_FUNDAMENTO = 'fundamento_por_edad';

    /**
     * Las edades que se pueden cargar, con su tramo: «Tercera edad
     * (60–79)», «Cuarta edad (80 en adelante)».
     *
     * El tramo se lee de `config('sihla.edad.rangos_por_defecto')` y no
     * se escribe acá: la ley ya cambió una vez las edades y va a volver a
     * cambiarlas. Quemar 60 y 80 en una etiqueta es cómo una pantalla
     * termina diciendo algo distinto de lo que el sistema calcula.
     *
     * @return array<string, string>
     */
    public static function opcionesDeRango(): array
    {
        $opciones = [];

        foreach (RangoEdad::conDerechoADescuento() as $rango) {
            $opciones[$rango->value] = $rango->etiqueta().' '.self::tramoDe($rango);
        }

        return $opciones;
    }

    public static function tramoDe(RangoEdad $rango): string
    {
        /** @var array<string, array{desde?: int, hasta?: int|null}> $rangos */
        $rangos = config('sihla.edad.rangos_por_defecto', []);

        $desde = $rangos[$rango->value]['desde'] ?? null;
        $hasta = $rangos[$rango->value]['hasta'] ?? null;

        if (! is_int($desde)) {
            return '';
        }

        return is_int($hasta)
            ? "({$desde}–{$hasta} años)"
            : "({$desde} años en adelante)";
    }

    public static function rangoDe(mixed $valor): ?RangoEdad
    {
        return is_string($valor) ? RangoEdad::tryFrom($valor) : null;
    }

    /**
     * El porcentaje vigente hoy, como número de formulario: «25» o «30».
     *
     * ⚠️ Se pregunta por el rango EXACTO y no por el resolutor, que sube
     * la escalera. Si la cuarta edad no tiene fila propia, el resolutor
     * devolvería el 25 % de la tercera — y el campo aparecería lleno,
     * haciendo creer que está cargada. Vacío es la verdad: no hay fila.
     */
    public static function vigente(?CategoriaLegalDeDescuento $categoria, ?RangoEdad $rango): ?string
    {
        if (! $categoria instanceof CategoriaLegalDeDescuento
            || ! $rango instanceof RangoEdad
            || $categoria === CategoriaLegalDeDescuento::SinDescuentoLegal) {
            return null;
        }

        $fila = DescuentoLegal::query()
            ->where('categoria_legal', $categoria->value)
            ->where('rango_edad', $rango->value)
            ->vigentesEn(now())
            ->first();

        return $fila instanceof DescuentoLegal
            ? $fila->fraccion()->por('100')->redondeado(2)
            : null;
    }

    /**
     * Lo que rige hoy para las dos edades, en una línea.
     *
     * Se muestra siempre, aunque no se vaya a cambiar nada: es la
     * respuesta a «¿cuánto se le descuenta a un adulto mayor en esto?»,
     * que es la pregunta que trae a alguien a esta pantalla.
     */
    public static function resumen(?CategoriaLegalDeDescuento $categoria): string
    {
        if (! $categoria instanceof CategoriaLegalDeDescuento
            || $categoria === CategoriaLegalDeDescuento::SinDescuentoLegal) {
            return 'Este ítem no lleva descuento de ley.';
        }

        $partes = [];

        foreach (RangoEdad::conDerechoADescuento() as $rango) {
            $vigente = self::vigente($categoria, $rango);

            $partes[] = $rango->etiqueta().' '.self::tramoDe($rango).': '
                .($vigente === null ? 'sin cargar' : $vigente.' %');
        }

        return implode(' · ', $partes)
            .'. Una edad sin porcentaje propio recibe el de la anterior, nunca cero.';
    }

    /**
     * Cuántos OTROS ítems comparten la categoría, para poder decirlo
     * antes de que alguien teclee.
     */
    public static function cuantosItemsComparten(?CategoriaLegalDeDescuento $categoria, ?Item $excepto = null): int
    {
        if (! $categoria instanceof CategoriaLegalDeDescuento) {
            return 0;
        }

        return Item::query()
            ->where('categoria_legal_descuento', $categoria->value)
            ->when($excepto instanceof Item, fn ($consulta) => $consulta->whereKeyNot($excepto?->getKey()))
            ->count();
    }

    /**
     * Escribe el porcentaje de la edad elegida, si de verdad cambió.
     *
     * @param array<string, mixed> $data lo que se sacó del formulario
     */
    public static function guardar(array $data, Item $item): bool
    {
        $categoria = $item->categoria_legal_descuento;
        $rango = self::rangoDe($data[self::CAMPO_RANGO] ?? null);

        if ($categoria === CategoriaLegalDeDescuento::SinDescuentoLegal || ! $rango instanceof RangoEdad) {
            return false;
        }

        $nuevo = NumeroDeFormulario::aDecimal($data[self::CAMPO_PORCENTAJE] ?? null);

        if (! $nuevo instanceof Decimal) {
            /* Eligió la edad y no escribió nada: no hay nada que guardar. */
            return false;
        }

        /*
         * Si vuelve el mismo número no se escribe. Sin esta comparación,
         * abrir el ítem y guardarlo dejaría una fila de descuento nueva
         * cada vez, y el historial de la ley se llenaría de ruido que
         * nadie puso a propósito.
         */
        if ($nuevo->redondeado(2) === self::vigente($categoria, $rango)) {
            return false;
        }

        $fundamento = is_string($data[self::CAMPO_FUNDAMENTO] ?? null)
            && mb_strlen(trim($data[self::CAMPO_FUNDAMENTO])) >= 10
            ? trim($data[self::CAMPO_FUNDAMENTO])
            : '⚠️ VERIFICAR: cargado desde el catálogo, sin citar el decreto.';

        try {
            app(FijadorDeDescuentoLegal::class)->fijar(
                categoria: $categoria,
                rango: $rango,
                porcentaje: $nuevo->entre('100'),
                fundamento: $fundamento,
                desde: now(),
                exigeReceta: $categoria->exigeReceta(),
            );
        } catch (DescuentoNoFijableException $e) {
            Notification::make()
                ->warning()
                ->title('El porcentaje de la '.mb_strtolower($rango->etiqueta()).' no se guardó')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return false;
        }

        Notification::make()
            ->success()
            ->title($rango->etiqueta().': '.$nuevo->redondeado(2).' %')
            ->body('Rige para todo «'.$categoria->etiqueta().'». El historial queda en «Descuentos de ley».')
            ->send();

        return true;
    }
}
