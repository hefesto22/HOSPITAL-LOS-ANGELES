<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Qué pasó en un renglón del historial de la cuenta.
 *
 * Es vocabulario de LECTURA, no de la base: en `cargos` un cambio son
 * dos filas —la reversa del viejo y el renglón nuevo— y un quitar es
 * otra fila más. El historial los cuenta como lo que fueron para quien
 * mira: «cambió», «quitó».
 *
 * Los verbos van en pasado y con el autor como sujeto («Administrador
 * agregó…»): es como se cuenta en el cambio de turno.
 */
enum TipoDeMovimiento: string
{
    case Agrego = 'agrego';
    case Quito = 'quito';
    case Cambio = 'cambio';
    case Cobro = 'cobro';
    case Facturo = 'facturo';
    case Anulo = 'anulo';

    public function verbo(): string
    {
        return match ($this) {
            self::Agrego  => 'Agregó',
            self::Quito   => 'Quitó',
            self::Cambio  => 'Cambió',
            self::Cobro   => 'Cobró',
            self::Facturo => 'Facturó',
            self::Anulo   => 'Anuló',
        };
    }

    /**
     * ¿El monto de este renglón le resta a algo que ya estaba?
     *
     * Quitar un cargo y anular un recibo deshacen plata: el monto se
     * pinta en rojo y con signo, para que no se lea como uno más.
     */
    public function deshace(): bool
    {
        return $this === self::Quito || $this === self::Anulo;
    }
}
