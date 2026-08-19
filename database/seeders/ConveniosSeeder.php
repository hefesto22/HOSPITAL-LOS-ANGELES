<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Enums\BaseDelDescuentoLegal;
use App\Domain\Enums\TipoConvenio;
use App\Models\Convenio;
use Illuminate\Database\Seeder;

/**
 * El único pagador que no se negocia.
 *
 * ─────────────────────────────────────────────────────────────────────
 * POR QUÉ ACÁ SOLO ESTÁ CONTADO
 * ─────────────────────────────────────────────────────────────────────
 *
 * El hospital trabaja con aseguradoras privadas, con el IHSS y con
 * convenios institucionales. Ninguno se siembra, y no es olvido: **cada
 * uno tiene que declarar sobre qué monto se le aplica el descuento del
 * adulto mayor**, y esa decisión no la puede tomar un seeder.
 *
 * El Art. 30 del Decreto 199-2006 no dice qué pasa cuando la factura la
 * paga un tercero. Sembrar el IHSS con una lectura elegida por mí sería
 * exactamente el default silencioso que la tabla está diseñada para
 * impedir: nadie revisa un dato que ya parece decidido. Se cargan por
 * pantalla, que es donde el formulario pone las tres opciones y su
 * explicación a la vista y exige escribir el fundamento.
 *
 * CONTADO es la excepción porque ahí no hay nada que interpretar: el
 * paciente paga de su bolsillo, así que lo que paga ES el total
 * facturado y las tres lecturas coinciden.
 */
class ConveniosSeeder extends Seeder
{
    /**
     * La fecha más temprana que el sistema contempla. No hay atención
     * anterior a esto, así que el pagador de contado vale desde acá.
     */
    private const DESDE = '2026-01-01';

    public function run(): void
    {
        Convenio::query()->updateOrCreate(
            ['codigo' => Convenio::CODIGO_CONTADO],
            [
                'nombre'               => 'PACIENTE PARTICULAR',
                'tipo'                 => TipoConvenio::Contado,
                'base_descuento_legal' => BaseDelDescuentoLegal::SobreLoQuePagaElPaciente,
                'fundamento_descuento' => 'El paciente paga de su bolsillo, así que lo que paga '
                    .'es el total facturado: el descuento del Art. 30 del Decreto 199-2006 cae '
                    .'sobre el precio de lista sin ambigüedad. No hay tercero pagador que '
                    .'discutir.',
                'requiere_autorizacion' => false,
                'dias_credito'          => null,
                'vigencia_desde'        => self::DESDE,
                'vigencia_hasta'        => null,
            ],
        );

        $this->command?->info('✓ Convenio CONTADO listo.');
        $this->command?->comment(
            '  Las aseguradoras, el IHSS y los convenios institucionales se cargan por pantalla:'
        );
        $this->command?->comment(
            '  cada uno tiene que declarar sobre qué monto aplica el descuento del Art. 30 (#16 del §7).'
        );
    }
}
