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
 * Blackboard V5 and V6 question importer.
 *
 * @package    qformat_fronter
 * @copyright  2013 Jean-Michel Vedrine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/xmlize.php');

class qformat_fronter extends qformat_based_on_xml {
    public function provide_import() {
        return true;
    }

    public function mime_type() {
        return 'application/xml';
    }

    /**
     * For now this is just a wrapper for cleaninput.
     * @param string text text to parse and recode
     * @return array with keys text, format, itemid.
     */
    public function cleaned_text_field($text) {
        return array('text' => $this->cleaninput($text), 'format' => FORMAT_HTML);
    }
    /**
     * Parse the xml document into an array of questions
     * this *could* burn memory - but it won't happen that much
     * so fingers crossed!
     * @param $lines array of lines from the input file.
     * @return array (of objects) questions objects.
     */
    public function readquestions($lines) {
        question_bank::get_qtype('multianswer'); // Ensure the multianswer code is loaded.
        $text = implode($lines, ' ');
        unset($lines);
        // This converts xml to big nasty data structure,
        // the 0 means keep white space as it is.
        try {
            $xml = xmlize($text, 0, 'UTF-8', true);
        } catch (xml_format_exception $e) {
            $this->error($e->getMessage(), '');
            return false;
        }

        $questions = array();
        // First step : we are only interested in the <item> tags.
        $rawquestions = $this->getpath($xml,
                array('questestinterop', '#', 'item'),
                array(), false);
        // Each <item> tag contains data related to a single question.
        foreach ($rawquestions as $quest) {
            // Second step : parse each question data into the intermediate
            // rawquestion structure array.
            // Warning : rawquestions are not Moodle questions.
            $question = $this->create_raw_question($quest);
            // Third step : convert a rawquestion into a Moodle question.
            switch($question->qtype) {
                case 'multiplechoice':
                    $this->process_mc($question, $questions);
                    break;
                case 'dropdownselect':
                    $this->process_select($question, $questions);
                    break;
                case 'essay':
                    $this->process_essay($question, $questions);
                    break;
                case 'description':
                    $this->process_description($question, $questions);
                    break;
                default:
                    $this->error(get_string('unknownorunhandledtype', 'question', $question->qtype));
                    break;
            }
        }
        return $questions;
    }

    /**
     * Creates a cleaner object to deal with for processing into Moodle.
     * The object returned is NOT a moodle question object.
     * @param array $quest XML <item> question  data
     * @return object rawquestion
     */
    public function create_raw_question($quest) {
        $rawquestion = new stdClass();
        $rawquestion->qtype = $this->find_item_type($quest);;
        $rawquestion->id = $this->getpath($quest,
                array('#', 'presentation', 0, '@', 'label'),
                '', true);
        $presentation = new stdClass();
        $presentation->blocks = $this->getpath($quest,
                array('#', 'presentation', 0, '#', 'flow'),
                array(), false);
        foreach ($presentation->blocks as $pblock) {
            $presentation->materials = $this->getpath($pblock,
                    array('#', 'material'),
                    array(), false);

            foreach ($presentation->materials as $material) {
                $block = new stdClass();
                $block->type = $this->getpath($material,
                        array('@', 'label'),
                        '', true);
                switch($block->type) {
                    case 'question':
                        if ($this->getpath($material,
                                array('#', 'mattext'),
                                false, false)) {
                            $block->text = $this->getpath($material,
                                    array('#', 'mattext', 0, '#'),
                                    '', true);
                        } else {
                            $this->error(get_string('missing question text', 'qformat_fronter'));
                        }
                        break;
                    case 'comment':
                        if ($this->getpath($material,
                                array('#', 'mattext'),
                                false, false)) {
                            $block->text = $this->getpath($material,
                                    array('#', 'mattext', 0, '#'),
                                    '', true);
                        }
                        break;
                }
                $rawquestion->{$block->type} = $block;
            }
            $rawquestion->choicesid = $this->getpath($pblock,
                                    array('#', 'response_lid', 0, '@', 'ident'),
                                    '', true);
            switch($rawquestion->qtype) {
                case 'multiplechoice':
                    $rawquestion->responsemax = (int)$this->getpath($pblock,
                            array('#', 'response_lid', 0, '#', 'render_choice', 0, '@', 'maxnumber'),
                            '', true);
                    $rawquestion->responsemin = (int)$this->getpath($pblock,
                            array('#', 'response_lid', 0, '#', 'render_choice', 0, '@', 'minnumber'),
                            '', true);
                    $mcchoices = $this->getpath($pblock,
                            array('#', 'response_lid', 0, '#', 'render_choice', 0, '#', 'response_label'),
                            array(), false);
                    $choices = array();
                    $this->process_choices($mcchoices, $choices);
                    $rawquestion->choices = $choices;
                    break;
                case 'dropdownselect':
                    $rawquestion->responsemin = (int)$this->getpath($pblock,
                            array('#', 'response_lid', 0, '#', 'render_slider', 0, '@', 'minnumber'),
                            '', true);
                    $rawquestion->lowerbound = (int)$this->getpath($pblock,
                            array('#', 'response_lid', 0, '#', 'render_slider', 0, '@', 'lowerbound'),
                            '', true);
                    $rawquestion->upperbound = (int)$this->getpath($pblock,
                            array('#', 'response_lid', 0, '#', 'render_slider', 0, '@', 'upperbound'),
                            '', true);
                    $mcchoices = $this->getpath($pblock,
                            array('#', 'response_lid', 0, '#', 'render_slider', 0, '#', 'response_label'),
                            array(), false);
                    $choices = array();
                    $this->process_choices($mcchoices, $choices);
                    $rawquestion->choices = $choices;
                    break;
                case 'shortanswer':
                    // TODO process answers.
                case 'essay':
                case 'description':
                    // Nothing to do here.
                    break;
                default:
                    $this->error(get_string('unknownorunhandledtype', 'question', $rawquestion->qtype));
                    break;
            }
        }
        if ($rawquestion->qtype != 'description') {
            // Determine response processing.
            $resprocessing = $this->getpath($quest,
                    array('#', 'resprocessing'),
                    array(), false);
            $respconditions = $this->getpath($resprocessing[0],
                    array('#', 'respcondition'),
                    array(), false);
            $rawquestion->maxmark = (float)$this->getpath($resprocessing[0],
                    array('#', 'outcomes', 0, '#', 'decvar', 0, '@', 'maxvalue'),
                    array(), false);
            $responses = array();
            if ($rawquestion->qtype == 'matching') {
                $this->process_matching_responses($respconditions, $responses);
            } else {
                $this->process_responses($respconditions, $responses);
            }
            $rawquestion->responses = $responses;
        }

        $feedbackset = $this->getpath($quest,
                array('#', 'itemfeedback'),
                array(), false);

        $feedbacks = array();
        $this->process_feedback($feedbackset, $feedbacks);
        $rawquestion->feedback = $feedbacks;
        return $rawquestion;
    }

    /**
     * Helper function to process an XML block into an object.
     * Can call himself recursively if necessary to parse this branch of the XML tree.
     * @param array $curblock XML block to parse
     * @return object $block parsed
     */
    public function process_block($curblock, $block) {

        // Foe now all block are of type Block.
        $curtype = 'Block';
        switch($curtype) {
            case 'Block':
                if ($this->getpath($curblock,
                        array('#', 'material', 0, '#', 'mattext'),
                        false, false)) {
                    $block->text = $this->getpath($curblock,
                            array('#', 'material', 0, '#', 'mattext', 0, '#'),
                            '', true);
                } else if ($this->getpath($curblock,
                        array('#', 'material', 0, '#', 'mat_extension', 0, '#', 'mat_formattedtext'),
                        false, false)) {
                    $block->text = $this->getpath($curblock,
                            array('#', 'material', 0, '#', 'mat_extension', 0, '#', 'mat_formattedtext', 0, '#'),
                            '', true);
                } else if ($this->getpath($curblock,
                        array('#', 'response_label'),
                        false, false)) {
                    // This is a response label block.
                    $subblocks = $this->getpath($curblock,
                            array('#', 'response_label', 0),
                            array(), false);
                    if (!isset($block->ident)) {
                        if ($this->getpath($subblocks,
                                array('@', 'ident'), '', true)) {
                            $block->ident = $this->getpath($subblocks,
                                array('@', 'ident'), '', true);
                        }
                    }
                    foreach ($this->getpath($subblocks,
                            array('#', 'flow_mat'), array(), false) as $subblock) {
                        $this->process_block($subblock, $block);
                    }
                } else {
                    if ($this->getpath($curblock,
                                array('#', 'flow_mat'), false, false)
                            || $this->getpath($curblock,
                                array('#', 'flow'), false, false)) {
                        if ($this->getpath($curblock,
                                array('#', 'flow_mat'), false, false)) {
                            $subblocks = $this->getpath($curblock,
                                    array('#', 'flow_mat'), array(), false);
                        } else if ($this->getpath($curblock,
                                array('#', 'flow'), false, false)) {
                            $subblocks = $this->getpath($curblock,
                                    array('#', 'flow'), array(), false);
                        }
                        foreach ($subblocks as $sblock) {
                            // This will recursively grab the sub blocks which should be of one of the other types.
                            $this->process_block($sblock, $block);
                        }
                    }
                }
                break;
        }
        return $block;
    }

    protected function find_item_type($data) {
        if ($this->getpath($data,
                array('#', 'resprocessing'),
                false, false)) {
            if ($this->getpath($data,
                    array('#', 'presentation', 0, '#', 'flow', 0, '#', 'response_lid', 0, '#', 'render_choice'),
                    false, false)) {
                // TODO find the yes/no questions.
                return 'multiplechoice';
            } else if ($this->getpath($data,
                    array('#', 'presentation', 0, '#', 'flow', 0, '#', 'response_lid', 0, '#', 'render_slider'),
                    false, false)) {
                return 'dropdownselect';
            } else if ($this->getpath($data,
                    array('#', 'presentation', 0, '#', 'flow', 0, '#', 'response_lid', 0, '#', 'render_fib'),
                    false, false)) {
                if ($this->getpath($data,
                        array('#', 'presentation', 0, '#', 'flow', 0, '#', 'response_lid', 0, '#', 'render_fib', 0, '#', 'rows')
                        , 0, false) == 1
                        &&  $this->getpath($data, array('#', 'itemfeedback', 0, '#'), false, false)) {
                    return 'shortanswer';
                } else {
                    return 'essay';
                }
            } else {
                return 'unknown';
            }
        } else {
            // Only description have no response processing.
            return 'description';
        }
    }
    /**
     * Preprocess XML blocks containing data for questions' choices.
     * Called by {@link create_raw_question()}
     * for matching, multichoice and fill in the blank questions.
     * @param array $bbchoices XML block to parse
     * @param array $choices array of choices suitable for a rawquestion.
     */
    protected function process_choices($bbchoices, &$choices) {
        foreach ($bbchoices as $choice) {
            $curchoice = new stdClass();
            if ($this->getpath($choice,
                    array('@', 'ident'), '', true)) {
                $curchoice->ident = $this->getpath($choice,
                        array('@', 'ident'), '', true);
            } else { // For multiple answers.
                $curchoice->ident = $this->getpath($choice,
                         array('#', 'response_label', 0), array(), false);
            }
            if ($this->getpath($choice,
                    array('#', 'flow_mat', 0), false, false)) { // For multiple answers.
                $curblock = $this->getpath($choice,
                    array('#', 'flow_mat', 0), false, false);
                // Reset $curchoice to new stdClass because process_block is expecting an object
                // for the second argument and not a string,
                // which is what is was set as originally - CT 8/7/06.

                $this->process_block($curblock, $curchoice);
            } else if ($this->getpath($choice,
                    array('#', 'response_label'), false, false)) {
                // Reset $curchoice to new stdClass because process_block is expecting an object
                // for the second argument and not a string,
                // which is what is was set as originally - CT 8/7/06.
                $this->process_block($choice, $curchoice);
            }
            $choices[] = $curchoice;
        }
    }

    /**
     * Preprocess XML blocks containing data for responses processing.
     * Called by {@link create_raw_question()}
     * for all questions types.
     * @param array $bbresponses XML block to parse
     * @param array $responses array of responses suitable for a rawquestion.
     */
    protected function process_responses($bbresponses, &$responses) {
        foreach ($bbresponses as $bbresponse) {
            $response = new stdClass();
            $response->ident = array();
            if ($this->getpath($bbresponse,
                    array('#', 'conditionvar', 0, '#', 'not'), false, false)) {
                $responseset = $this->getpath($bbresponse,
                    array('#', 'conditionvar', 0, '#', 'not'), array(), false);
                foreach ($responseset as $rs) {
                    $response->ident[] = $this->getpath($rs, array('#', 'varequal'), array(), false);
                    if (!isset($response->feedback) and $this->getpath($rs, array('@'), false, false)) {
                        $response->feedback = $this->getpath($rs,
                                array('@', 'respident'), '', true);
                    }
                }
            } else {
                $responseset = $this->getpath($bbresponse,
                    array('#', 'conditionvar'), array(), false);
                foreach ($responseset as $rs) {
                    $response->ident[] = $this->getpath($rs, array('#', 'varequal'), array(), false);
                    if (!isset($response->feedback) and $this->getpath($rs, array('@'), false, false)) {
                        $response->feedback = $this->getpath($rs,
                                array('@', 'respident'), '', true);
                    }
                }

            }

            // Determine what mark to give to response.
            if ($this->getpath($bbresponse,
                    array('#', 'setvar', 0, '#'), false, false)) {
                $response->mark = (float)$this->getpath($bbresponse,
                        array('#', 'setvar', 0, '#'), '', true);
                if ($response->mark > 0.0) {
                    $response->title = 'correct';
                } else {
                    $response->title = 'incorrect';
                }
            } else {
                // Just going to assume this is the case, this is probably not correct.
                $response->mark = 0;
                $response->title = 'incorrect';
            }

            $responses[] = $response;
        }
    }

    /**
     * Preprocess XML blocks containing data for responses feedbacks.
     * Called by {@link create_raw_question()}
     * for all questions types.
     * @param array $feedbackset XML block to parse
     * @param array $feedbacks array of feedbacks suitable for a rawquestion.
     */
    public function process_feedback($feedbackset, &$feedbacks) {
        foreach ($feedbackset as $rawfeedback) {
            $feedback = new stdClass();
            $feedback->ident = $this->getpath($rawfeedback,
                    array('@', 'ident'), '', true);
            $feedback->text = '';
            if ($this->getpath($rawfeedback,
                    array('#', 'flow_mat', 0), false, false)) {
                $this->process_block($this->getpath($rawfeedback,
                        array('#', 'flow_mat', 0), false, false), $feedback);
            } else if ($this->getpath($rawfeedback,
                    array('#', 'solution', 0, '#', 'solutionmaterial', 0, '#', 'flow_mat', 0), false, false)) {
                $this->process_block($this->getpath($rawfeedback,
                        array('#', 'solution', 0, '#', 'solutionmaterial', 0, '#', 'flow_mat', 0), false, false), $feedback);
            }

            $feedbacks[$feedback->ident] = $feedback;
        }
    }

    /**
     * Create common parts of question
     * @param object $quest rawquestion
     * @return object Moodle question.
     */
    public function process_common($quest) {
        $question = $this->defaultquestion();
        $text = $quest->question->text;
        $question->name = $this->create_default_question_name($text,
                get_string('defaultname', 'qformat_fronter' , $quest->id));

        if (isset($quest->comment) && $quest->comment != '') {
            $text .= ' '. $quest->comment->text;
        }
        $questiontext = $this->cleaned_text_field($text);
        $question->questiontext = $questiontext['text'];
        $question->questiontextformat = $questiontext['format']; // Needed because add_blank_combined_feedback uses it.

        $question->generalfeedback = '';
        $question->generalfeedbackformat = FORMAT_HTML;

        return $question;
    }

    /**
     * Process Multichoice Questions
     * Parse a multichoice single answer rawquestion and add the result
     * to the array of questions already parsed.
     * @param object $quest rawquestion
     * @param $questions array of Moodle questions already done.
     */
    protected function process_mc($quest, &$questions) {
        $gradeoptionsfull = question_bank::fraction_options_full();
        $question = $this->process_common($quest);
        $question->qtype = 'multichoice';
        $question = $this->add_blank_combined_feedback($question);
        if (isset($quest->responsemax) && $quest->responsemax > 1) {
            $question->single = 0;
        } else {
            $question->single = 1;
        }

        $answers = $quest->responses;

        $correctanswers = array();
        foreach ($answers as $answer) {
            if ($answer->title == 'correct') {
                $answerset = $answer->ident[0];
                foreach ($answerset as $ans) {
                    $correctanswers[$ans['#']] = $answer->mark;
                }
            }
        }

        $feedback = new stdClass();
        foreach ($quest->feedback as $fb) {
            $feedback->{$fb->ident} = trim($fb->text);
        }

        $correctanswersum = array_sum($correctanswers);
        $i = 0;
        foreach ($quest->choices as $choice) {
            $question->answer[$i] = $this->cleaned_text_field(trim($choice->text));
            if (array_key_exists($choice->ident, $correctanswers)) {
                // Correct answer.
                $question->fraction[$i] =
                        match_grade_options($gradeoptionsfull, $correctanswers[$choice->ident] / $correctanswersum, 'nearest');
                $question->feedback[$i] = $this->cleaned_text_field('');
            } else {
                // Wrong answer.
                $question->fraction[$i] = 0;
                $question->feedback[$i] = $this->cleaned_text_field('');
            }
            $i++;
        }
        $questions[] = $question;
    }

    /**
     * Process Drop Down Select menu Questions
     * Parse a Drop Down Select menu rawquestion and add the result
     * to the array of questions already parsed.
     * @param object $quest rawquestion
     * @param $questions array of Moodle questions already done.
     */
    protected function process_select($quest, &$questions) {
        // By default these questions will be imported as multianswer questions.
        // TODO if OU gapselect question type is installed import as gapselect questions.
        $text = $quest->question->text;
        if (isset($quest->comment) && $quest->comment != '') {
            $text .= ' '. $quest->comment->text;
        }
        $questiontext = $this->cleaninput($text);

        $answers = $quest->responses;

        $max = 0;
        foreach ($answers as $answer) {
            if ($answer->mark > $max) {
                $max = $answer->mark;
            }
            $answerset = $answer->ident[0];
            foreach ($answerset as $ans) {
                $answermark[$ans['#']] = $answer->mark;
            }
        }

        $questiontext .= '<p>{1:MULTICHOICE:';
        foreach ($quest->choices as $choice) {
            $percentage = round($answermark[$choice->ident] / $max * 100);
            $choicetext = $this->cleaninput($choice->text);
            $questiontext .= '~%' . $percentage . '%' . $choicetext;
        }
        $questiontext .= '}</p>';
        $question = qtype_multianswer_extract_question(array('text' => $questiontext, 'format' => FORMAT_HTML, 'itemid' => ''));
        $question->questiontext = $question->questiontext['text'];
        $question->name = $this->create_default_question_name($question->questiontext,
                get_string('defaultname', 'qformat_fronter' , $quest->id));
        $question->questiontextformat = FORMAT_HTML;
        $question->course = $this->course;
        $question->generalfeedback = '';
        $question->generalfeedbackformat = FORMAT_HTML;
        $question->qtype = 'multianswer';
        $question->length = 1;
        $question->penalty = 0.3333333;
        $questions[] = $question;
    }

    /**
     * Process Essay Questions
     * Parse an essay rawquestion and add the result
     * to the array of questions already parsed.
     * @param object $quest rawquestion
     * @param $questions array of Moodle questions already done.
     */
    public function process_essay($quest, &$questions) {

        $question = $this->process_common($quest);
        $question->qtype = 'essay';

        $question->fraction[] = 1;
        $question->defaultmark = 1;
        $question->responseformat = 'editor';
        $question->responsefieldlines = 15;
        $question->attachments = 0;
        $question->graderinfo = $this->text_field('');
        $question->responsetemplate = $this->text_field('');
        $question->feedback = '';
        $question->responserequired = 1;
        $question->attachmentsrequired = 0;
        $question->fraction = 0;

        $questions[] = $question;
    }

    /**
     * Process description Questions
     * Parse a description rawquestion and add the result
     * to the array of questions already parsed.
     * @param object $quest rawquestion
     * @param $questions array of Moodle questions already done.
     */
    public function process_description($quest, &$questions) {

        $question = $this->process_common($quest);
        $question->qtype = 'description';
        $question->defaultmark = 0;
        $question->length = 0;
        $questions[] = $question;
    }
}
