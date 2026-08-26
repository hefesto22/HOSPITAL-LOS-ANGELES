<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * Lo que impide contar, o lo que impide cerrar el conteo.
 *
 * Los mensajes están escritos para quien está parado frente al estante
 * con la pistola en la mano: dicen qué falta hacer, no qué validación
 * falló. Y cuando el sistema se niega a algo, dicen POR QUÉ se niega —
 * porque el que cuenta a las once de la noche merece saber si vale la
 * pena insistir o si tiene que llamar a alguien.
 */
final class ConteoException extends SihlaException
{
    public static function yaHayUnoAbierto(string $almacen): self
    {
        return new self(
            "Ya hay un conteo abierto en {$almacen}. Terminá ese antes de abrir otro: con dos "
            .'conteos sobre el mismo estante, la misma diferencia se asienta dos veces y el '
            .'inventario queda el doble de mal que antes de contar.'
        );
    }

    public static function noEstaAbierto(string $estado): self
    {
        return new self(
            "Este conteo está {$estado}, así que ya no admite cambios. Sus líneas explican "
            .'movimientos del kardex que no se pueden editar; para corregir algo, se asienta un '
            .'ajuste con su motivo.'
        );
    }

    public static function elAlmacenNoTieneNadaQueContar(string $almacen): self
    {
        return new self(
            "{$almacen} no tiene ninguna existencia registrada, así que no hay nada que contar. "
            .'Si el estante tiene mercadería, primero hay que meterla al sistema con una '
            .'recepción — que es la que captura el costo, sin el cual no hay precio de venta.'
        );
    }

    public static function laCantidadNoPuedeSerNegativa(string $item): self
    {
        return new self(
            "No se puede contar una cantidad negativa de {$item}. Si el estante está vacío, el "
            .'número es cero, y ese cero hay que teclearlo: dejarlo en blanco significa «no lo '
            .'conté todavía», que es otra cosa.'
        );
    }

    public static function elLoteNoEsDelItem(string $item, string $lote): self
    {
        return new self(
            "El lote {$lote} no es de {$item}. Contarlo así movería los dos productos: uno de "
            .'más y el otro de menos, y el error recién se vería en el conteo siguiente.'
        );
    }

    public static function faltaElLote(string $item): self
    {
        return new self(
            "{$item} se maneja por lote, así que hay que contar lote por lote. Un número global "
            .'no dice cuál de los dos vencimientos es el que falta, y ese es justamente el dato '
            .'que se necesita para saber qué se va a vencer en el estante.'
        );
    }

    public static function faltaQuienCuenta(): self
    {
        return new self(
            'No hay usuario autenticado. Un conteo sin nombre no sirve para nada: lo que le da '
            .'valor a la cifra es saber quién la vio.'
        );
    }

    /**
     * No debería pasar nunca: la fila se acaba de crear y otra
     * transacción la borró en el medio. Se dice en voz alta en vez de
     * dejar que reviente con «llamada a un método sobre null».
     */
    public static function laLineaDesaparecio(): self
    {
        return new self(
            'La línea del conteo desapareció mientras se guardaba. Volvé a escanear el producto; '
            .'si pasa otra vez, avisá — significa que alguien está borrando líneas del conteo.'
        );
    }

    public static function faltanLineasPorContar(int $cuantas): self
    {
        $cuenta = $cuantas === 1 ? 'Falta 1 línea' : "Faltan {$cuantas} líneas";

        return new self(
            "{$cuenta} por contar y este es un conteo total. Si esos productos no están en el "
            .'estante, hay que teclear cero en cada uno: dar por cero lo que nadie contó '
            .'borraría existencias que sí están.'
        );
    }

    public static function faltanRecuentos(int $cuantas): self
    {
        $cuenta = $cuantas === 1
            ? 'Hay 1 línea con una diferencia grande'
            : "Hay {$cuantas} líneas con una diferencia grande";

        return new self(
            "{$cuenta} que todavía nadie volvió a contar. El segundo conteo existe porque la "
            .'mayoría de las diferencias grandes son errores de la primera pasada, no faltantes '
            .'reales.'
        );
    }

    public static function noSeCierraSolo(): self
    {
        return new self(
            'Este conteo lo abriste vos, así que lo tiene que cerrar otra persona. Cerrar es lo '
            .'que asienta los faltantes en el kardex, y un faltante que firma el mismo que contó '
            .'es un faltante que nadie verificó.'
        );
    }

    public static function laLineaYaSeConto(): self
    {
        return new self(
            'Esta línea ya se contó, así que no se puede sacar del conteo: sacarla dejaría el '
            .'documento diciendo que ese producto nunca estuvo en la planilla. Si el número está '
            .'mal, contala otra vez — la lectura nueva reemplaza a la vieja y la anterior queda '
            .'guardada.'
        );
    }

    public static function laExistenciaCambioDemasiado(string $item, string $diferencia): self
    {
        return new self(
            "No se pudo asentar la diferencia de {$item} ({$diferencia}): desde que se contó, la "
            .'existencia bajó tanto que el ajuste dejaría el estante en negativo. La línea queda '
            .'sin asentar y hay que contarla de nuevo en un conteo aparte.'
        );
    }

    public static function faltaQuienCierra(): self
    {
        return new self(
            'No hay usuario autenticado, y cerrar un conteo mueve el inventario. Sin nombre no '
            .'se asienta.'
        );
    }

    public static function yaSeCerro(): self
    {
        return new self(
            'Este conteo ya se cerró y sus diferencias ya están asentadas. Volver a cerrarlo '
            .'duplicaría el ajuste.'
        );
    }

    public static function faltaElMotivoDeAnulacion(): self
    {
        return new self(
            'Para anular un conteo hay que explicar por qué, con al menos diez caracteres. Un '
            .'conteo anulado sin explicación deja sin sentido la tarde que alguien pasó contando '
            .'el estante.'
        );
    }

    public static function demasiadasLineas(int $lineas, int $maximo): self
    {
        return new self(
            "Este conteo tiene {$lineas} líneas y el máximo por documento es {$maximo}. No es un "
            .'capricho: cerrarlo bloquea cada fila de existencia que toca, y una transacción '
            .'larga sobre el inventario frena la farmacia entera. Partilo en conteos parciales '
            .'por sección del estante.'
        );
    }
}
