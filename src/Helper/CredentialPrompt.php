<?php

namespace Heyday\Beam\Helper;

use Heyday\Beam\Exception\InvalidArgumentException;
use Heyday\Beam\Exception\RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Collect AWS portal credentials via a temp file and editor, avoiding PHP stdin
 * issues with long session tokens in IDE terminals.
 */
class CredentialPrompt
{
    private const TEMPLATE = <<<'TXT'
# Paste AWS access portal credentials from the browser.
# Set each value after the = sign, save, then close this file.
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_SESSION_TOKEN=
TXT;

    /**
     * @return array{accessKeyId: string, secretAccessKey: string, sessionToken: string}
     */
    public static function collectViaEditor(?string $editor = null): array
    {
        $path = tempnam(sys_get_temp_dir(), 'beam-aws-');
        if ($path === false) {
            throw new RuntimeException('Unable to create a temporary credentials file.');
        }

        file_put_contents($path, self::TEMPLATE);
        @chmod($path, 0600);

        try {
            self::openInEditor($path, $editor);

            return self::parseCredentialsFile($path);
        } finally {
            self::wipeFile($path);
        }
    }

    /**
     * @return array{accessKeyId: string, secretAccessKey: string, sessionToken: string}
     */
    public static function parseCredentialsFile(string $path): array
    {
        if (!is_readable($path)) {
            throw new InvalidArgumentException(sprintf('Credentials file "%s" is not readable.', $path));
        }

        $map = [
            'AWS_ACCESS_KEY_ID' => 'accessKeyId',
            'AWS_SECRET_ACCESS_KEY' => 'secretAccessKey',
            'AWS_SESSION_TOKEN' => 'sessionToken',
        ];
        $values = array_fill_keys(array_values($map), '');

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\"'");

            if (isset($map[$key])) {
                $values[$map[$key]] = $value;
            }
        }

        foreach ($map as $envKey => $field) {
            if ($values[$field] === '') {
                throw new InvalidArgumentException(sprintf('Missing %s in credentials file.', $envKey));
            }
        }

        return $values;
    }

    public static function resolveEditor(?string $editor = null): string
    {
        if ($editor !== null && trim($editor) !== '') {
            return trim($editor);
        }

        foreach (['BEAM_EDITOR', 'EDITOR', 'VISUAL'] as $variable) {
            $value = getenv($variable);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return PHP_OS_FAMILY === 'Windows' ? 'notepad' : 'nano';
    }

    private static function openInEditor(string $path, ?string $editor): void
    {
        $editor = self::resolveEditor($editor);

        if (PHP_OS_FAMILY === 'Darwin' && $editor === 'nano' && !getenv('EDITOR') && !getenv('VISUAL') && !getenv('BEAM_EDITOR')) {
            self::runEditorProcess(sprintf('open -e -W %s', escapeshellarg($path)), true);
            return;
        }

        self::runEditorProcess(sprintf('%s %s', $editor, escapeshellarg($path)), true);
    }

    private static function runEditorProcess(string $command, bool $preferTty): void
    {
        $process = Process::fromShellCommandline($command);
        $process->setTimeout(null);

        if ($preferTty && Process::isTtySupported()) {
            $process->setTty(true);
        }

        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                'Editor exited with an error. Command: %s',
                $command
            ));
        }
    }

    private static function wipeFile(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $size = filesize($path);
        if ($size !== false && $size > 0) {
            file_put_contents($path, str_repeat("\0", $size));
        }

        @unlink($path);
    }
}
