<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * The main mod_assignquiz configuration form.
 *
 * @package     mod_assignquiz
 * @copyright   2024 Zakaria Lasry Sahraou zsahraoui20@gmail.com
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_grades\component_gradeitems;


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/course/moodleform_mod.php');
require_once($CFG->dirroot.'/mod/quiz/mod_form.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/grade/querylib.php');
require_once($CFG->libdir . '/pdflib.php');
require_once(__DIR__ . '/vendor/autoload.php');
class mod_assignquiz_mod_form extends mod_quiz_mod_form {


    /**
     * Defines forms elements
     */

    protected function definition()
    {
        parent::definition(); // TODO: Change the autogenerated stub
        $mform = $this->_form;
        //feedback will be ai generated, so no need for these elements
        $mform->removeElement('overallfeedbackhdr');
        $mform->removeElement('gradeboundarystatic1');
        $mform->removeElement('gradeboundarystatic2');
        $mform->removeElement('boundary_repeats');
        $mform->removeElement('boundary_add_fields');
        $mform->removeElement('feedbacktext[0]');
    }
    public function data_preprocessing(&$toform)
    {
        if (isset($toform['grade'])) {
            // Convert to a real number, so we don't get 0.0000.
            $toform['grade'] = $toform['grade'] + 0;
        }
        if (count($this->_feedbacks)) {
            $key = 0;
            foreach ($this->_feedbacks as $feedback) {
                $draftid = file_get_submitted_draft_itemid('feedbacktext['.$key.']');
                $toform['feedbacktext['.$key.']']['text'] = file_prepare_draft_area(
                    $draftid,               // Draftid.
                    $this->context->id,     // Context.
                    'mod_quiz',             // Component.
                    'feedback',             // Filarea.
                    !empty($feedback->id) ? (int) $feedback->id : null, // Itemid.
                    null,
                    $feedback->feedbacktext // Text.
                );
                $toform['feedbacktext['.$key.']']['format'] = $feedback->feedbacktextformat;
                $toform['feedbacktext['.$key.']']['itemid'] = $draftid;

                if ($toform['grade'] == 0) {
                    // When a quiz is un-graded, there can only be one lot of
                    // feedback. If the quiz previously had a maximum grade and
                    // several lots of feedback, we must now avoid putting text
                    // into input boxes that are disabled, but which the
                    // validation will insist are blank.
                    break;
                }

                if ($feedback->mingrade > 0) {
                    $toform['feedbackboundaries['.$key.']'] =
                        round(100.0 * $feedback->mingrade / $toform['grade'], 6) . '%';
                }
                $key++;
            }
        }

        if (isset($toform['timelimit'])) {
            $toform['timelimitenable'] = $toform['timelimit'] > 0;
        }

        $this->preprocessing_review_settings($toform, 'during',
            mod_quiz_display_options::DURING);
        $this->preprocessing_review_settings($toform, 'immediately',
            mod_quiz_display_options::IMMEDIATELY_AFTER);
        $this->preprocessing_review_settings($toform, 'open',
            mod_quiz_display_options::LATER_WHILE_OPEN);
        $this->preprocessing_review_settings($toform, 'closed',
            mod_quiz_display_options::AFTER_CLOSE);
        $toform['attemptduring'] = true;
        $toform['overallfeedbackduring'] = false;

        // Password field - different in form to stop browsers that remember
        // passwords from getting confused.
        if (isset($toform['password'])) {
            $toform['quizpassword'] = $toform['password'];
            unset($toform['password']);
        }

        // Load any settings belonging to the access rules.
        if (!empty($toform['instance'])) {
            $accesssettings = aiquiz_access_manager::load_settings($toform['instance']);
            foreach ($accesssettings as $name => $value) {
                $toform[$name] = $value;
            }
        }

        if (empty($toform['completionminattempts'])) {
            $toform['completionminattempts'] = 1;
        } else {
            $toform['completionminattemptsenabled'] = $toform['completionminattempts'] > 0;
        }
    }

    public function data_postprocessing($data)
    {
        $fs = get_file_storage();
        parent::data_postprocessing($data);
        $files = $this->get_pdfs_in_section();
        foreach ($files as $file) {
            $file = $fs->get_file($file->contextid, $file->component, $file->filearea, $file->itemid, $file->filepath, $file->filename);
            $file_content= $file->get_content();
            error_log("PDFS = ".print_r($file_content, true));
            $response = $this->call_api($file_content);
            error_log("RESPONSE = ".print_r($response, true));
        }

    }
    private function get_pdfs_in_section() {
        global $DB;

        $files_in_section = [];
        $resource_id = $DB->get_field('modules', 'id', ['name' => 'resource']);

        // Obtener el ID de la sección
        $section_id = $DB->get_field('course_sections', 'id', ['section' => $this->_section]);

        if (!$section_id) {
            return [];
        }

        // Obtener los IDs de los módulos de recursos en la sección
        $module_ids = $DB->get_fieldset_select('course_modules', 'id', 'section = ? AND module = ?', [$section_id, $resource_id]);

        if (empty($module_ids)) {
            return [];
        }

        // Obtener los contextos de los módulos de recursos
        list($in_sql, $params) = $DB->get_in_or_equal($module_ids);
        $context_ids = $DB->get_fieldset_select('context', 'id', "instanceid $in_sql", $params);

        if (empty($context_ids)) {
            return [];
        }

        // Obtener los PDFs en estos contextos
        list($in_sql, $params) = $DB->get_in_or_equal($context_ids);
        $pdfs = $DB->get_records_sql("
        SELECT *
        FROM {files}
        WHERE contextid $in_sql
        AND component = 'mod_resource'
        AND filesize > 0
        AND filename <> '.'
        AND mimetype = 'application/pdf'
    ", $params);

        return $pdfs;
    }


    private function call_api($file)
    {
        $yourApiKey = getenv('OPENAI_API_KEY');
        $client = OpenAI::client($yourApiKey);
        $response = $this->upload_file_to_openai($client, $file);
        return $response;
    }

    private function upload_file_to_openai($client, $file)
    {
        $response = $client->files()->upload([
            'purpose' => 'assistants',
            'file' =>  $file,
        ]);

        return $response;
    }

    private function openai_create_assistant($client){
        $response = $client->assistants()->create([
            'instructions' => 'Eres un generador de preguntas de opción múltiple en español basadas en documentos PDF.
            Genera preguntas únicas con 4 opciones de respuesta cada una, asegurando una única respuesta correcta por pregunta.

            Reglas estrictas:
            - No incluyas pistas en la redacción de las preguntas.
            - Cubre todo el documento con las preguntas, no solo fragmentos.
            - Varía la posición de la respuesta correcta para evitar patrones predecibles.
            - Usa español, salvo términos sin traducción en el texto original.
            - No preguntes sobre ubicaciones (página/sección) dentro del documento.
            - Evita preguntas de definición directa; prioriza preguntas conceptuales y aplicadas.
            - No formules preguntas cuya respuesta se mencione directamente en el enunciado.
            - Si no es posible generar 10 preguntas, proporciona tantas como sea posible siguiendo el mismo formato.

            Formato de salida:
                [Número]. Pregunta: [Texto de la pregunta]
                Opciones:
                A. [Opción 1]
                B. [Opción 2]
                C. [Opción 3]
                D. [Opción 4]
                Respuesta correcta: [Letra]',
            'name' => 'Moodle PDF to Quiz Generator',
            'tools' => [
                [
                    'type' => 'file_search',
                ],
            ],
            'model' => "gpt-4o-mini",
        ]);
        return $response;
    }

    private function openai_create_thread($client, $file_id, $assistant_id){
        $thread_create_response = $client->threads()->create([]);

        $client->threads()->messages()->create($thread_create_response->id, [
            'role' => 'user',
            'content' => 'Genera 10 preguntas para un cuestionario basándote en el documento adjuntado',
            'attachments' => [
                [
                    'file_id' => $file_id,
                    'tools' => [
                        [
                            'type' => 'file_search',
                        ],
                    ],
                ],
            ],
        ]);
        $response = $client->threads()->runs()->create_and_poll(
            threadId: $thread_create_response->id,
            parameters: [
                'assistant_id' => $assistant_id,
            ],
        );
        return $response;
    }
}