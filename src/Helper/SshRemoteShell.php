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
}
