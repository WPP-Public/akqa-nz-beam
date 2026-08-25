<?php

namespace Heyday\Beam\Helper;

use Heyday\Beam\Exception\InvalidArgumentException;

/**
 * Builds the SSH remote-shell command used by rsync (RSYNC_RSH) and target commands.
 */
class SshRemoteShell
{
    private const SSM_DOCUMENT = 'AWS-StartSSHSession';

    /**
     * Build the SSH command (and optional wrappers/options) for a server config.
     *
     * @param array $server Processed server configuration
     * @throws InvalidArgumentException
     */
    public static function build(array $server): string
    {
        $ssh = !empty($server['sshpass']) ? 'sshpass -e ssh' : 'ssh';

        if (!empty($server['ssm']['enabled'])) {
            if (!empty($server['sshpass'])) {
                throw new InvalidArgumentException(
                    'The "ssm" and "sshpass" options cannot be used together on the same server.'
                );
            }

            $ssh .= ' -o ProxyCommand=' . escapeshellarg(self::buildSsmProxyCommand($server['ssm']));
        }

        // An SSM host is an instance ID, so `Host`/`HostName` blocks in ~/.ssh/config no longer
        // match and the identity has to come from the beam config instead.
        if (!empty($server['identityFile'])) {
            $identityFile = self::resolveIdentityFile($server['identityFile']);

            // IdentitiesOnly stops ssh-agent keys being offered ahead of this one, which
            // otherwise trips MaxAuthTries before the configured key is ever tried.
            $ssh .= ' -i ' . escapeshellarg($identityFile) . ' -o IdentitiesOnly=yes';
        }

        foreach ($server['sshOptions'] ?? [] as $option) {
            self::assertSafeSshOption($option);
            $ssh .= ' -o ' . escapeshellarg($option);
        }

        return $ssh;
    }

    /**
     * Build the AWS SSM ProxyCommand value for OpenSSH.
     *
     * @param array $ssm Processed ssm configuration node
     * @throws InvalidArgumentException
     */
    public static function buildSsmProxyCommand(array $ssm): string
    {
        $command = sprintf(
            'aws ssm start-session --target %%h --document-name %s --parameters portNumber=%%p',
            self::SSM_DOCUMENT
        );

        if (!empty($ssm['region'])) {
            self::assertSafeAwsToken($ssm['region'], 'region');
            $command .= ' --region ' . $ssm['region'];
        }

        if (!empty($ssm['profile'])) {
            self::assertSafeAwsToken($ssm['profile'], 'profile');
            $command .= ' --profile ' . $ssm['profile'];
        }

        return $command;
    }

    /**
     * Return whether a processed server config uses SSM tunneling.
     */
    public static function isEnabled(array $server): bool
    {
        return !empty($server['ssm']['enabled']);
    }

    /**
     * Expand a configured identity file path and check it is readable.
     *
     * @throws InvalidArgumentException
     */
    public static function resolveIdentityFile(string $path): string
    {
        $path = self::expandHome($path);

        if (!is_file($path)) {
            throw new InvalidArgumentException(sprintf(
                'The configured identityFile "%s" does not exist.',
                $path
            ));
        }

        if (!is_readable($path)) {
            throw new InvalidArgumentException(sprintf(
                'The configured identityFile "%s" is not readable.',
                $path
            ));
        }

        return $path;
    }

    /**
     * Expand a leading "~" to the current user's home directory.
     */
    public static function expandHome(string $path): string
    {
        if ($path === '~' || str_starts_with($path, '~/')) {
            $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '');

            if ($home !== '') {
                return rtrim($home, '/') . substr($path, 1);
            }
        }

        return $path;
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function assertSafeAwsToken(string $value, string $name): void
    {
        if (!preg_match('/^[A-Za-z0-9_+=,.@-]+$/', $value)) {
            throw new InvalidArgumentException(sprintf(
                'SSM %s contains invalid characters. Allowed: letters, numbers, and _+=,.@-',
                $name
            ));
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function assertSafeSshOption(string $option): void
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*=[^\r\n]+$/', $option)) {
            throw new InvalidArgumentException(sprintf(
                'sshOptions entries must be in "Keyword=value" form, got "%s".',
                $option
            ));
        }
    }
}
