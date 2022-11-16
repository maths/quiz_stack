<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Step definitions related to quiz_stack.
 *
 * @package   quiz_stack
 * @category  test
 * @copyright 2021 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

use Behat\Gherkin\Node\TableNode;
use Behat\Mink\Exception\ExpectationException;

require_once(__DIR__ . '/../../../../../../lib/behat/behat_base.php');

/**
 * Step definitions related to quiz_stack.
 */
class behat_quiz_stack extends behat_question_base {

    /**
     * Convert page names to URLs for steps like 'When I am on the "[identifier]" "[page type]" page'.
     *
     * Recognised page names are:
     * | pagetype          | name meaning | description                                      |
     * | Report            | Quiz name    | The report page (mod/quiz/report.php?mode=stack) |
     *
     * @param string $type identifies which type of page this is, e.g. 'Attempt review'.
     * @param string $identifier identifies the particular page, e.g. 'Test quiz > student > Attempt 1'.
     * @return moodle_url the corresponding URL.
     */
    protected function resolve_page_instance_url(string $type, string $identifier): moodle_url {
        switch ($type) {
            case 'Report':
                return new moodle_url('/mod/quiz/report.php',
                        ['id' => $this->get_cm_by_quiz_name($identifier)->id, 'mode' => 'stack']);

            default:
                throw new Exception('Unrecognised quiz_stack page type "' . $type . '."');
        }
    }

    /**
     * Get a quiz by name.
     *
     * @param string $name quiz name.
     * @return stdClass the corresponding DB row.
     */
    protected function get_quiz_by_name(string $name): stdClass {
        global $DB;
        return $DB->get_record('quiz', ['name' => $name], '*', MUST_EXIST);
    }

    /**
     * Get a quiz cmid from the quiz name.
     *
     * @param string $name quiz name.
     * @return stdClass cm from get_coursemodule_from_instance.
     */
    protected function get_cm_by_quiz_name(string $name): stdClass {
        $quiz = $this->get_quiz_by_name($name);
        return get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course);
    }

    /**
     * @Given /^I confirm the quiz submission for the STACK quiz report$/
     */
    public function i_confirm_the_quiz_submission_for_the_stack_quiz_report() {
        global $CFG;
        require_once($CFG->libdir . '/environmentlib.php');
        require($CFG->dirroot . '/version.php');

        $currentversion = normalize_version($release);
        if (version_compare($currentversion, '4.1', ">=")) {
            $xpath = "//div[contains(@class, 'modal-dialog')]/*/*/button[contains(@class, 'btn-primary')]";
        } else if (version_compare($currentversion, '3.9', ">=")) {
            $xpath = "//div[contains(@class, 'confirmation-dialogue')]/*/input[contains(@class, 'btn-primary')]";
        }
        $this->execute('behat_general::i_click_on', [$xpath, "xpath_element"]);
    }
}
