<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Cie10;
use Illuminate\Database\Seeder;

/**
 * LO QUE UN HOSPITAL HONDUREÑO VE TODAS LAS SEMANAS.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NO ES EL CIE-10 COMPLETO, Y ESTÁ BIEN
 * ─────────────────────────────────────────────────────────────────────
 *
 * El catálogo entero son ~14.000 códigos y lo publica la OPS gratis. Eso
 * se carga con un comando cuando el archivo esté; esto es para que el
 * sistema sirva desde el primer día sin esperarlo.
 *
 * 🔴 La columna que importa es `es_notificable`. De ahí sale el reporte
 * del Art. 180 del Código de Salud —notificación epidemiológica
 * obligatoria— sin que dependa de que alguien se acuerde de marcar una
 * casilla. Las marcadas acá son las de vigilancia en Honduras: arbovirosis
 * (dengue, zika, chikungunya), malaria, tuberculosis, leptospirosis,
 * cólera, rabia, meningitis, sarampión, tos ferina, tétanos, hepatitis
 * virales, VIH y sífilis congénita.
 *
 * ⚠️ Si el hospital agrega códigos a mano, la marca de notificable es una
 * decisión sanitaria y no de digitación. Es de dirección médica.
 */
class Cie10DeArranqueSeeder extends Seeder
{
    /**
     * codigo => [descripción, capítulo, ¿notificable?]
     */
    private const CODIGOS = [
        // ── Infecciosas y parasitarias (A00–B99) ──────────────────────
        'A09'   => ['Diarrea y gastroenteritis de presunto origen infeccioso', 'Infecciosas y parasitarias', false],
        'A00.9' => ['Cólera, no especificado', 'Infecciosas y parasitarias', true],
        'A01.0' => ['Fiebre tifoidea', 'Infecciosas y parasitarias', true],
        'A15.0' => ['Tuberculosis pulmonar confirmada bacteriológicamente', 'Infecciosas y parasitarias', true],
        'A27.9' => ['Leptospirosis, no especificada', 'Infecciosas y parasitarias', true],
        'A35'   => ['Tétanos, otros', 'Infecciosas y parasitarias', true],
        'A37.9' => ['Tos ferina, no especificada', 'Infecciosas y parasitarias', true],
        'A39.0' => ['Meningitis meningocócica', 'Infecciosas y parasitarias', true],
        'A41.9' => ['Sepsis, no especificada', 'Infecciosas y parasitarias', false],
        'A50.9' => ['Sífilis congénita, no especificada', 'Infecciosas y parasitarias', true],
        'A82.9' => ['Rabia, no especificada', 'Infecciosas y parasitarias', true],
        'A90'   => ['Dengue sin signos de alarma', 'Infecciosas y parasitarias', true],
        'A91'   => ['Dengue grave (hemorrágico)', 'Infecciosas y parasitarias', true],
        'A92.0' => ['Fiebre de chikungunya', 'Infecciosas y parasitarias', true],
        'A92.8' => ['Enfermedad por virus del Zika', 'Infecciosas y parasitarias', true],
        'B05.9' => ['Sarampión sin complicaciones', 'Infecciosas y parasitarias', true],
        'B15.9' => ['Hepatitis aguda tipo A, sin coma hepático', 'Infecciosas y parasitarias', true],
        'B16.9' => ['Hepatitis aguda tipo B, sin coma hepático', 'Infecciosas y parasitarias', true],
        'B24'   => ['Enfermedad por VIH, sin otra especificación', 'Infecciosas y parasitarias', true],
        'B50.9' => ['Paludismo por Plasmodium falciparum, no especificado', 'Infecciosas y parasitarias', true],
        'B54'   => ['Paludismo no especificado', 'Infecciosas y parasitarias', true],
        'B82.9' => ['Parasitosis intestinal, sin otra especificación', 'Infecciosas y parasitarias', false],

        // ── Endocrinas y metabólicas (E00–E90) ────────────────────────
        'E11.9' => ['Diabetes mellitus tipo 2 sin complicaciones', 'Endocrinas y metabólicas', false],
        'E11.6' => ['Diabetes mellitus tipo 2 con otras complicaciones especificadas', 'Endocrinas y metabólicas', false],
        'E86'   => ['Deshidratación', 'Endocrinas y metabólicas', false],
        'E87.6' => ['Hipopotasemia', 'Endocrinas y metabólicas', false],

        // ── Circulatorio (I00–I99) ────────────────────────────────────
        'I10'   => ['Hipertensión esencial (primaria)', 'Circulatorio', false],
        'I21.9' => ['Infarto agudo de miocardio, no especificado', 'Circulatorio', false],
        'I50.9' => ['Insuficiencia cardíaca, no especificada', 'Circulatorio', false],
        'I64'   => ['Accidente cerebrovascular agudo, no especificado', 'Circulatorio', false],

        // ── Respiratorio (J00–J99) ────────────────────────────────────
        'J00'   => ['Rinofaringitis aguda (resfriado común)', 'Respiratorio', false],
        'J02.9' => ['Faringitis aguda, no especificada', 'Respiratorio', false],
        'J06.9' => ['Infección aguda de las vías respiratorias superiores', 'Respiratorio', false],
        'J18.9' => ['Neumonía, no especificada', 'Respiratorio', false],
        'J45.9' => ['Asma, no especificada', 'Respiratorio', false],
        'J44.9' => ['Enfermedad pulmonar obstructiva crónica, no especificada', 'Respiratorio', false],

        // ── Digestivo (K00–K93) ───────────────────────────────────────
        'K29.7' => ['Gastritis, no especificada', 'Digestivo', false],
        'K35.8' => ['Apendicitis aguda, no especificada', 'Digestivo', false],
        'K40.9' => ['Hernia inguinal unilateral, sin obstrucción ni gangrena', 'Digestivo', false],
        'K80.2' => ['Colelitiasis sin colecistitis', 'Digestivo', false],
        'K92.2' => ['Hemorragia gastrointestinal, no especificada', 'Digestivo', false],

        // ── Genitourinario (N00–N99) ──────────────────────────────────
        'N39.0' => ['Infección de vías urinarias, sitio no especificado', 'Genitourinario', false],
        'N20.0' => ['Cálculo del riñón', 'Genitourinario', false],
        'N17.9' => ['Insuficiencia renal aguda, no especificada', 'Genitourinario', false],

        // ── Embarazo, parto y puerperio (O00–O99) ─────────────────────
        'O80'   => ['Parto único espontáneo', 'Embarazo, parto y puerperio', false],
        'O82'   => ['Parto único por cesárea', 'Embarazo, parto y puerperio', false],
        'O14.9' => ['Preeclampsia, no especificada', 'Embarazo, parto y puerperio', false],
        'O03.9' => ['Aborto espontáneo, completo o no especificado', 'Embarazo, parto y puerperio', false],

        // ── Síntomas y signos (R00–R99): con qué llegan al ingreso ────
        'R10.4' => ['Dolor abdominal, otro y el no especificado', 'Síntomas y signos', false],
        'R50.9' => ['Fiebre, no especificada', 'Síntomas y signos', false],
        'R51'   => ['Cefalea', 'Síntomas y signos', false],
        'R05'   => ['Tos', 'Síntomas y signos', false],
        'R11'   => ['Náusea y vómito', 'Síntomas y signos', false],
        'R55'   => ['Síncope y colapso', 'Síntomas y signos', false],

        // ── Traumatismos y causas externas (S00–T98) ──────────────────
        'S06.0' => ['Concusión (traumatismo craneoencefálico leve)', 'Traumatismos', false],
        'S72.0' => ['Fractura del cuello de fémur', 'Traumatismos', false],
        'T14.9' => ['Traumatismo no especificado', 'Traumatismos', false],
        'T63.0' => ['Efecto tóxico por veneno de serpiente', 'Traumatismos', true],
        'T60.9' => ['Efecto tóxico de plaguicida, no especificado', 'Traumatismos', true],
    ];

    public function run(): void
    {
        foreach (self::CODIGOS as $codigo => [$descripcion, $capitulo, $notificable]) {
            Cie10::query()->updateOrCreate(
                ['version' => 'CIE-10', 'codigo' => $codigo],
                [
                    'descripcion'    => $descripcion,
                    'capitulo'       => $capitulo,
                    'es_notificable' => $notificable,
                ],
            );
        }
    }
}
