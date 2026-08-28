<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Los dos mundos del catálogo: lo que se OFRECE y lo que se GUARDA.
 *
 * ─────────────────────────────────────────────────────────────────────
 * UNA SOLA TABLA, DOS PANTALLAS
 * ─────────────────────────────────────────────────────────────────────
 *
 * Debajo sigue habiendo un único `items` (ADR-0003, §8.4): la factura de
 * un ingreso mezcla habitación, laboratorio y medicamento en el MISMO
 * documento, y esas líneas tienen que salir del mismo lugar. Partir la
 * tabla obligaría a que cada cargo supiera a cuál de las dos apunta.
 *
 * Lo que se parte es la PANTALLA, porque cargar un hemograma y cargar
 * una ampolla no se parecen en nada: uno necesita LOINC y precio a mano;
 * el otro, lote, vencimiento, registro ARSA y costo del que se deriva el
 * precio. Un solo formulario para los dos es un formulario con la mitad
 * de los campos apagados, y ahí se aprende a saltear campos.
 *
 * El eje es `items.se_almacena`, que ya existía. Esto le pone nombre y
 * lo convierte en la puerta por la que se entra.
 */
enum AmbitoCatalogo: string
{
    /** Lo que el hospital ofrece y cobra sin descontar existencia. */
    case Servicios = 'servicios';

    /** Lo que se guarda, se cuenta, se dispensa y tiene stock. */
    case Productos = 'productos';

    /**
     * El valor que le corresponde a `items.se_almacena`.
     *
     * Es la traducción exacta del CHECK
     * `items_categoria_coherente_con_almacenamiento`: si esto y la base
     * dejan de coincidir, la base gana y el INSERT falla.
     */
    public function seAlmacena(): bool
    {
        return $this === self::Productos;
    }

    public static function deSeAlmacena(bool $seAlmacena): self
    {
        return $seAlmacena ? self::Productos : self::Servicios;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Servicios => 'Catálogo de servicios',
            self::Productos => 'Productos de farmacia',
        };
    }

    public function etiquetaCorta(): string
    {
        return match ($this) {
            self::Servicios => 'Servicios',
            self::Productos => 'Farmacia',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Servicios => 'info',
            self::Productos => 'success',
        };
    }

    /**
     * Qué tipos de ítem tienen sentido de este lado.
     *
     * ─────────────────────────────────────────────────────────────────
     * 🔴 `Insumo` VIVE DE UN SOLO LADO (ADR-0006)
     * ─────────────────────────────────────────────────────────────────
     *
     * Antes estaba en los dos, con este argumento: el gel del ecógrafo y
     * el papel de la camilla son insumos que el hospital consume sin
     * inventariar. El argumento no se sostiene, y por dos razones.
     *
     * La primera: **el gel del ecógrafo no se le cobra al paciente**. Una
     * fila del catálogo es, por definición, algo que alguien puede cargar
     * a una cuenta. Si nunca se cobra, no necesita fila — su costo va
     * adentro del precio del ultrasonido, que es donde se recupera.
     *
     * La segunda es el costo de tenerlo, y es silencioso: dos filas
     * «GASA», una que descuenta existencia y otra que no. Quien cobra
     * elige la que le aparezca primero en el buscador, y nadie se entera
     * hasta el conteo físico — cuando falta gasa que el kardex jura que
     * está. Es exactamente lo que esta separación existe para impedir,
     * reabierto justo en el tipo donde más caro sale.
     *
     * El material que se cobra pero no se almacena —un implante que se
     * compra para un paciente— va como `Otro` o dentro del precio del
     * procedimiento. Para un ítem sin stock los dos tipos no se
     * diferencian en nada más que la etiqueta: `precioDerivadoDelCosto()`
     * sigue a `mueveInventario()`, así que el precio se fija a mano igual.
     *
     * @return list<TipoItem>
     */
    public function tiposPermitidos(): array
    {
        return match ($this) {
            self::Productos => [
                TipoItem::Medicamento,
                TipoItem::Insumo,
                TipoItem::Otro,
            ],
            self::Servicios => [
                TipoItem::Servicio,
                TipoItem::Procedimiento,
                TipoItem::EstudioLaboratorio,
                TipoItem::EstudioImagen,
                TipoItem::Honorario,
                TipoItem::Estancia,

                /*
                 * 🔴 `Paquete` NO se ofrece acá, y el tipo sigue
                 * existiendo igual.
                 *
                 * Un paquete no se da de alta a mano: nace cuando un
                 * presupuesto entra a la cuenta y el agregador crea su
                 * ítem técnico (ADR-0009). Ofrecerlo en esta pantalla
                 * invitaría a inventar «CIRUGIA DE APENDICE» como
                 * servicio con precio fijo — que es exactamente lo que
                 * el presupuesto existe para reemplazar: acá no hay
                 * precio fijo, hay un estimado que se negocia caso por
                 * caso.
                 *
                 * El enum lo conserva porque los ítems que YA existen
                 * con ese tipo tienen que poder leerse y mostrarse.
                 */
                TipoItem::Otro,
            ],
        };
    }

    /**
     * Las opciones del selector de tipo, ya etiquetadas.
     *
     * @return array<string, string>
     */
    public function opcionesDeTipo(): array
    {
        $opciones = [];

        foreach ($this->tiposPermitidos() as $tipo) {
            $opciones[$tipo->value] = $tipo->etiqueta();
        }

        return $opciones;
    }
}
