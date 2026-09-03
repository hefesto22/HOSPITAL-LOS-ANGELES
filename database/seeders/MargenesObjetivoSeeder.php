<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Enums\TipoItem;
use App\Models\MargenObjetivo;
use Illuminate\Database\Seeder;

/**
 * El margen con el que arranca el hospital.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SOLO SE SIEMBRA LO QUE SE COMPRA — Y ESO ES DOS TIPOS
 * ─────────────────────────────────────────────────────────────────────
 *
 * El margen objetivo existe para DERIVAR un precio del costo (la Ruta A
 * del §4.1). Solo tiene costo lo que el hospital compra y guarda, y
 * `CalculadoraDePrecioDeLista` lo hace explícito en su primera línea:
 *
 *     if (! $item->tipo->precioDerivadoDelCosto()) {
 *         throw PrecioNoDerivableException::elTipoNoSeCompra(...);
 *     }
 *
 * `precioDerivadoDelCosto()` es `mueveInventario()`, y eso es
 * **medicamentos e insumos, nada más**. Los servicios, procedimientos,
 * honorarios, estancias y estudios llevan su precio de lista escrito a
 * mano en el tarifario (la Ruta B): la calculadora los RECHAZA, así que
 * ningún margen los toca nunca. El propio formulario lo dice — el
 * desplegable se arma con `tiposQueSeCompran()` y ofrece esos dos.
 *
 * Las dos decisiones, las dos fechadas:
 *
 *   · 17-ago-2026 — el margen nunca baja del 120 % en medicamentos, sin
 *     importar la edad del paciente ni el descuento legal.
 *   ·  3-sep-2026 — los insumos y el material de curación van al mismo
 *     120 %, con su propia fila.
 *
 * Que hoy coincidan en el número no las vuelve la misma decisión, y por
 * eso son filas separadas: el día que los insumos bajen al 80 %, se le
 * cierra la vigencia a esa fila y medicamentos no se entera.
 *
 * ⚠️ La fila de insumos rige **desde el 17-ago** aunque se haya decidido
 * el 3-sep. No es un error: el 120 % ya se les aplicaba desde entonces
 * por el default de la instalación. La decisión del 3-sep lo hizo
 * explícito, no cambió el número — y la fecha de la decisión vive en el
 * motivo, que es donde se lee. Fecharla el 3-sep abriría un hueco de dos
 * semanas sin margen para insumos.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ YA NO SE SIEMBRA UN «TODO LO DEMÁS»
 * ─────────────────────────────────────────────────────────────────────
 *
 * Había una tercera fila con `tipo_item` nulo, el default de la
 * instalación. Con medicamentos e insumos cubiertos —los DOS únicos
 * tipos que llegan a la calculadora— esa fila era **inalcanzable**:
 * `scopeParaElTipo()` pone el específico primero y siempre hay uno.
 * Aparecía en la pantalla de márgenes diciendo que afectaba a algo.
 *
 * Y no era solo ruido. El día que un tipo nuevo mueva inventario
 * —prótesis, reactivos— ese default lo tarifaría **en silencio** al
 * 120 % en vez de obligar a decidir el número. Es el mismo default
 * silencioso que el §9 prohíbe y el mismo error del ×1.20 que se borró
 * del precio de lista: se ve razonable, nadie lo revisa, y a los tres
 * meses es el precio real por inercia.
 *
 * Sin esa fila, un tipo sin margen hace que la calculadora tire
 * `noHayMargenDefinido` con el tipo y la fecha adentro. Eso es lo
 * correcto: sin margen definido no hay precio que calcular.
 *
 * El MECANISMO del default sigue existiendo —`scopeParaElTipo()` lo trae
 * y hay un test que lo prueba—; lo que ya no hay es una fila sembrada
 * ocupando lugar sin hacer nada.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ESTE SEEDER PLANTA LA PRIMERA FILA Y NUNCA MÁS TOCA EL TIPO
 * ─────────────────────────────────────────────────────────────────────
 *
 * ⚠️ La versión anterior hacía `updateOrCreate` con la vigencia adentro
 * de la llave, y eso reventó apenas cambió la fecha:
 *
 *     conflicting key value violates exclusion constraint
 *     "margenes_objetivo_sin_traslape"
 *     Key (…, vigencia)=(insumo, [2026-08-17,)) conflicts with existing
 *     (…, vigencia)=(insumo, [2026-09-03,))
 *
 * Con la vigencia en la llave, mover la fecha no actualiza la fila: abre
 * OTRA, y quedan dos márgenes abiertos del mismo tipo — que es justo lo
 * que el EXCLUDE existe para impedir. Es el mismo error que rompió el
 * seed del catálogo con `tarifarios_sin_traslape`, en otra tabla.
 *
 * La regla ahora: **si el tipo ya tiene historial, el seeder no lo
 * toca.** No es solo para poder re-correrlo; es para que un `db:seed`
 * del deploy NO pise una decisión tomada después desde la pantalla. El
 * margen es una tabla versionada: el seeder planta el arranque y se
 * aparta.
 *
 * ⚠️ Para registrar un CAMBIO de margen no se edita este archivo: se le
 * pone `vigencia_hasta` a la fila vieja y se inserta la nueva. Cuando en
 * 2028 alguien pregunte por qué un producto se vendía a ese precio en
 * 2026, la respuesta tiene que ser una fila con fecha.
 */
class MargenesObjetivoSeeder extends Seeder
{
    /** El día que Mauricio fijó la política de precios del hospital. */
    private const DESDE = '2026-08-17';

    public function run(): void
    {
        $plantados = 0;

        $plantados += (int) $this->fijar(
            tipo: TipoItem::Medicamento,
            porcentaje: '1.2000',
            motivo: 'Decisión de Mauricio del 17-ago-2026: el margen nunca baja del '
                        .'120 % en medicamentos, sin importar la edad del paciente.',
        );

        $plantados += (int) $this->fijar(
            tipo: TipoItem::Insumo,
            porcentaje: '1.2000',
            motivo: 'Decisión de Mauricio del 3-sep-2026: los insumos y el material de curación '
                        .'van al mismo 120 % que los medicamentos. Fila propia y no compartida, '
                        .'para poder cambiar uno sin tocar el otro.',
        );

        $this->command?->info("✓ Márgenes objetivo: {$plantados} de 2 plantados al 120 %; el resto ya tenía historial y no se tocó.");
        $this->command?->comment('  Medicamentos e insumos son los dos únicos tipos que se compran; el resto lleva precio de lista escrito a mano.');
        $this->command?->comment('  Falta el margen por categoría comercial dentro de cada tipo (#2 del §7).');
    }

    /**
     * Planta el margen de arranque del tipo. Devuelve `false` si ya había
     * historial y no hizo nada.
     *
     * ⚠️ NO es `updateOrCreate` sobre (tipo, vigencia_desde): con la
     * vigencia en la llave, cambiar la fecha abre una SEGUNDA fila abierta
     * del mismo tipo y `margenes_objetivo_sin_traslape` la rechaza. Y
     * tampoco es «escribir sobre la fila abierta», porque esa fila puede
     * ser una decisión que alguien tomó desde la pantalla después del
     * primer seed — y un `db:seed` del deploy no puede revertirla en
     * silencio.
     *
     * @param numeric-string $porcentaje
     */
    private function fijar(TipoItem $tipo, string $porcentaje, string $motivo): bool
    {
        if (MargenObjetivo::query()->where('tipo_item', $tipo->value)->exists()) {
            return false;
        }

        MargenObjetivo::query()->create([
            'tipo_item'      => $tipo,
            'porcentaje'     => $porcentaje,
            'motivo'         => $motivo,
            'vigencia_desde' => self::DESDE,
            'vigencia_hasta' => null,
        ]);

        return true;
    }
}
