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
 * This file defines the report class for STACK questions.
 *
 * @copyright  2012 the University of Birmingham
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/report/attemptsreport.php');
require_once($CFG->dirroot . '/mod/quiz/report/statistics/report.php');
require_once($CFG->dirroot . '/question/type/stack/locallib.php');


/**
 * Report subclass for the responses report to individual stack questions.
 *
 * @copyright 2012 the University of Birmingham
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_stack_report extends quiz_attempts_report {

    /** @var The quiz context. */
    protected $context;

    /** @var qubaid_condition used to select the attempts to include in SQL queries. */
    protected $qubaids;

    /** @array The names of all inputs for this question.*/
    protected $inputs;

    /** @array The names of all prts for this question.*/
    protected $prts;

    /** @array The deployed questionnotes for this question.*/
    protected $qnotes;

    /** @array The attempts at this question.*/
    protected $attempts;

    public function display($quiz, $cm, $course) {
        global $CFG, $DB, $OUTPUT;

        // Initialise the required data.
        $this->mode = 'stack';
        $this->context = context_module::instance($cm->id);

        list($currentgroup, $students, $groupstudents, $allowed) =
                $this->load_relevant_students($cm, $course);

        $this->qubaids = quiz_statistics_qubaids_condition($quiz->id, $currentgroup, $groupstudents, true);

        $questionsused = $this->get_stack_questions_used_in_attempt($this->qubaids);

        $questionid = optional_param('questionid', 0, PARAM_INT);

        // Display the appropriate page:
        $this->print_header_and_tabs($cm, $course, $quiz);
        if (!$questionsused) {
            $this->display_no_stack_questions();

        } else if (!$questionid) {
            $this->display_index($questionsused);

        } else if (array_key_exists($questionid, $questionsused)) {
            $this->display_analysis($questionsused[$questionid]);

        } else {
            $this->display_unknown_question();
        }
    }

    /**
     * Get all the STACK questions used in all the attempts at a quiz. (Note that
     * Moodle random questions may be being used.)
     * @param qubaid_condition $qubaids the attempts of interest.
     * @return array of rows from the question table.
     */
    protected function get_stack_questions_used_in_attempt(qubaid_condition $qubaids) {
        global $DB;

        return $DB->get_records_sql("
                SELECT q.*
                  FROM {question} q
                  JOIN (
                        SELECT qa.questionid, MIN(qa.slot) AS firstslot
                          FROM {$qubaids->from_question_attempts('qa')}
                         WHERE {$qubaids->where()}
                      GROUP BY qa.questionid
                       ) usedquestionids ON q.id = usedquestionids.questionid
                 WHERE q.qtype = 'stack'
              ORDER BY usedquestionids.firstslot
                ", $qubaids->from_where_params());
    }

    /**
     * Display a message saying there are no STACK questions in this quiz.
     */
    public function display_no_stack_questions() {
        global $OUTPUT;

        echo $OUTPUT->heading(get_string('nostackquestions', 'quiz_stack'));
    }

    /**
     * Display an error if the question id is unrecognised.
     */
    public function display_unknown_question() {
        print_error('questiondoesnotexist', 'question');
    }

    /**
     * Display an index page listing all the STACK questions in the quiz,
     * with a link to get a detailed analysis of each one.
     * @param array $questionsused the STACK questions used in this quiz.
     */
    public function display_index($questionsused) {
        global $OUTPUT;

        $baseurl = $this->get_base_url();
        echo $OUTPUT->heading(get_string('stackquestionsinthisquiz', 'quiz_stack'));

        echo html_writer::start_tag('ul');
        foreach ($questionsused as $question) {
            echo html_writer::tag('li', html_writer::link(
                    new moodle_url($baseurl, array('questionid' => $question->id)),
                    format_string($question->name)));
        }
        echo html_writer::end_tag('ul');
    }

    /**
     * Display analysis of a particular question in this quiz.
     * @param object $question the row from the question table for the question to analyse.
     */
    public function display_analysis($question) {
        get_question_options($question);

        $this->display_question_information($question);

        $dm = new question_engine_data_mapper();
        $this->attempts = $dm->load_attempts_at_question($question->id, $this->qubaids);

        // Setup useful internal arrays for report generation
        $this->inputs = array_keys($question->inputs);
        $this->prts = array_keys($question->prts);

        // TODO: change this to be a list of all *deployed* notes, not just those *used*.
        $qnotes = array();
        foreach ($this->attempts as $qattempt) {
            $q = $qattempt->get_question();
            $qnotes[$q->get_question_summary()] = true;
        }
        $this->qnotes = array_keys($qnotes);

        // Compute results
        list ($results, $answernote_results, $answernote_results_raw) = $this->input_report();
        list ($results_valid, $results_invalid) = $this->input_report_separate();
        // ** Display the results **

        // Overall results.
        $i=0;
        $list = '';
        $tablehead = array();
        foreach ($this->qnotes as $qnote) {
            $list .= html_writer::tag('li', stack_ouput_castext($qnote));
            $i++;
            $tablehead[] = $i;
        }
        $tablehead[] = format_string(get_string('questionreportingtotal', 'quiz_stack'));
        $tablehead = array_merge(array(''), $tablehead, $tablehead);
        echo html_writer::tag('ol', $list);

        // Complete anwernotes
        $inputstable = new html_table();
        $inputstable->head = $tablehead;
        $data = array();
        foreach ($answernote_results as $prt => $anotedata) {
            if (count($answernote_results) > 1) {
                $inputstable->data[] = array(html_writer::tag('b', $this->prts[$prt]));
            }
            $cstats = $this->column_stats($anotedata);
            foreach ($anotedata as $anote => $a) {
                $inputstable->data[] = array_merge(array($anote), $a, array(array_sum($a)), $cstats[$anote]);
            }
        }
        echo html_writer::table($inputstable);

        // Split anwernotes
        $inputstable = new html_table();
        $inputstable->head = $tablehead;
        foreach ($answernote_results_raw as $prt => $anotedata) {
            if (count($answernote_results_raw) > 1) {
                $inputstable->data[] = array(html_writer::tag('b', $this->prts[$prt]));
            }
            $cstats = $this->column_stats($anotedata);
            foreach ($anotedata as $anote => $a) {
                $inputstable->data[] = array_merge(array($anote), $a, array(array_sum($a)), $cstats[$anote]);
            }
        }
        echo html_writer::table($inputstable);

        // Results for each question note
        foreach ($this->qnotes as $qnote) {
            echo html_writer::tag('h2', get_string('variantx', 'quiz_stack').stack_ouput_castext($qnote));

            $inputstable = new html_table();
            $inputstable->attributes['class'] = 'generaltable stacktestsuite';
            $inputstable->head = array_merge(array(get_string('questionreportingsummary', 'quiz_stack'), '', get_string('questionreportingscore', 'quiz_stack')), $this->prts);
            foreach ($results[$qnote] as $dsummary => $summary) {
                foreach ($summary as $key => $res) {
                    $inputstable->data[] = array_merge(array($dsummary, $res['count'], $res['fraction']), $res['answernotes']);
                }
            }
            echo html_writer::table($inputstable);

            // Separate out inputs and look at validity.
            foreach ($this->inputs as $input) {
                $inputstable = new html_table();
                $inputstable->attributes['class'] = 'generaltable stacktestsuite';
                $inputstable->head = array($input, '', '');
                foreach ($results_valid[$qnote][$input] as $key => $res) {
                    $inputstable->data[] = array($key, $res, get_string('inputstatusnamevalid', 'qtype_stack'));
                    $inputstable->rowclasses[] = 'pass';
                }
                foreach ($results_invalid[$qnote][$input] as $key => $res) {
                    $inputstable->data[] = array($key, $res, get_string('inputstatusnameinvalid', 'qtype_stack'));
                    $inputstable->rowclasses[] = 'fail';
                }
                echo html_writer::table($inputstable);
            }

        }

    }

    /**
     * This function counts the number of response summaries per question note.
     */
    protected function input_report() {

        // $results holds the by question note analysis
        $results = array();
        foreach ($this->qnotes as $qnote) {
            $results[$qnote] = array();
        }
        // splits up the results to look for which answernotes occur most often.
        $answernote_results = array();
        $answernote_results_raw = array();
        foreach ($this->prts as $prtname => $prt) {
            $answernote_results[$prtname] = array();
            $answernote_results_raw[$prtname] = array();
        }
        $answernote_empty_row = array();
        foreach ($this->qnotes as $qnote) {
            $answernote_empty_row[$qnote] = '';
        }

        foreach ($this->attempts as $qattempt) {
            $question = $qattempt->get_question();
            $qnote = $question->get_question_summary();

            for ($i = 0; $i < $qattempt->get_num_steps(); $i++) {
                $step = $qattempt->get_step($i);
                if ($data = $this->nontrivial_response_step($qattempt, $i)) {
                    $fraction = trim((string) round($step->get_fraction(), 3));
                    $summary = $question->summarise_response($data);

                    $answernotes = array();
                    foreach ($this->prts as $prtname => $prt) {
                        $prt_object = $question->get_prt_result($prt, $data, true);
                        $raw_answernotes = $prt_object->__get('answernotes');

                        foreach ($raw_answernotes as $anote) {
                            if (!array_key_exists($anote, $answernote_results_raw[$prtname])) {
                                $answernote_results_raw[$prtname][$anote] = $answernote_empty_row;
                            }
                            $answernote_results_raw[$prtname][$anote][$qnote] += 1;
                        }

                        $answernotes[$prt] = implode(' | ', $raw_answernotes);
                        if (!array_key_exists($answernotes[$prt], $answernote_results[$prtname])) {
                            $answernote_results[$prtname][$answernotes[$prt]] = $answernote_empty_row;
                        }
                        $answernote_results[$prtname][$answernotes[$prt]][$qnote] += 1;
                    }

                    $answernote_key = implode(' # ', $answernotes);

                    if (array_key_exists($summary, $results[$qnote])) {
                        if (array_key_exists($answernote_key, $results[$qnote][$summary])) {
                            $results[$qnote][$summary][$answernote_key]['count'] += 1;
                            if ('' != $fraction) {
                                $results[$qnote][$summary][$answernote_key]['fraction'] = $fraction;
                            }
                        } else {
                            $results[$qnote][$summary][$answernote_key]['count'] = 1;
                            $results[$qnote][$summary][$answernote_key]['answernotes'] = $answernotes;
                            $results[$qnote][$summary][$answernote_key]['fraction'] = $fraction;
                        }
                    } else {
                        $results[$qnote][$summary][$answernote_key]['count'] = 1;
                        $results[$qnote][$summary][$answernote_key]['answernotes'] = $answernotes;
                        $results[$qnote][$summary][$answernote_key]['fraction'] = $fraction;
                    }
                }
            }
        }

        return array($results, $answernote_results, $answernote_results_raw);
    }

    /**
     * Counts the number of response to each input and records their validity.
     */
    protected function input_report_separate() {

        $results = array();
        $validity = array();
        foreach ($this->qnotes as $qnote) {
            foreach ($this->inputs as $input) {
                $results[$qnote][$input] = array();
            }
        }

        foreach ($this->attempts as $qattempt) {
            $question = $qattempt->get_question();
            $qnote = $question->get_question_summary();

            for ($i = 0; $i < $qattempt->get_num_steps(); $i++) {
                if ($data = $this->nontrivial_response_step($qattempt, $i)) {
                    $summary = $question->summarise_response_data($data);
                    foreach ($this->inputs as $input) {
                        if (array_key_exists($input, $summary)) {
                            if ('' != $data[$input]) {
                                if (array_key_exists($data[$input],  $results[$qnote][$input])) {
                                    $results[$qnote][$input][$data[$input]] += 1;
                                } else {
                                    $results[$qnote][$input][$data[$input]] = 1;
                                }
                            }
                            $validity[$qnote][$input][$data[$input]] = $summary[$input];
                        }
                    }
                }
            }
        }

        foreach ($this->qnotes as $qnote) {
            foreach ($this->inputs as $input) {
                arsort($results[$qnote][$input]);
            }
        }

        // Split into valid and invalid responses.
        $results_valid = array();
        $results_invalid = array();
        foreach ($this->qnotes as $qnote) {
            foreach ($this->inputs as $input) {
                $results_valid[$qnote][$input] = array();
                $results_invalid[$qnote][$input] = array();
                foreach ($results[$qnote][$input] as $key => $res) {
                    if ('valid' == $validity[$qnote][$input][$key]) {
                        $results_valid[$qnote][$input][$key] = $res;
                    } else {
                        $results_invalid[$qnote][$input][$key] = $res;
                    }
                }
            }
        }

        return array($results_valid, $results_invalid);
    }

    /**
     * From an individual attempt, we need to establish that step $i for this attempt is non-trivial, and return the non-trivial responses.
     * Otherwise we return boolean false
     */
    protected function nontrivial_response_step($qattempt, $i) {
        $any_data = false;
        $rdata = array();
        $step = $qattempt->get_step($i);
        // TODO: work out which states need to be reported..
        //if ('question_state_todo' == get_class($step->get_state())) {
            $data = $step->get_submitted_data();
            foreach ($this->inputs as $input) {
                if (array_key_exists($input, $data)) {
                    $any_data = true;
                    $rdata[$input] = $data[$input];
                }
            }
            if ($any_data) {
                return $rdata;
            }
        //}
        return false;
    }

    /*
     * This function simply prints out some useful information about the question.
     */
    private function display_question_information($question) {
        global $OUTPUT;
        $opts = $question->options;

        echo $OUTPUT->heading($question->name, 3);

        // Display the question variables.
        echo $OUTPUT->heading(stack_string('questionvariables'), 3);
        echo html_writer::start_tag('div', array('class' => 'questionvariables'));
        echo  html_writer::tag('pre', htmlspecialchars($opts->questionvariables));
        echo html_writer::end_tag('div');

        echo $OUTPUT->heading(stack_string('questiontext'), 3);
        echo html_writer::tag('div', html_writer::tag('div', stack_ouput_castext($question->questiontext),
        array('class' => 'outcome generalfeedback')), array('class' => 'que'));

        echo $OUTPUT->heading(stack_string('generalfeedback'), 3);
        echo html_writer::tag('div', html_writer::tag('div', stack_ouput_castext($question->generalfeedback),
        array('class' => 'outcome generalfeedback')), array('class' => 'que'));

        echo $OUTPUT->heading(stack_string('questionnote'), 3);
        echo html_writer::tag('div', html_writer::tag('div', stack_ouput_castext($opts->questionnote),
        array('class' => 'outcome generalfeedback')), array('class' => 'que'));

        echo $OUTPUT->heading(get_string('pluginname', 'quiz_stack'), 3);
    }

    /*
     * Take an array of numbers and create an array containing %s for each column.
     */
    private function column_stats($data) {
        $rdata = array();
        foreach ($data as $anote => $a) {
            $rdata[$anote] = array_merge(array_values($a), array(array_sum($a)));
        }
        reset($data);
        $col_total = array_fill(0, count(next($data))+1, 0);
        foreach ($rdata as $anote => $row) {
            foreach ($row as $key => $col) {
                $col_total[$key] += $col;
            }
        }
        foreach ($rdata as $anote => $row) {
            foreach ($row as $key => $col) {
                if (0 != $col_total[$key]) {
                    $rdata[$anote][$key] = round(100*$col/$col_total[$key],1);
                }
            }
        }
        return $rdata;
    }
}
