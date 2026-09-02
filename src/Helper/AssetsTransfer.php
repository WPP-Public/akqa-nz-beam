<?php

namespace Heyday\Beam\Helper;

use Heyday\Beam\Exception\RuntimeException;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Pull or push a server's configured assets directory over rsync/SSH/SSM.
 */
class AssetsTransfer
{
    /**
     * @param array $server Processed server configuration
     * @param array $config Processed assets configuration node
     */
    public function __construct(
        private array $server,
        private array $config,
        private string $srcDir,
        private OutputInterface $output,
        private FormatterHelper $formatter
    ) {
    }

    /**
     * @throws RuntimeException
     */
    public function pull(bool $dryRun = false, bool $delete = false): void
    {
        $this->transfer('down', $dryRun, $delete);
    }

    /**
     * @throws RuntimeException
     */
    public function push(bool $dryRun = false, bool $delete = false): void
    {
        $this->transfer('up', $dryRun, $delete);
    }

    private function transfer(string $direction, bool $dryRun, bool $delete): void
    {
        $remoteRel = trim($this->config['path'], '/');
        $localRel = trim($this->config['localPath'] ?? $this->config['path'], '/');

        $remotePath = rtrim($this->server['webroot'], '/') . '/' . $remoteRel . '/';
        $localPath = rtrim($this->srcDir, '/') . '/' . $localRel . '/';
        $destination = SshRemoteShell::destinationHost($this->server);

        if ($direction === 'down') {
            if (!is_dir($localPath) && !mkdir($localPath, 0755, true) && !is_dir($localPath)) {
                throw new RuntimeException(sprintf('Unable to create directory "%s".', $localPath));
            }
            $from = $destination . ':' . $remotePath;
            $to = $localPath;
            $this->writeln(sprintf('Pulling assets from %s to %s', $from, $to));
        } else {
            if (!is_dir($localPath)) {
                throw new RuntimeException(sprintf('Local assets directory does not exist: %s', $localPath));
            }
            $from = $localPath;
            $to = $destination . ':' . $remotePath;
            $this->writeln(sprintf('Pushing assets from %s to %s', $from, $to));

            if (!$dryRun && !empty($this->config['ensureWritable'])) {
                $this->writeln(sprintf('Ensuring %s is writable', $remotePath));
                RemoteProcess::run(
                    $this->server,
                    sprintf('sudo chmod -R 777 %s', escapeshellarg(rtrim($remotePath, '/'))),
                    tty: true,
                    timeout: $this->timeout()
                );
            }
        }

        $args = [
            '-avz',
            '--omit-dir-times',
            '--human-readable',
            '--progress',
        ];

        foreach ($this->config['excludes'] as $exclude) {
            $args[] = '--exclude=' . $exclude;
        }

        if ($delete) {
            $args[] = '--delete';
        }

        if ($dryRun) {
            $args[] = '--dry-run';
        }

        $args[] = $from;
        $args[] = $to;

        RemoteProcess::rsync($this->server, $args, $this->timeout());
    }

    private function timeout(): float
    {
        return (float) ($this->server['timeout'] ?? 600);
    }

    private function writeln(string $message): void
    {
        $this->output->writeln(
            $this->formatter->formatSection('assets', $message)
        );
    }
}
