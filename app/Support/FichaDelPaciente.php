<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Enums\SexoBiologico;
use App\Models\Persona;
use App\Models\PersonaIdentificador;
use Carbon\CarbonInterface;

/**
 * Lo que se ve del paciente al abrirle una cuenta: en cada resultado del
 * buscador y en la ficha del elegido (Mauricio, 26-oct-2026: «que el
 * diseño sea mejor, más bonito»).
 *
 * ─────────────────────────────────────────────────────────────────────
 * 🔴 NO ES ADORNO: SON LOS DOS IDENTIFICADORES
 * ─────────────────────────────────────────────────────────────────────
 *
 * El buscador devolvía solo el nombre, y dos «JOSÉ ANTONIO MARTÍNEZ» se
 * veían iguales. Ahora cada resultado y la ficha dicen el nombre, la
 * fecha de nacimiento y el documento: los dos identificadores (§9.D2) y
 * la alerta de homónimos (§9.D3) empiezan en la admisión, que es donde
 * nace la cuenta al paciente equivocado.
 *
 * ─────────────────────────────────────────────────────────────────────
 * UNA CLASE APARTE, Y NO MÉTODOS PÚBLICOS DE LA PÁGINA
 * ─────────────────────────────────────────────────────────────────────
 *
 * Un método público de un componente Livewire se puede llamar desde el
 * navegador, y lo que devuelve vuelve al cliente. Con el id de cualquier
 * persona, `$wire.fichaDelPaciente(…)` devolvería su nombre, su fecha de
 * nacimiento y su DNI. Acá se prueba sin exponer nada.
 *
 * ⚠️ Espera los identificadores ya cargados (`with('identificadores')`):
 * en el buscador son veinte personas, y leerlos uno por uno serían
 * veinte consultas por tecla (§13.2). `preventLazyLoading` lo atrapa en
 * los tests.
 */
final class FichaDelPaciente
{
    /**
     * @return array{iniciales: string, nombre: string, nn: bool, datos: list<string>}
     */
    public static function de(Persona $persona, CarbonInterface $hoy): array
    {
        return [
            'iniciales' => self::iniciales($persona),
            'nombre'    => $persona->nombreCompleto(),
            'nn'        => $persona->es_nn,
            'datos'     => self::datos($persona, $hoy),
        ];
    }

    /**
     * El renglón del buscador, en HTML: el nombre arriba y, debajo, con
     * qué distinguirlo. Si ya tiene cuenta viva, lo dice en ámbar: el
     * renglón se ve pero no se puede elegir (`disableOptionWhen`).
     *
     * Todo lo que viene de la base pasa por `e()`. Los estilos van en
     * línea: la lista del select es de Filament, con su CSS ya compilado
     * (§9.A7).
     */
    public static function opcionDelBuscador(Persona $persona, CarbonInterface $hoy, ?string $cuentaAbierta = null): string
    {
        $html = '<span style="display:block;font-weight:600;">'.e($persona->nombreCompleto()).'</span>'
            .'<span style="display:block;font-size:.75rem;line-height:1.1rem;opacity:.7;">'
            .e(implode(' · ', self::datos($persona, $hoy)))
            .'</span>';

        if ($cuentaAbierta !== null) {
            $html .= '<span style="display:block;font-size:.75rem;line-height:1.1rem;font-weight:600;color:rgb(217 119 6);">'
                .'Ya tiene '.e($cuentaAbierta).' abierta'
                .'</span>';
        }

        return $html;
    }

    /**
     * Fecha de nacimiento, edad, sexo y documento, en ese orden.
     *
     * @return list<string>
     */
    public static function datos(Persona $persona, CarbonInterface $hoy): array
    {
        $datos = self::nacimiento($persona, $hoy);

        if ($persona->sexo_biologico !== SexoBiologico::Desconocido) {
            $datos[] = $persona->sexo_biologico->etiqueta();
        }

        $datos[] = self::documento($persona);

        return $datos;
    }

    private static function iniciales(Persona $persona): string
    {
        $letras = mb_substr($persona->primer_nombre, 0, 1).mb_substr($persona->primer_apellido ?? '', 0, 1);

        return $letras === '' ? '?' : mb_strtoupper($letras);
    }

    /**
     * «Nació 12/03/1980» y «45 años».
     *
     * Con la fecha estimada no se escribe el día: sería inventarle un
     * cumpleaños al NN. Se dice la edad con «≈». El bebé de días no tiene
     * «0 años»: tiene días o meses, que es como lo dice enfermería.
     *
     * @return list<string>
     */
    private static function nacimiento(Persona $persona, CarbonInterface $hoy): array
    {
        $nacio = $persona->fecha_nacimiento;

        if ($nacio === null) {
            return ['Sin fecha de nacimiento'];
        }

        $edad = self::edad($nacio, $hoy, $persona->edadEn($hoy) ?? 0);

        if (! $persona->fechaNacimientoEsExacta()) {
            return ['≈ '.$edad.' (fecha aproximada)'];
        }

        return ['Nació '.$nacio->format('d/m/Y'), $edad];
    }

    private static function edad(CarbonInterface $nacio, CarbonInterface $hoy, int $anios): string
    {
        if ($anios >= 1) {
            return $anios === 1 ? '1 año' : "{$anios} años";
        }

        $meses = max(0, (int) $nacio->diffInMonths($hoy));

        if ($meses >= 1) {
            return $meses === 1 ? '1 mes' : "{$meses} meses";
        }

        $dias = max(0, (int) $nacio->diffInDays($hoy));

        return match ($dias) {
            0       => 'Recién nacido',
            1       => '1 día',
            default => "{$dias} días",
        };
    }

    /**
     * El principal, que es con el que se factura; si no hay, el primero.
     * «Sin documento» es un estado válido —el NN, el recién nacido—, no
     * un dato que falte llenar.
     */
    private static function documento(Persona $persona): string
    {
        $identificadores = $persona->identificadores;

        $principal = $identificadores->firstWhere('es_principal', true) ?? $identificadores->first();

        return $principal instanceof PersonaIdentificador
            ? $principal->tipo->etiqueta().' '.$principal->formateado()
            : 'Sin documento';
    }
}
