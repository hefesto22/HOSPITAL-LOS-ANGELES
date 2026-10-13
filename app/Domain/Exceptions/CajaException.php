<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * Lo que puede salir mal cuando entra plata.
 *
 * Todos los mensajes dicen QUÉ HACER. Quien está en el mostrador tiene a
 * la familia enfrente con los billetes en la mano: «operación inválida»
 * lo deja parado, «abrí tu turno y volvé a intentar» lo destraba.
 */
final class CajaException extends SihlaException
{
    /**
     * 🔴 Sin turno abierto no se recibe plata.
     *
     * No es burocracia: el efectivo entra a una gaveta que alguien
     * cuenta al final. Un abono sin turno es plata que nadie cuadra
     * contra billetes, y ahí es donde desaparece el dinero.
     */
    public static function sinTurnoAbierto(): self
    {
        return new self(
            'No hay un turno de caja abierto. Abrí el turno en «Caja» —con el fondo con el que arranca la gaveta— '
            .'y volvé a recibir el abono. Sin turno, el efectivo no cuadra con nadie al cerrar.'
        );
    }

    public static function yaTenesUnTurnoAbierto(string $numero): self
    {
        return new self(
            "Ya tenés el turno {$numero} abierto. Cerralo con su arqueo antes de abrir otro: "
            .'con dos turnos abiertos, tus recibos se reparten entre los dos y ninguno cuadra.'
        );
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * 🔴 LA GAVETA ES UNA SOLA
     * ─────────────────────────────────────────────────────────────────
     *
     * Si el turno A sigue abierto, no se abre el B en la misma gaveta: al
     * final de la noche habría un solo montón de billetes con dos
     * arqueos, que es exactamente lo que el arqueo existe para impedir.
     *
     * El mensaje cambia según quién pregunta: a quien puede cerrar el A
     * se le dice que lo cierre; a los demás, quién lo tiene que cerrar.
     *
     * ⚠️ Bloquea ABRIR TURNO, no el acceso al sistema. Quien llega
     * igual puede consultar una cuenta o reimprimir una factura; lo que
     * no puede es cobrar hasta que el anterior cuadre lo suyo. Cerrarle
     * el sistema entero termina en que use la clave de otro, y ahí se
     * pierde el rastro que todo esto sostiene.
     */
    public static function laGavetaYaEstaAbierta(string $turno, string $quien, string $desde, bool $loPodesCerrar): self
    {
        return new self($loPodesCerrar
            ? "El {$turno} sigue abierto (lo abrió {$quien} a las {$desde}). Cerralo con su arqueo y después se abre el que sigue."
            : "{$quien} abrió el {$turno} a las {$desde} y la gaveta es una sola. Lo cierra quien es de ese turno o la administración; después se abre el que sigue.");
    }

    /**
     * Quien quiere cobrar en la gaveta, o cerrarla, y no es de ese turno.
     */
    public static function noEsTuTurno(string $turno, ?string $elTuyo): self
    {
        $suyo = $elTuyo === null || trim($elTuyo) === ''
            ? 'No tenés un turno asignado en tu usuario'
            : 'Tu turno es el '.mb_strtoupper(trim($elTuyo), 'UTF-8');

        return new self(
            "La gaveta está en el {$turno}. {$suyo}: cobra y cierra quien es de ese turno o la administración. "
            .'Si hoy lo cubrís vos, pedí que te asignen ese turno en tu usuario.'
        );
    }

    public static function elTurnoYaEstaCerrado(string $numero): self
    {
        return new self("El turno {$numero} ya está cerrado. Su arqueo no se vuelve a tocar.");
    }

    /**
     * El monto del recibo y la suma de sus medios no coinciden.
     */
    public static function losMediosNoCuadran(string $total, string $suma): self
    {
        return new self(
            "El recibo dice L {$total} pero las formas de pago suman L {$suma}. "
            .'Corregí los montos: el recibo tiene que decir exactamente lo que entró.'
        );
    }

    public static function sinFormasDePago(): self
    {
        return new self('Falta decir con qué se pagó. Un abono sin forma de pago es plata que entró de la nada.');
    }

    public static function montoInvalido(): self
    {
        return new self('El monto del abono tiene que ser mayor que cero.');
    }

    public static function laCuentaNoRecibeAbonos(string $numero, string $estado): self
    {
        return new self(
            "La cuenta {$numero} está {$estado} y no recibe abonos. "
            .'Si el paciente todavía debe, eso se cobra contra la factura, no contra la cuenta.'
        );
    }

    public static function elAbonoYaEstaAnulado(string $numero): self
    {
        return new self("El abono {$numero} ya está anulado.");
    }

    /**
     * 🔴 El arqueo cerrado no se toca.
     *
     * Anular un recibo de un turno cerrado sacaría plata de un conteo
     * que ya se firmó y se entregó. Eso es una devolución: otro hecho,
     * con su propio movimiento de caja.
     */
    public static function noSeAnulaConElTurnoCerrado(string $numero): self
    {
        return new self(
            "El abono {$numero} pertenece a un turno ya cerrado, con su efectivo contado y entregado. "
            .'Devolverle plata al paciente a esta altura es una devolución, no una anulación.'
        );
    }

    /**
     * 🔴 Lo mismo que en la factura: el CHECK
     * `abonos_anulacion_completa` exige autor, y un recibo anulado por
     * nadie es un faltante sin explicación.
     */
    public static function sinQuienAnula(): self
    {
        return new self('No se pudo identificar quién anula el recibo. Volvé a entrar al sistema e intentá de nuevo.');
    }

    public static function faltaElMotivo(): self
    {
        return new self('Escribí por qué se anula, con al menos diez caracteres. Un recibo anulado sin motivo es un faltante sin explicación.');
    }

    /**
     * Sobró o faltó efectivo y nadie escribió por qué.
     */
    public static function laDiferenciaExigeExplicacion(string $diferencia): self
    {
        return new self(
            "El arqueo da una diferencia de L {$diferencia}. Escribí qué pasó antes de cerrar: "
            .'un faltante explicado tres días después ya no lo puede explicar nadie.'
        );
    }

    public static function faltaElBanco(): self
    {
        return new self(
            'Falta decir a qué banco se depositó. Sin eso, el depósito hay que ir a buscarlo '
            .'estado de cuenta por estado de cuenta.'
        );
    }

    public static function elBancoEsSoloDelDeposito(): self
    {
        return new self('El banco solo se llena en transferencias o depósitos. El efectivo y la tarjeta no lo llevan.');
    }

    public static function elEfectivoContadoEsInvalido(): self
    {
        return new self('El efectivo contado no puede ser negativo. Si la gaveta quedó vacía, es cero.');
    }
}
