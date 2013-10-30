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
 * coderunner question definition classes.
 *
 * @package    qtype
 * @subpackage coderunner
 * @copyright  Richard Lobb, 2011, The University of Canterbury
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

// TODO -- fix up the horrible hack defining MAX_OUTPUT_LENGTH and FUNC_MIN_LENGTH
// (to avoid any conflict with the legacy prgcode module).

/** Max size of output to be stored in question_attempt_step_data table
 *  (which is of type text so limited to 64k).
 */
defined('MAX_OUTPUT_LENGTH') || define('MAX_OUTPUT_LENGTH', 60000);

defined('FUNC_MIN_LENGTH') ||  define('FUNC_MIN_LENGTH', 1);  /* Minimum no. of bytes for a valid bit of code */

require_once($CFG->dirroot . '/question/behaviour/adaptive/behaviour.php');
require_once($CFG->dirroot . '/question/engine/questionattemptstep.php');
require_once($CFG->dirroot . '/question/behaviour/adaptive_adapted_for_coderunner/behaviour.php');
require_once($CFG->dirroot . '/local/Twig/Autoloader.php');
require_once('Grader/graderbase.php');
require_once('testingoutcome.php');

/**
 * Represents a Python 'coderunner' question.
 */
class qtype_coderunner_question extends question_graded_automatically {

    public  $testcases;    // Array of testcases
    private $has_custom_grader = FALSE;  // True IFF we have a custom grader
    private $graderInstance = NULL;      // The grader instance, if it's NOT a custom one
    private $twig = NULL;                // The template processor environment
    private $sandboxInstance = NULL;     // The sandbox we're using
    private $allRuns = NULL;             // Array of the source code for all runs
    private $allGradings = NULL;         // Array of the source code of all grading runs


    /**
     * Override default behaviour so that we can use a specialised behaviour
     * that caches test results returned by the call to grade_response().
     *
     * @param question_attempt $qa the attempt we are creating an behaviour for.
     * @param string $preferredbehaviour the requested type of behaviour.
     * @return question_behaviour the new behaviour object.
     */
    public function make_behaviour(question_attempt $qa, $preferredbehaviour) {
        // TODO: see if there's some way or issuing a warning message when
        // coderunner questions aren't being used in an adaptive mode.

        if ($preferredbehaviour == 'adaptive') {
            return  new qbehaviour_adaptive_adapted_for_coderunner($qa, $preferredbehaviour);
        }
        else {
            return parent::make_behaviour($qa, $preferredbehaviour);
        }
    }



    public function get_expected_data() {
        return array('answer' => PARAM_RAW, 'rating' => PARAM_INT);
    }


    public function summarise_response(array $response) {
        if (isset($response['answer'])) {
            return $response['answer'];
        } else {
            return null;
        }
    }

    public function is_gradable_response(array $response) {
        return array_key_exists('answer', $response) &&
                !empty($response['answer']) &&
                strlen($response['answer']) > FUNC_MIN_LENGTH;
    }

    public function is_complete_response(array $response) {
        return $this->is_gradable_response($response);
    }


    /**
     * In situations where is_gradable_response() returns false, this method
     * should generate a description of what the problem is.
     * @return string the message.
     */
    public function get_validation_error(array $response) {
        return get_string('answerrequired', 'qtype_coderunner');
    }


    /** This function is used by the question engine to prevent regrading of
     *  unchanged submissions.
     *
     * @param array $prevresponse
     * @param array $newresponse
     * @return boolean
     */
    public function is_same_response(array $prevresponse, array $newresponse) {
        if (!question_utils::arrays_same_at_key_missing_is_blank(
                $prevresponse, $newresponse, 'answer')
            || !question_utils::arrays_same_at_key_integer(
                $prevresponse, $newresponse, 'rating')) {
            return false;
        }
        return true;
    }



    public function get_correct_response() {
        return $this->get_correct_answer();
    }


    public function get_correct_answer() {
        // Allow for the possibility in the future of providing a sample answer
        return isset($this->answer) ? array('answer' => $this->answer) : array();
    }


    // Grade the given 'response'.
    // This implementation assumes a modified behaviour that will accept a
    // third array element in its response, containing data to be cached and
    // served up again in the response on subsequent calls.


    public function grade_response(array $response) {
        if (empty($response['_testoutcome'])) {
            $code = $response['answer'];
            $testOutcome = $this->run_tests($code, $this->testcases);
            $testOutcomeSerial = serialize($testOutcome);
        }
        else {
            $testOutcomeSerial = $response['_testoutcome'];
            $testOutcome = unserialize($testOutcomeSerial);
        }

        $dataToCache = array('_testoutcome' => $testOutcomeSerial);
        if ($testOutcome->allCorrect()) {
             return array(1, question_state::$gradedright, $dataToCache);
        }
        elseif ($this->all_or_nothing) {
            return array(0, question_state::$gradedwrong, $dataToCache);
        }
        else {
            return array($testOutcome->markAsFraction(), question_state::$gradedpartial, $dataToCache);
        }
    }


    // Check the correctness of a student's code given the student's
    // response (i.e. "answer") and and a set of testCases.
    // Returns a TestingOutcome object.

    protected function run_tests($code, $testCases) {
        global $CFG;

        Twig_Autoloader::register();
        $loader = new Twig_Loader_String();
        $this->twig = new Twig_Environment($loader, array(
            'debug' => true,
            'autoescape' => false,
            'strict_variables' => true,
            'optimizations' => 0
        ));

        $this->setUpSandbox();
        $this->setUpGrader();

        $templateParams = array(
            'STUDENT_ANSWER' => $code,
            'ESCAPED_STUDENT_ANSWER' => str_replace('"', '\"', str_replace('\\', '\\\\', $code)),
            'MATLAB_ESCAPED_STUDENT_ANSWER' => str_replace(
                array("'",  "\n", "\r", '%'),
                array("''", '\\n',  '',  '%%'),
                str_replace('\\n', '\\\\n', $code)));
         $this->allRuns = array();
         $this->allGradings = array();
         $outcome = $this->runWithCombinator($testCases, $templateParams);

        // If that failed for any reason (e.g. no combinator template or timeout
        // of signal) run the tests individually. Any compilation
        // errors or abnormal terminations (not including signals) in individual
        // tests bomb the whole test process, but otherwise we should finish
        // with a TestingOutcome object containing a test result for each test
        // case.
        if ($outcome == NULL) {
            $outcome = $this->runTestsSingly($testCases, $templateParams);
        }

        $this->sandboxInstance->close();
        if ($this->show_source) {
            $outcome->sourceCodeList = $this->allRuns;
            $outcome->graderCodeList = $this->allGradings;
        }
    	return $outcome;
    }


    /** Set the test cases for this question. Called by questiontype.
     *  Ummm. Well actually no. It isn't used as at the time the questiontype
     *  is trying to set testcases it doesn't have a real question object
     *  so it sets the testcases field by direct assignment :-(
     *  I'm leaving this code in place for documentation purposes, however.
     *
     * @param type $testcases The set of testcases, each consisting of
     * all the fields contained in the quest_coderunner_testcases database table
     * (q.v.) and including the testcode to run for the test, the stdin to
     * use and the expected output.
     */
    public function setTestcases($testcases) {
        $this->testcases = $testcases;
    }


    // Try running with the combinator template, which combines all tests into
    // a single sandbox run.
    // Only do this if there are no stdins and it's not a customised question.
    // Special template parameters are STUDENT_ANSWER, the raw submitted code,
    // ESCAPED_STUDENT_ANSWER, the submitted code with all double quote chars escaped
    // (for use in a Python statement like s = """{{ESCAPED_STUDENT_ANSWER}}""" and
    // MATLAB_ESCAPED_STUDENT_ANSWER, a string for use in Matlab intended
    // to be used as s = sprintf('{{MATLAB_ESCAPED_STUDENT_ANSWER}}')
    // Return true if successful.
    private function runWithCombinator($testCases, $templateParams) {
        $outcome = NULL;
        if (!$this->customise && $this->combinator_template && $this->noStdins($testCases)) {

            assert($this->test_splitter_re != '');
            $templateParams['TESTCASES'] = $testCases;
            $testProg = $this->twig->render($this->combinator_template, $templateParams);

            $this->allRuns[] = $testProg;
            $run = $this->sandboxInstance->execute($testProg, $this->language, NULL);

            if ($run->result === SANDBOX::RESULT_COMPILATION_ERROR) {
                $outcome = new TestingOutcome(TestingOutcome::STATUS_SYNTAX_ERROR, $run->cmpinfo);
            }
            else if ($run->result === SANDBOX::RESULT_ABNORMAL_TERMINATION) {
                // Could be a syntax error but might be a runtime error on just one test case so abandon combinator approach
            }
            // Cater for very rare situation that a successful run is recorded
            // but stderr output is generated as well. [e.g.
            // SyntaxWarning from misuse of a global declaration.] Can't split
            // stdout in this case so give up on the combinator template.
            else if ($run->result === Sandbox::RESULT_SUCCESS && !$run->stderr) {
                $outputs = preg_split($this->test_splitter_re, $run->output);
                if (count($outputs) == count($testCases)) {
                    //debugging("Good split");
                    $outcome = new TestingOutcome();
                    $i = 0;
                    foreach ($testCases as $testCase) {
                        $outcome->addTestResult($this->grade($outputs[$i], $testCase));
                        $i++;
                    }
                }
                else {
                    // debugging("Bad split");
                }
            }
            else {
                // Could be any of the other failure modes, e.g. runtime error.
                // Abandon combinator approach
                // debugging('Unsuccessful run: ' . print_r($run->result, TRUE));
            }
        }
        return $outcome;
    }


    // Run all tests one-by-one on the sandbox
    private function runTestsSingly($testCases, $templateParams) {
        $template = $this->customise ? $this->custom_template : $this->per_test_template;
        $outcome = new TestingOutcome();
        foreach ($testCases as $testCase) {
            $templateParams['TEST'] = $testCase;
            $testProg = $this->twig->render($template, $templateParams);
            $input = isset($testCase->stdin) ? $testCase->stdin : '';
            $this->allRuns[] = $testProg;
            $run = $this->sandboxInstance->execute($testProg, $this->language, $input);
            if ($run->result === SANDBOX::RESULT_COMPILATION_ERROR) {
                $outcome = new TestingOutcome(TestingOutcome::STATUS_SYNTAX_ERROR, $run->cmpinfo);
                break;
            } else if ($run->result != Sandbox::RESULT_SUCCESS) {
                $errorMessage = $this->makeErrorMessage($run);
                $isError = TRUE;
                $outcome->addTestResult($this->grade($errorMessage, $testCase, $isError));
                break;
            } else {
                // Very rarely Python will generate stderr output AND
                // valid stdout output, so must merge them.
                $output = $run->stderr ? $run->stderr + '\n' + $run->output : $run->output;
                $outcome->addTestResult($this->grade($output, $testCase));
            }
        }
        return $outcome;
    }


    // Set up the $this->has_custom_grader field and, if that's false, the
    // $this->grader field.
    private function setUpGrader() {
        global $CFG;
        if (!isset($this->custom_grader) || trim($this->custom_grader) === '') {
            $graderClass = $this->grader;
            $graderClassLC = strtolower($graderClass);
            require_once($CFG->dirroot . "/question/type/coderunner/Grader/$graderClassLC.php");
            $this->graderInstance = new $graderClass();
            $this->has_custom_grader = False;
        } else {
            $this->has_custom_grader = True;
        }
    }


    // Set $this->sandboxInstance
    private function setUpSandbox() {
        global $CFG;
        $sandboxClass = $this->sandbox;
        $sandboxClassLC = strtolower($sandboxClass);
        require_once($CFG->dirroot . "/question/type/coderunner/Sandbox/$sandboxClassLC.php");
        $this->sandboxInstance = new $sandboxClass();
    }


    // Grade a given test result using either the custom grader for this
    // question, if it's defined, or the default grader for the question type
    // otherwise.
    private function grade($output, $testcase, $isBad = FALSE) {
        if (!$this->has_custom_grader) {
            return $this->graderInstance->grade($output, $testcase);
        }
        else {
            $testcase->got = $output;
            $templateParams = array('TEST' => $testcase);
            $cleanedStudentOutput = Grader::clean($output);
            $cleanedExpected = Grader::clean($testcase->output);
            $testProg = $this->twig->render($this->custom_grader, $templateParams);
            $this->allGradings[] = $testProg;
            $graderRun = $this->sandboxInstance->execute($testProg, $this->language, '');
            if ($graderRun->result != Sandbox::RESULT_SUCCESS) {
                $errorMessage = $this->makeErrorMessage($graderRun);

            } else if (!preg_match('#[0-9]+(\.[0-9]+)?#', $graderRun->output)) {
                $errorMessage = "Bad grader response:'" . $graderRun->output . "'";
            }

            if (isset($errorMessage)) {
                $outcome = new TestResult(
                        $testcase->mark,
                        FALSE,
                        0.0,
                        $cleanedExpected,
                        $errorMessage
                );
            } else {
                $fractionalGrade = floatval($graderRun->output);
                $isCorrect = abs($fractionalGrade - 1.0) < 0.000001;
                $awardedMark = $fractionalGrade * $testcase->mark;
                $outcome = new TestResult(
                    $testcase->mark,
                    $isCorrect,
                    $awardedMark,
                    Grader::snip($cleanedExpected),
                    Grader::snip($cleanedStudentOutput)
                );

            }
            return $outcome;
        }
    }


    // Return a $sep-separated string of the non-empty elements
    // of the array $strings. Similar to implode except empty strings
    // are ignored
    private function merge($sep, $strings) {
        $s = '';
        foreach($strings as $el) {
            if ($el) {
                if ($s !== '') {
                    $s .= $sep;
                }
                $s .= $el;
            }
        }
        return $s;
    }


    private function makeErrorMessage($run) {
        $err = "***" . Sandbox::resultString($run->result) . "***";
        if ($run->result === Sandbox::RESULT_RUNTIME_ERROR) {
            $sig = $run->signal;
            $err .= " (signal $sig)";
        }
        return $this->merge("\n", array($run->cmpinfo, $run->stderr, $run->output, $err));
    }


    /** True IFF no testcases have nonempty stdin. */
    private function noStdins($testCases) {
        foreach ($testCases as $testCase) {
            if ($testCase->stdin != '') {
                return FALSE;
            }
        }
        return TRUE;
    }


    // Count the number of errors in the given array of test results.
    // TODO -- figure out how to eliminate either this one or the identical
    // version in renderer.php.
    private function count_errors($testResults) {
        $errors = 0;
        foreach ($testResults as $tr) {
            if (!$tr->isCorrect) {
                $errors++;
            }
        }
        return $errors;
    }
}

