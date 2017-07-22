<?php

namespace Heyday\Beam\Helper;
use Symfony\Component\Console\Question\Question;

/**
 * Confirmation yes/no question
 *
 * The question will be asked until the user answers with nothing, yes, or no.
 * This emulates the original prompt behaviour in Beam, which always returns a boolean answer to callers.
 *
 * This replaces and mimics DialogHelper::askConfirmation() which was deprecated in Symfony Console 2.5
 */
class YesNoQuestion extends Question
{
    /**
     * @param string $question
     * @param bool $default - whether the answer should be true (y) or false (n) if there is no input
     */
    public function __construct($question, $default = true)
    {
        // Force default value to be a value we can work with for a yes/no question
        $default = $this->convertStringToBool($default);

        if (!is_bool($default)) {
            throw new \InvalidArgumentException('Value should be a string starting with "y" or "n", or a boolean');
        }

        parent::__construct($question, $default);

        // Force a 'yes', 'no' or boolean response. QuestionHelper converts blank responses to $default for us
        $this->setValidator(function($answer) {
            $answer = $this->convertStringToBool($answer);

            if (!is_bool($answer)) {
                // This exception tells QuestionHelper that validation failed. The message is shown to the user
                throw new \InvalidArgumentException('Please answer "yes" or "no".');
            }

            // Even though this is a "validator", the value returned here is returned as the answer
            return $answer;
        });
    }

    /**
     * Convert a yes/no string to a boolean if it can be done
     *
     * The input value is passed through unchanged if it cannot be converted. This allows the validator to This is required to allow our validator to see
     * the actual value, since normalise is always called before the validator by QuestionHelper.
     *
     * @param string|mixed $value
     * @return bool|mixed
     */
    protected function convertStringToBool($value)
    {
        if (is_string($value)) {
            switch (strtolower($value)[0]) {
                case 'y':
                    return true;
                case 'n':
                    return false;
            }
        }

        return $value;
    }
}
