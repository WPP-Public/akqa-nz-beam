<?php

namespace Heyday\Beam\Helper;

use Heyday\Beam\Exception\RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs remote shell commands and rsync transfers using a server's SSH/SSM config.
 */
class RemoteProcess
{
    /**
     * Run a command on the remote host via SSH (honours SSM ProxyCommand).
     *
     * @param array<string, string> $env Extra environment variables for the remote command
     * @throws RuntimeException
     */
    public static function run(
        array $server,
        string $remoteCommand,
        array $env = [],
        bool $tty = false,
        ?float $timeout = 600
    ): string {
        $exports = '';
        foreach ($env as $name => $value) {
            $exports .= sprintf('export %s=%s; ', $name, escapeshellarg($value));
        }

        $wrapped = $exports . $remoteCommand;
        $ssh = SshRemoteShell::build($server);
        $destination = escapeshellarg(SshRemoteShell::destinationHost($server));
        $ttyFlag = $tty ? '-t ' : '';

        $process = Process::fromShellCommandline(
            sprintf('%s %s%s %s', $ssh, $ttyFlag, $destination, escapeshellarg($wrapped))
        );
        $process->setTimeout($timeout);
        $process->run();

        if (!$process->isSuccessful()) {
            $error = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new RuntimeException($error !== '' ? $error : 'Remote command failed');
        }

        return $process->getOutput();
    }

    /**
     * Run rsync with the server's SSH/SSM remote shell.
     *
     * @param list<string> $args Full rsync argument list (flags, excludes, src, dest)
     * @throws RuntimeException
     */
    public static function rsync(array $server, array $args, ?float $timeout = 600): void
    {
        $remoteShell = SshRemoteShell::build($server);
        $command = array_merge(['rsync', '-e', $remoteShell], $args);

        $process = new Process($command);
        $process->setTimeout($timeout);
        $process->run(function ($type, $buffer) {
            echo $buffer;
        });

        if (!$process->isSuccessful()) {
            $error = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new RuntimeException($error !== '' ? $error : 'rsync failed');
        }
    }
}
