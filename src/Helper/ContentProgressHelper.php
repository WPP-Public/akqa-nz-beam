<?php

namespace Heyday\Beam\Helper;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class ContentProgressHelper
 * @package Heyday\Beam\Helper
 */
class ContentProgressHelper extends ProgressBar
{
    /**
     * Name of the format this helper creates and uses through static methods on ProgressBar
     */
    const FORMAT_DEFINITION = 'beam-file-progress';

    /**
     * Description of the content
     * @var string
     */
    protected $prefix = 'File: ';

    /**
     * Current terminal width in columns
     * @var int
     */
    protected $cols;

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * @inheritdoc
     */
    public function __construct(OutputInterface $output, $max = null)
    {
        parent::__construct($output, $max);

        // ProgressBar hides all of its member variables so we need to capture this
        $this->output = $output;

        // Find terminal width
        $this->cols = exec('tput cols');

        // Blank content initially (this prevents '%content%' being printed literally)
        $this->setMessage(' ', 'content');

        // Custom output format
        ProgressBar::setFormatDefinition(
            self::FORMAT_DEFINITION,
            "%content%\n" . "\033[34m[%bar%]\033[0m %current%/%max% files\n"
        );

        $this->setFormat(self::FORMAT_DEFINITION);
    }

    /**
     * Set the content to display in the progress bar (eg. current filename)
     *
     * @param string $content
     */
    public function setContent($content)
    {
        $availableCols = $this->cols - strlen($this->prefix);

        if (strlen($content) > $availableCols) {
            $paddedContent = $this->prefix . '...' . substr($content, -1 * ($availableCols - 3));
        } else {
            $paddedContent = $this->prefix . str_pad($content, $availableCols, ' ', STR_PAD_LEFT);
        }

        $this->setMessage($paddedContent, 'content');
    }

    /**
     * Set the progress bar to an appropriate size for the current terminal
     *
     * This accounts for the length of the largest number that will be displayed, plus the length of
     * other whitespace and text in the progress output (eg. " 1000/1000 files")
     *
     * @param int $max
     */
    public function setAutoWidth($max)
    {
        $maxValueText = (string) $max;
        $this->setBarWidth($this->cols - (strlen($maxValueText) * 2 + 18));
    }
}
