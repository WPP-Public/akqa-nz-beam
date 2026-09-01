<?php

namespace Heyday\Beam\Helper;

use Heyday\Beam\Exception\RuntimeException;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Pull a remote MySQL dump over SSH/SSM and optionally import it locally.
 */
class DatabaseTransfer
{
    /**
     * @param array $server Processed server configuration
     * @param array $config Processed database configuration node
     */
    public function __construct(
        private array $server,
        private array $config,
        private string $target,
        private string $srcDir,
        private OutputInterface $output,
        private FormatterHelper $formatter
    ) {
    }

    /**
     * @throws RuntimeException
     */
    public function pull(bool $dryRun = false, bool $keepDump = false, bool $skipImport = false): void
    {
        [$dbName, $dbHost] = $this->resolveDatabaseIdentity();

        $remoteDump = $this->config['remoteDumpPath']
            ?: sprintf('/tmp/beam-%s-db.sql.gz', preg_replace('/[^A-Za-z0-9_-]/', '-', $this->target));
        $localDump = $this->resolveLocalPath();

        $this->writeln(sprintf(
            'Dumping database "%s" on %s -> %s (credentials from remote ~/.mysql.cnf)',
            $dbName,
            RemoteProcess::destinationHost($this->server),
            $remoteDump
        ));

        if ($dryRun) {
            $this->writeln('[dry-run] Skipping remote mysqldump, download, and import.');
            return;
        }

        $this->dumpRemote($dbName, $dbHost, $remoteDump);

        $localDir = dirname($localDump);
        if (!is_dir($localDir) && !mkdir($localDir, 0755, true) && !is_dir($localDir)) {
            throw new RuntimeException(sprintf('Unable to create directory "%s".', $localDir));
        }

        $this->writeln(sprintf('Downloading dump to %s', $localDump));
        RemoteProcess::rsync($this->server, [
            '-az',
            '--human-readable',
            '--progress',
            RemoteProcess::destinationHost($this->server) . ':' . $remoteDump,
            $localDump,
        ], $this->timeout());

        if (!$keepDump) {
            $this->writeln('Removing remote dump');
            RemoteProcess::run(
                $this->server,
                sprintf('rm -f %s', escapeshellarg($remoteDump)),
                timeout: $this->timeout()
            );
        }

        if ($skipImport || empty($this->config['importCommand'])) {
            $this->writeln(sprintf('Dump saved to %s', $localDump));
            return;
        }

        $importCommand = sprintf($this->config['importCommand'], escapeshellarg($localDump));
        $this->writeln(sprintf('Importing dump: %s', $importCommand));

        $process = Process::fromShellCommandline($importCommand, $this->srcDir);
        $process->setTimeout($this->timeout());
        $process->run(function ($type, $buffer) {
            echo $buffer;
        });

        if (!$process->isSuccessful()) {
            $message = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new RuntimeException($message !== '' ? $message : 'Import command failed');
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveDatabaseIdentity(): array
    {
        $dbName = $this->config['name'] ?? null;
        $dbHost = $this->config['host'] ?? 'localhost';

        if ($dbName) {
            return [$dbName, $dbHost ?: 'localhost'];
        }

        $this->writeln('No database.name configured; reading SS_DATABASE_* from remote .env');
        $envPath = rtrim($this->server['webroot'], '/') . '/.env';
        $remoteEnv = RemoteProcess::run(
            $this->server,
            sprintf("grep -E '^SS_DATABASE_(NAME|SERVER)=' %s", escapeshellarg($envPath)),
            timeout: $this->timeout()
        );

        $dbName = $this->extractEnvVar($remoteEnv, 'SS_DATABASE_NAME');
        $envHost = $this->extractEnvVar($remoteEnv, 'SS_DATABASE_SERVER');
        if ($envHost !== '') {
            $dbHost = $envHost;
        }

        if ($dbName === '') {
            throw new RuntimeException(
                'Could not resolve database name. Set servers.<target>.database.name in beam.json '
                . 'or ensure SS_DATABASE_NAME exists in the remote .env.'
            );
        }

        return [$dbName, $dbHost ?: 'localhost'];
    }

    private function dumpRemote(string $dbName, string $dbHost, string $remoteDump): void
    {
        // mysqldump reads user/password from the remote deploy user's ~/.mysql.cnf
        // ([client] section) — the same option file MySQL clients use by default.
        $lines = [
            'set -eu -o pipefail',
            'DUMP_SQL="${BEAM_DUMP_PATH%.gz}"',
            'mysqldump'
                . ' --defaults-extra-file="${HOME}/.mysql.cnf"'
                . ' --host="${BEAM_DB_HOST}"'
                . ' --no-tablespaces'
                . ' --single-transaction'
                . ' --set-gtid-purged=OFF'
                . ' "${BEAM_DB_NAME}" > "${DUMP_SQL}"',
        ];

        if (!empty($this->config['compatTransforms'])) {
            $lines[] = 'sed -i \'s/utf8mb4_0900_ai_ci/utf8_general_ci/g\' "${DUMP_SQL}"';
            $lines[] = 'sed -i \'s/utf8mb4_/utf8_/g\' "${DUMP_SQL}"';
            $lines[] = 'sed -i \'s/CHARSET=utf8mb4/CHARSET=utf8/g\' "${DUMP_SQL}"';
            $lines[] = 'sed -i \'s/CHARACTER SET utf8mb4/CHARACTER SET utf8/g\' "${DUMP_SQL}"';
        }

        $lines[] = 'gzip -f "${DUMP_SQL}"';

        RemoteProcess::run(
            $this->server,
            implode("\n", $lines),
            [
                'BEAM_DB_NAME' => $dbName,
                'BEAM_DB_HOST' => $dbHost,
                'BEAM_DUMP_PATH' => $remoteDump,
            ],
            timeout: $this->timeout()
        );
    }

    private function resolveLocalPath(): string
    {
        $path = str_replace('%target%', $this->target, $this->config['localPath']);
        $path = SshRemoteShell::expandHome($path);

        if ($path[0] !== '/') {
            $path = rtrim($this->srcDir, '/') . '/' . ltrim($path, '/');
        }

        return $path;
    }

    private function extractEnvVar(string $envBlock, string $name): string
    {
        if (!preg_match('/^' . preg_quote($name, '/') . '=(.*)$/m', $envBlock, $matches)) {
            return '';
        }

        return trim($matches[1], " \t\"'");
    }

    private function timeout(): float
    {
        return (float) ($this->server['timeout'] ?? 600);
    }

    private function writeln(string $message): void
    {
        $this->output->writeln(
            $this->formatter->formatSection('db', $message)
        );
    }
}
