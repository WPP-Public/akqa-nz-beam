<?php

namespace Heyday\Beam\Helper;

use Heyday\Beam\Exception\InvalidArgumentException;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Line-based interactive prompts that avoid Symfony QuestionHelper autocomplete
 * (stream_select + stty), which breaks Ctrl+C on macOS/Cursor terminals.
 */
class InteractivePrompt
{
    public const CANCEL_LABEL = '(cancel)';

    private static bool $cancelHandlerInstalled = false;

    /**
     * Restore terminal settings after stty changes or interrupted reads.
     */
    public static function restoreTerminal(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            return;
        }

        @shell_exec('stty echo icanon 2>/dev/null');
    }

    /**
     * Exit cleanly on Ctrl+C after restoring the terminal.
     */
    public static function enableCancelHandler(callable $onCancel): void
    {
        if (self::$cancelHandlerInstalled || !\function_exists('pcntl_signal')) {
            return;
        }

        if (\function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        pcntl_signal(\SIGINT, function () use ($onCancel): void {
            self::restoreTerminal();
            echo "\n";
            $onCancel();
            exit(1);
        });

        self::$cancelHandlerInstalled = true;
    }

    /**
     * @param list<string> $choices
     * @return string|null Selected choice, or null when cancelled
     */
    public static function choose(
        OutputInterface $output,
        FormatterHelper $formatter,
        string $prompt,
        array $choices
    ): ?string {
        $choicesWithCancel = [...$choices, self::CANCEL_LABEL];

        $output->writeln($formatter->formatSection('prompt', $prompt, 'comment'));
        foreach ($choicesWithCancel as $index => $choice) {
            $output->writeln(sprintf('  [%d] %s', $index, $choice));
        }

        while (true) {
            $output->write('> ');
            $line = self::readLine();

            if ($line === null) {
                return null;
            }

            try {
                $selected = self::parseChoice($line, $choicesWithCancel);
            } catch (InvalidArgumentException $e) {
                $output->writeln($formatter->formatSection('error', $e->getMessage(), 'error'));
                continue;
            }

            if ($selected === self::CANCEL_LABEL) {
                return null;
            }

            return $selected;
        }
    }

    /**
     * Read a single line from stdin.
     *
     * @return string|null Null when stdin closes (Ctrl+D)
     */
    public static function readLine(bool $hidden = false): ?string
    {
        $stdin = \STDIN;
        $sttyState = null;

        if (\DIRECTORY_SEPARATOR !== '\\' && @stream_isatty($stdin)) {
            $sttyState = shell_exec('stty -g');
            shell_exec($hidden ? 'stty -echo icanon' : 'stty echo icanon');
        }

        try {
            $line = self::readLineFromStream($stdin);
        } finally {
            if ($sttyState !== null && $sttyState !== false) {
                shell_exec('stty ' . $sttyState);
            }
            self::restoreTerminal();
        }

        if ($line === false) {
            return null;
        }

        return trim($line, "\r\n \t");
    }

    /**
     * Read until end-of-line, accepting both LF and CR terminators.
     *
     * fgets() only stops on LF; macOS/Cursor often send CR alone after stty
     * changes, which leaves long pasted values (e.g. AWS session tokens) hanging.
     *
     * @param resource $handle
     * @return string|false
     */
    public static function readLineFromStream($handle): string|false
    {
        $line = '';

        while (!feof($handle)) {
            $char = fgetc($handle);
            if ($char === false) {
                return $line === '' ? false : $line;
            }

            // Skip a leading LF left over from a previous CRLF terminator.
            if ($char === "\n" && $line === '') {
                continue;
            }

            if ($char === "\n" || $char === "\r") {
                return $line;
            }

            $line .= $char;
        }

        return $line === '' ? false : $line;
    }

    /**
     * @param list<string> $choices
     * @throws InvalidArgumentException
     */
    public static function parseChoice(string $line, array $choices): string
    {
        $line = trim($line);

        if ($line === '') {
            throw new InvalidArgumentException('Enter a number or name from the list.');
        }

        if (strcasecmp($line, self::CANCEL_LABEL) === 0 || strcasecmp($line, 'cancel') === 0) {
            return self::CANCEL_LABEL;
        }

        if (ctype_digit($line)) {
            $index = (int) $line;
            if (!isset($choices[$index])) {
                throw new InvalidArgumentException(sprintf('Invalid choice "%s".', $line));
            }

            return $choices[$index];
        }

        foreach ($choices as $choice) {
            if (strcasecmp($line, $choice) === 0) {
                return $choice;
            }
        }

        throw new InvalidArgumentException(sprintf('Invalid choice "%s".', $line));
    }
}
