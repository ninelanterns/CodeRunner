<?php
/** The RegexGrader class. With this grader the *expected* field of the test
 *  case being graded is a PHP-style PERL regular expression, as defined
 *  at http://www.php.net/manual/en/pcre.pattern.php, but excluding the
 *  delimiters and modifiers. The modifiers PCRE_MULTILINE and PCRE_DOTALL
 *  are always added. Internally the delimiter '/' is used but any existing
 *  slashes in the pattern are first escaped with a backslash so the user
 *  doesn't need to worry about the choice of delimiter.
 *  The grader awards full marks if and only if the output matches the
 *  expected pattern (using preg_match) with the addition of the
 *  PCRE_MULTILINE and PCRE_DOTALL modifiers. Otherwise, zero marks are awarded.
 *  Note that preg_match is actually a search function, so any substring of
 *  the output can match the pattern. If the entire output is to be matched
 *  in the normal sense of the term, the pattern should start with '^' and end
 *  with '$'.
 */

/**
 * @package    qtype
 * @subpackage coderunner
 * @copyright  Richard Lobb, 2013, The University of Canterbury
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('graderbase.php');
use qtype_coderunner\local\test_result;

class qtype_coderunner_regex_grader extends qtype_coderunner_grader {

    /** Called to grade the output generated by a student's code for
     *  a given testcase. Returns a single TestResult object.
     *  Should not be called if the execution failed (syntax error, exception
     *  etc).
     */
    function grade_known_good(&$output, &$testCase) {
        $regex = '/' . str_replace('/', '\/', $testCase->expected) . '/ms';
        $isCorrect = preg_match($regex, $output);
        $awardedMark = $isCorrect ? $testCase->mark : 0.0;
        
        if ($testCase->stdin) {
            $resultStdin = qtype_coderunner_grader::tidy($testCase->stdin);
        } else {
            $resultStdin = null;
        }

        return new test_result(
                qtype_coderunner_grader::tidy($testCase->testcode),
                $testCase->mark,
                $isCorrect,
                $awardedMark,
                qtype_coderunner_grader::snip($testCase->expected),
                qtype_coderunner_grader::snip($output),
                $resultStdin,
                qtype_coderunner_grader::tidy($testCase->extra)
        );
    }
}