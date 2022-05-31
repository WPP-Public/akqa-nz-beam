<?php

namespace Heyday\Beam\Helper;

use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Class ContentProgressHelper
 * @package Heyday\Beam\Helper
 */
class ContentProgressHelper
{
    /**
     * Name of the format this helper creates and uses through static methods
     * on ProgressBar
     */
    const FORMAT_DEFINITION = 'beam-file-progress';


    public static function setupBar(ProgressBar $bar): ProgressBar
    {
        ProgressBar::setFormatDefinition(
            self::FORMAT_DEFINITION,
            "%content%\n" . "\033[34m[%bar%]\033[0m %current%/%max% files\n"
        );

        $bar->setFormat(self::FORMAT_DEFINITION);
        $bar->setMessage(' ', 'content');

        return $bar;
    }

    /**
     * Set the content to display in the progress bar (eg. current filename)
     *
     * @param ProgressBar $bar
     * @param string $content
     */
    public static function setContent(ProgressBar $bar, $content)
    {
        $prefix = 'File: ';
        $cols = exec('tput cols');
        $availableCols = $cols - strlen($prefix);

        if (strlen($content) > $availableCols) {
            $paddedContent = $prefix . '...' . substr($content, -1 * ($availableCols - 3));
        } else {
            $paddedContent = $prefix . str_pad($content, $availableCols, ' ', STR_PAD_LEFT);
        }

        $bar->setMessage($paddedContent, 'content');
    }
}
