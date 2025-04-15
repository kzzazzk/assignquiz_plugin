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

use Spatie\PdfToText\Pdf;


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/course/moodleform_mod.php');
require_once($CFG->dirroot.'/mod/quiz/mod_form.php');
require_once($CFG->dirroot.'/mod/quiz/locallib.php');
require_once($CFG->dirroot.'/mod/assignquiz/locallib.php');
require_once($CFG->dirroot.'/grade/querylib.php');
require_once($CFG->dirroot.'/vendor/autoload.php');
require_once($CFG->dirroot.'/question/type/multichoice/questiontype.php'); // Add this line

class mod_assignquiz_mod_form extends mod_quiz_mod_form {


    /**
     * Defines forms elements
     */

    protected function definition()
    {
        global $DB, $CFG;

        parent::definition(); // TODO: Change the autogenerated stub
        $mform = $this->_form;
        $mform->setDefault('preferredbehaviour', 'deferredfeedback');
        $mform->setDefault('decimalpoints', 2);
        $mform->setDefault('questiondecimalpoints', -1);
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
    public function validation($data, $files)
    {
        $errors = [];
// Check open and close times are consistent.
        if ($data['timeopen'] != 0 && $data['timeclose'] != 0 &&
            $data['timeclose'] < $data['timeopen']) {
            $errors['timeclose'] = get_string('closebeforeopen', 'quiz');
        }

        // Check that the grace period is not too short.
        if ($data['overduehandling'] == 'graceperiod') {
            $graceperiodmin = get_config('quiz', 'graceperiodmin');
            if ($data['graceperiod'] <= $graceperiodmin) {
                $errors['graceperiod'] = get_string('graceperiodtoosmall', 'quiz', format_time($graceperiodmin));
            }
        }

        if (!empty($data['completionminattempts'])) {
            if ($data['attempts'] > 0 && $data['completionminattempts'] > $data['attempts']) {
                $errors['completionminattemptsgroup'] = get_string('completionminattemptserror', 'quiz');
            }
        }

//        // Check the boundary value is a number or a percentage, and in range.
//        $i = 0;
//        while (!empty($data['feedbackboundaries'][$i] )) {
//            $boundary = trim($data['feedbackboundaries'][$i]);
//            if (strlen($boundary) > 0) {
//                if ($boundary[strlen($boundary) - 1] == '%') {
//                    $boundary = trim(substr($boundary, 0, -1));
//                    if (is_numeric($boundary)) {
//                        $boundary = $boundary * $data['grade'] / 100.0;
//                    } else {
//                        $errors["feedbackboundaries[$i]"] =
//                            get_string('feedbackerrorboundaryformat', 'quiz', $i + 1);
//                    }
//                } else if (!is_numeric($boundary)) {
//                    $errors["feedbackboundaries[$i]"] =
//                        get_string('feedbackerrorboundaryformat', 'quiz', $i + 1);
//                }
//            }
//            if (is_numeric($boundary) && $boundary <= 0 || $boundary >= $data['grade'] ) {
//                $errors["feedbackboundaries[$i]"] =
//                    get_string('feedbackerrorboundaryoutofrange', 'quiz', $i + 1);
//            }
//            if (is_numeric($boundary) && $i > 0 &&
//                $boundary >= $data['feedbackboundaries'][$i - 1]) {
//                $errors["feedbackboundaries[$i]"] =
//                    get_string('feedbackerrororder', 'quiz', $i + 1);
//            }
//            $data['feedbackboundaries'][$i] = $boundary;
//            $i += 1;
//        }
//        $numboundaries = $i;
//
//        // Check there is nothing in the remaining unused fields.
//        if (!empty($data['feedbackboundaries'])) {
//            for ($i = $numboundaries; $i < count($data['feedbackboundaries']); $i += 1) {
//                if (!empty($data['feedbackboundaries'][$i] ) &&
//                    trim($data['feedbackboundaries'][$i] ) != '') {
//                    $errors["feedbackboundaries[$i]"] =
//                        get_string('feedbackerrorjunkinboundary', 'quiz', $i + 1);
//                }
//            }
//        }
//        for ($i = $numboundaries + 1; $i < count($data['feedbacktext']); $i += 1) {
//            if (!empty($data['feedbacktext'][$i]['text']) &&
//                trim($data['feedbacktext'][$i]['text'] ) != '') {
//                $errors["feedbacktext[$i]"] =
//                    get_string('feedbackerrorjunkinfeedback', 'quiz', $i + 1);
//            }
//        }

        // If CBM is involved, don't show the warning for grade to pass being larger than the maximum grade.
        if (($data['preferredbehaviour'] == 'deferredcbm') OR ($data['preferredbehaviour'] == 'immediatecbm')) {
            unset($errors['gradepass']);
        }
        // Any other rule plugins.
        $errors = aiquiz_access_manager::assignquiz_validate_settings_form_fields($errors, $data, $files, $this);

        return $errors;
    }
}