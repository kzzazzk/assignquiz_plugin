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
 * Prints an instance of mod_aiquiz.
 *
 * @package     mod_aiquiz
 * @copyright   2024 Zakaria Lasry zlsahraoui@alumnos.upm.es
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/mod/quiz/accessmanager.php');
require_once($CFG->dirroot . '/mod/aiquiz/accessrule/seb/rule.php');

class aiquiz_access_manager extends quiz_access_manager
{
    public static function load_quiz_and_settings($quizid) {
        global $DB;
        $rules = self::get_rule_classes();
        $rules = self::name_reset_rule($rules, 'quizaccess_seb', 'aiquizaccess_seb', 'aiquizaccess_seb');
        list($sql, $params) = self::get_load_sql($quizid, $rules, 'aiquiz.*');
        $quiz = $DB->get_record_sql($sql, $params, MUST_EXIST);

        foreach ($rules as $rule) {
            foreach ($rule::get_extra_settings($quizid) as $name => $value) {
                $quiz->$name = $value;
            }
        }

        return $quiz;
    }
    public static function aiquiz_validate_settings_form_fields(array $errors,
                                                         array $data, $files, mod_aiquiz_mod_form $quizform) {

        foreach (self::get_rule_classes() as $rule) {
            $errors = $rule::validate_settings_form_fields($errors, $data, $files, $quizform);
        }

        return $errors;
    }
    public static function load_settings($quizid) {
        global $DB;

        $rules = self::get_rule_classes();
        $rules = self::name_reset_rule($rules, 'quizaccess_seb', 'aiquizaccess_seb', 'aiquizaccess_seb');
        list($sql, $params) = self::get_load_sql($quizid, $rules, '');

        if ($sql) {
            $data = (array) $DB->get_record_sql($sql, $params);
        } else {
            $data = array();
        }

        foreach ($rules as $rule) {
            $data += $rule::get_extra_settings($quizid);
        }

        return $data;
    }
    protected static function get_load_sql($quizid, $rules, $basefields) {
        global $DB;
        $allfields = $basefields;
        $alljoins = '{aiquiz} aiquiz';  // Alias is 'aiquiz'
        $allparams = array('quizid' => $quizid);

        foreach ($rules as $rule) {
            list($fields, $joins, $params) = $rule::get_settings_sql($quizid);
            if ($fields) {
                if ($allfields) {
                    $allfields .= ', ';
                }
                $allfields .= $fields;
            }
            if ($joins) {
                // Ensure the JOIN clauses use the correct alias 'aiquiz'
                $joins = str_replace('quiz.id', 'aiquiz.id', $joins); //For some reason some joins are hardcoded, this fixes it.
                $alljoins .= ' ' . $joins;
            }
            if ($params) {
                $allparams += $params;
            }
        }

        if ($allfields === '') {
            return array('', array());
        }

        return array("SELECT $allfields FROM $alljoins WHERE aiquiz.id = :quizid", $allparams);
    }

    public static function save_settings($quiz)
    {
        $rules = self::get_rule_classes();
        $rules = self::name_reset_rule($rules, 'quizaccess_seb', 'aiquizaccess_seb', 'aiquizaccess_seb');
        foreach ($rules as $rule) {
            $rule::save_settings($quiz);
        }
    }

    //Function that switches the name of the rule
    public static function name_reset_rule($rules, $previousname, $newname, $value) {
        unset($rules[$previousname]);
        return $rules + [$newname => $value];
    }
    public function get_end_time($attempt) {
        $timeclose = false;
        foreach ($this->rules as $rule) {
            $ruletimeclose = $rule->end_time($attempt);
            if ($ruletimeclose !== false && ($timeclose === false || $ruletimeclose < $timeclose)) {
                $timeclose = $ruletimeclose;
            }
        }
        return $timeclose;
    }
}