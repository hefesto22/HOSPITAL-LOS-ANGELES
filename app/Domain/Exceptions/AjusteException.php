<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * Lo que impide asentar un ajuste.
 *
 * Casi todas estas negativas existen para que el ajuste no se convierta
 * en lo que es en la mayoría de los sistemas: el lugar donde un faltante
 * desaparece sin que nadie tenga que explicarlo.
 */
final class AjusteException extends SihlaException
{
    public static function sinLineas(): self
    {
        return new self(
            'Un ajuste sin productos no ajusta nada. Agregá al menos una línea con su cantidad '
            .'y su motivo.'
        );
    }

    public static function faltaElMotivo(): self
    {
        return new self(
            'Falta explicar qué pasó, con al menos diez caracteres. El motivo tipificado dice la '
            .'categoría —rotura, derrame, vencido—; esto es el caso concreto, y es lo único que '
            .'va a quedar para entenderlo dentro de un año.'
        );
    }

    public static function laCantidadDebeSerPositiva(string $item): self
    {
        return new self(
            "La cantidad de {$item} tiene que ser mayor que cero. El signo lo pone el motivo, no "
            .'el número: para que sume se elige un motivo de entrada.'
        );
    }

    public static function elMotivoNoAdmiteEsaDireccion(string $motivo, bool $esEntrada): self
    {
        $direccion = $esEntrada ? 'sumar' : 'restar';

        return new self(
            "El motivo «{$motivo}» no puede {$direccion} existencia. Dejar que cualquier motivo "
            .'vaya en cualquier dirección es cómo un faltante se asienta como sobrante y '
            .'desaparece del reporte.'
        );
    }

    public static function elMotivoNoEsDeEseTipo(string $motivo, string $tipo): self
    {
        return new self(
            "El motivo «{$motivo}» no corresponde a un ajuste de tipo «{$tipo}». Mezclarlos "
            .'rompe el reporte que separa la merma del vencimiento, que es justamente el que se '
            .'mira para saber si el hospital compra de más.'
        );
    }

    public static function noSeCreaAMano(string $tipo): self
    {
        return new self(
            "Un ajuste de tipo «{$tipo}» no se crea a mano: nace del cierre de un conteo físico, "
            .'con la evidencia de lo que se contó detrás. Poder escribirlo directo sería poder '
            .'declarar un faltante sin haber contado nada.'
        );
    }

    /**
     * 🔴 §9.F11 — el ajuste directo de un controlado no existe.
     */
    public static function esUnControlado(string $item): self
    {
        return new self(
            "{$item} es un medicamento controlado y su existencia NO se ajusta directamente. "
            .'Toda entrada y toda salida de un controlado pasa por el libro de estupefacientes '
            .'y psicotrópicos, con folio, saldo corrido y doble firma. El ajuste libre de un '
            .'controlado es el mecanismo por el cual desaparece el fentanilo y el hospital '
            .'pierde la licencia.'
        );
    }

    public static function exigeAutorizacion(string $valor, string $tope): self
    {
        return new self(
            "Este ajuste vale L {$valor} al costo y el tope sin autorización es L {$tope}. "
            .'Necesita que lo autorice alguien de dirección; el nombre queda guardado en el '
            .'documento.'
        );
    }

    public static function elAutorizadorNoPuede(string $nombre): self
    {
        return new self(
            "{$nombre} no tiene rol para autorizar ajustes por encima del tope. Quien autoriza "
            .'es quien responde por la pérdida, y por eso no es el mismo que la registra.'
        );
    }

    public static function noSeAutorizaUnoMismo(): self
    {
        return new self(
            'No podés autorizar tu propio ajuste. Un tope que se levanta uno mismo no es un tope.'
        );
    }

    public static function noEsSuAlmacen(string $almacen): self
    {
        return new self(
            "No podés contar ni ajustar en {$almacen}: no es un almacén de tu área. Bodega "
            .'responde por la bodega central y los stocks de servicio; farmacia, por la '
            .'farmacia. Quien responde por el estante es quien lo cuenta.'
        );
    }

    public static function faltaQuienAjusta(): self
    {
        return new self(
            'No hay usuario autenticado. Un ajuste sin responsable es exactamente lo que este '
            .'documento existe para impedir.'
        );
    }

    public static function elLoteNoEsDelItem(string $item, string $lote): self
    {
        return new self(
            "El lote {$lote} no es de {$item}. Ajustar con el lote de otro producto deja los dos "
            .'kardex mal a la vez.'
        );
    }

    public static function faltaElLote(string $item): self
    {
        return new self(
            "{$item} se maneja por lote, así que hay que decir cuál se está ajustando. Sin lote "
            .'no hay forma de saber qué vencimiento se perdió ni de rastrear el producto hasta '
            .'el paciente si el laboratorio manda a retirarlo.'
        );
    }
}
