<?php

namespace Heyday\Beam\Helper;

use Heyday\Beam\Exception\InvalidArgumentException;
use Heyday\Beam\Exception\RuntimeException;

/**
 * Read and update profiles in an AWS shared credentials file (~/.aws/credentials).
 */
class AwsCredentials
{
    /**
     * Write temporary session credentials into a named profile.
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public static function writeProfile(
        string $profile,
        string $accessKeyId,
        string $secretAccessKey,
        string $sessionToken,
        ?string $path = null
    ): string {
        $profile = trim($profile);
        if ($profile === '' || !preg_match('/^[A-Za-z0-9_+=,.@-]+$/', $profile)) {
            throw new InvalidArgumentException(
                'AWS profile name must be non-empty and use only letters, numbers, and _+=,.@-'
            );
        }

        if (trim($accessKeyId) === '' || trim($secretAccessKey) === '' || trim($sessionToken) === '') {
            throw new InvalidArgumentException(
                'AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, and AWS_SESSION_TOKEN are all required.'
            );
        }

        $path = $path ?? self::defaultCredentialsPath();
        $path = SshRemoteShell::expandHome($path);

        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Unable to create AWS config directory "%s".', $dir));
        }

        $profiles = self::parse($path);
        $profiles[$profile] = [
            'aws_access_key_id' => trim($accessKeyId),
            'aws_secret_access_key' => trim($secretAccessKey),
            'aws_session_token' => trim($sessionToken),
        ];

        self::write($path, $profiles);

        return $path;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function parse(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $parsed = parse_ini_file($path, true, INI_SCANNER_RAW);
        if ($parsed === false) {
            throw new RuntimeException(sprintf('Unable to parse AWS credentials file "%s".', $path));
        }

        $profiles = [];
        foreach ($parsed as $section => $values) {
            if (!is_array($values)) {
                continue;
            }
            $profiles[(string) $section] = array_map('strval', $values);
        }

        return $profiles;
    }

    /**
     * @param array<string, array<string, string>> $profiles
     */
    public static function write(string $path, array $profiles): void
    {
        $lines = [];
        foreach ($profiles as $name => $values) {
            if ($lines !== []) {
                $lines[] = '';
            }
            $lines[] = '[' . $name . ']';
            foreach ($values as $key => $value) {
                $lines[] = $key . '=' . $value;
            }
        }
        $lines[] = '';

        if (file_put_contents($path, implode("\n", $lines)) === false) {
            throw new RuntimeException(sprintf('Unable to write AWS credentials file "%s".', $path));
        }

        @chmod($path, 0600);
    }

    public static function defaultCredentialsPath(): string
    {
        return SshRemoteShell::expandHome('~/.aws/credentials');
    }
}
