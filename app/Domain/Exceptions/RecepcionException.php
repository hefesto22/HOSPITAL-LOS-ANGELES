<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * Lo que impide meter algo al estante.
 *
 * Los mensajes están escritos para quien tiene el camión descargado en la
 * puerta y el teléfono en la mano: dicen qué revisar —el envase, el
 * catálogo— y no qué campo falló.
 */
final class RecepcionException extends SihlaException
{
    public static function sinLineas(): self
    {
        return new self(
            'No agregaste ningún producto. Escaneá el código de barras de la caja o buscá el '
            .'producto por nombre.'
        );
    }

    public static function elItemNoMueveInventario(string $item): self
    {
        return new self(
            "{$item} no mueve inventario —es un servicio u honorario— así que no puede entrar "
            .'a una bodega. Si de verdad llegó algo físico, el ítem está mal clasificado en el '
            .'catálogo.'
        );
    }

    public static function laCantidadDebeSerPositiva(string $item): self
    {
        return new self(
            "La cantidad de {$item} tiene que ser mayor que cero. Para sacar mercadería del "
            .'estante está el ajuste, que pide motivo.'
        );
    }

    public static function elContenidoDebeSerPositivo(string $item): self
    {
        return new self(
            "Falta cuántas unidades trae cada presentación de {$item}. Sin ese número no hay "
            .'cómo convertir cajas a tabletas, que es la única unidad en la que se lleva el '
            .'kardex.'
        );
    }

    public static function elCostoNoPuedeSerNegativo(string $item): self
    {
        return new self(
            "El costo de {$item} no puede ser negativo. Cero sí se acepta: es lo que llega "
            .'donado.'
        );
    }

    public static function faltaElNumeroDeLote(string $item): self
    {
        return new self(
            "{$item} se maneja por lote y falta el número. Está impreso en la caja, junto a la "
            .'fecha de vencimiento: sin él no hay cómo saber qué vence cuándo ni despachar '
            .'primero lo que vence antes.'
        );
    }

    public static function vencimientoSinLote(string $item): self
    {
        return new self(
            "Pusiste fecha de vencimiento para {$item} pero no el número de lote. La fecha sola "
            .'no sirve: cuando haya que ir al estante a sacar lo que vence, no hay cómo saber '
            .'cuál caja es.'
        );
    }

    public static function laPresentacionEsDeOtroItem(string $item, string $presentacion): self
    {
        return new self(
            "La presentación «{$presentacion}» no es de {$item}. Con la presentación equivocada "
            .'la conversión a unidades da cualquier cosa, y el kardex queda con un número que '
            .'nadie va a poder explicar.'
        );
    }

    /**
     * El hallazgo que más plata salva de todos.
     */
    public static function loteConOtroVencimiento(
        string $item,
        string $lote,
        string $registrado,
        string $recibido,
    ): self {
        return new self(
            "El lote {$lote} de {$item} ya está registrado con vencimiento {$registrado} y esta "
            ."entrada dice {$recibido}. Un mismo lote no puede vencer dos veces: o el número "
            .'está mal tecleado, o la caja que llegó es de otro lote. Mirá el envase antes de '
            .'guardar.'
        );
    }

    public static function noHayCodigoDeBarras(string $codigo): self
    {
        return new self(
            "Ningún producto tiene el código {$codigo}. Si es la primera vez que se compra esta "
            .'presentación, hay que darla de alta en el catálogo con su código de barras — y '
            .'conviene hacerlo ahora, porque la próxima vez el escaneo va a ser instantáneo.'
        );
    }

    public static function noSeRevisaUnoMismo(): self
    {
        return new self(
            'Esta recepción la cargaste vos, así que la tiene que revisar otra persona. La '
            .'revisión no frena nada —la mercadería ya entró al kardex—, pero firmarse el '
            .'propio trabajo dejaría al reporte de pendientes sin significar nada.'
        );
    }

    public static function faltaQuienRevisa(): self
    {
        return new self(
            'No hay usuario autenticado, así que no se puede marcar como revisada: lo único '
            .'que agrega la revisión es constar QUIÉN miró los números.'
        );
    }

    public static function yaEstaRevisada(): self
    {
        return new self('Esta recepción ya la revisó alguien; no hace falta revisarla otra vez.');
    }
}
