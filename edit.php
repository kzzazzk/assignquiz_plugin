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
 * The main mod_aiquiz configuration form.
 *
 * @package     mod_aiquiz
 * @copyright   2024 Zakaria Lasry Sahraou zsahraoui20@gmail.com
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/mod/aiquiz/locallib.php');
require_once($CFG->dirroot . '/mod/quiz/addrandomform.php');
require_once($CFG->dirroot . '/question/editlib.php');
require_once($CFG->dirroot . '/mod/aiquiz/classes/question/bank/custom_view.php');

// These params are only passed from page request to request while we stay on
// this page otherwise they would go in question_edit_setup.
$scrollpos = optional_param('scrollpos', '', PARAM_INT);
list($thispageurl, $contexts, $cmid, $cm, $quiz, $pagevars) =
    question_edit_setup('editq', '/mod/aiquiz/edit.php', true);

$defaultcategoryobj = question_make_default_categories($contexts->all());
$defaultcategory = $defaultcategoryobj->id . ',' . $defaultcategoryobj->contextid;

$quizhasattempts = aiquiz_has_attempts($quiz->id);

$PAGE->set_url($thispageurl);
$PAGE->set_secondary_active_tab("mod_aiquiz_edit");

// Get the course object and related bits.
$course = $DB->get_record('course', array('id' => $quiz->course), '*', MUST_EXIST);
$quizobj = new aiquiz($quiz, $cm, $course);
$structure = $quizobj->get_structure();

// You need mod/quiz:manage in addition to question capabilities to access this page.
require_capability('mod/quiz:manage', $contexts->lowest());

// Process commands ============================================================.

// Get the list of question ids had their check-boxes ticked.
$selectedslots = array();
$params = (array) data_submitted();
foreach ($params as $key => $value) {
    if (preg_match('!^s([0-9]+)$!', $key, $matches)) {
        $selectedslots[] = $matches[1];
    }
}

$afteractionurl = new moodle_url($thispageurl);
if ($scrollpos) {
    $afteractionurl->param('scrollpos', $scrollpos);
}

if (optional_param('repaginate', false, PARAM_BOOL) && confirm_sesskey()) {
    // Re-paginate the quiz.
    $structure->check_can_be_edited();
    $questionsperpage = optional_param('questionsperpage', $quiz->questionsperpage, PARAM_INT);
    aiquiz_repaginate_questions($quiz->id, $questionsperpage );
    aiquiz_delete_previews($quiz);
    redirect($afteractionurl);
}

if (($addquestion = optional_param('addquestion', 0, PARAM_INT)) && confirm_sesskey()) {
    // Add a single question to the current quiz.
    $structure->check_can_be_edited();
    quiz_require_question_use($addquestion);
    $addonpage = optional_param('addonpage', 0, PARAM_INT);
    aiquiz_add_quiz_question($addquestion, $quiz, $addonpage);
    aiquiz_delete_previews($quiz);
    aiquiz_update_sumgrades($quiz);
    $thispageurl->param('lastchanged', $addquestion);
    redirect($afteractionurl);
}

if (optional_param('add', false, PARAM_BOOL) && confirm_sesskey()) {
    $structure->check_can_be_edited();
    $addonpage = optional_param('addonpage', 0, PARAM_INT);
    // Add selected questions to the current quiz.
    $rawdata = (array) data_submitted();
    foreach ($rawdata as $key => $value) { // Parse input for question ids.
        if (preg_match('!^q([0-9]+)$!', $key, $matches)) {
            $key = $matches[1];
            quiz_require_question_use($key);
            aiquiz_add_quiz_question($key, $quiz, $addonpage);
        }
    }
    aiquiz_delete_previews($quiz);
    aiquiz_update_sumgrades($quiz);
    redirect($afteractionurl);
}

if ($addsectionatpage = optional_param('addsectionatpage', false, PARAM_INT)) {
    // Add a section to the quiz.
    $structure->check_can_be_edited();
    $structure->add_section_heading($addsectionatpage);
    aiquiz_delete_previews($quiz);
    redirect($afteractionurl);
}

if ((optional_param('addrandom', false, PARAM_BOOL)) && confirm_sesskey()) {
    // Add random questions to the quiz.
    $structure->check_can_be_edited();
    $recurse = optional_param('recurse', 0, PARAM_BOOL);
    $addonpage = optional_param('addonpage', 0, PARAM_INT);
    $categoryid = required_param('categoryid', PARAM_INT);
    $randomcount = required_param('randomcount', PARAM_INT);
    aiquiz_add_random_questions($quiz, $addonpage, $categoryid, $randomcount, $recurse);

    aiquiz_delete_previews($quiz);
    aiquiz_update_sumgrades($quiz);
    redirect($afteractionurl);
}

if (optional_param('savechanges', false, PARAM_BOOL) && confirm_sesskey()) {

    // If rescaling is required save the new maximum.
    $maxgrade = unformat_float(optional_param('maxgrade', '', PARAM_RAW_TRIMMED), true);
    if (is_float($maxgrade) && $maxgrade >= 0) {
        aiquiz_set_grade($maxgrade, $quiz);
        aiquiz_update_all_final_grades($quiz);
        quiz_update_grades($quiz, 0, true);
    }

    redirect($afteractionurl);
}

// Log this visit.
$event = \mod_quiz\event\edit_page_viewed::create([
    'courseid' => $course->id,
    'context' => $contexts->lowest(),
    'other' => [
        'quizid' => $quiz->id
    ]
]);
$event->trigger();

// Get the question bank view.
$questionbank = new mod_aiquiz\question\bank\aiquiz_custom_view($contexts, $thispageurl, $course, $cm, $quiz);
$questionbank->set_quiz_has_attempts($quizhasattempts);

// End of process commands =====================================================.

$PAGE->set_pagelayout('incourse');
$PAGE->set_pagetype('mod-quiz-edit');

$output = $PAGE->get_renderer('mod_aiquiz', 'aiquizedit');

$PAGE->set_title(get_string('editingquizx', 'quiz', format_string($quiz->name)));
$PAGE->set_heading($course->fullname);
$PAGE->activityheader->disable();
$node = $PAGE->settingsnav->find('mod_aiquiz_edit', navigation_node::TYPE_SETTING);
if ($node) {
    $node->make_active();
}
echo $OUTPUT->header();

// Initialise the JavaScript.
$quizeditconfig = new stdClass();
$quizeditconfig->url = $thispageurl->out(true, array('qbanktool' => '0'));
$quizeditconfig->dialoglisteners = array();
$numberoflisteners = $DB->get_field_sql("
    SELECT COALESCE(MAX(page), 1)
      FROM {aiquiz_slots}
     WHERE quizid = ?", array($quiz->id));

for ($pageiter = 1; $pageiter <= $numberoflisteners; $pageiter++) {
    $quizeditconfig->dialoglisteners[] = 'addrandomdialoglaunch_' . $pageiter;
}

$PAGE->requires->data_for_js('quiz_edit_config', $quizeditconfig);
$PAGE->requires->js('/question/qengine.js');

// Questions wrapper start.
echo html_writer::start_tag('div', array('class' => 'mod-quiz-edit-content'));
$value =  $output->aiquizpage_edit_page($quizobj, $structure, $contexts, $thispageurl, $pagevars);
echo $value;
// Questions wrapper end.
echo html_writer::end_tag('div');

echo $OUTPUT->footer();
