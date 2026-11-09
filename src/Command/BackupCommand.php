<?php

namespace Heyday\Beam\Command;

use Heyday\Beam\Config\BeamConfiguration;
use Heyday\Beam\Exception\InvalidArgumentException;
use Heyday\Beam\Exception\RuntimeException;
use Heyday\Beam\Helper\DatabaseTransfer;
use Heyday\Beam\Helper\RemoteProcess;
use Heyday\Beam\Helper\SshRemoteShell;
use Symfony\Component\Console\Helper\ProgressIndicator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Create, verify, and list database/assets backups stored on the target server.
 *
 *   beam backup <target>                  create and verify a backup
 *   beam backup <target> --verify[=NAME]  verify an existing backup (default: latest)
 *   beam backup <target> --list           list backups on the server
 *
 * Backups are written to <path>/<target>/<YYYYMMDD-HHMMSS>/ on the remote host, with
 * <path>/<target>/latest pointing at the most recent one.
 */
class BackupCommand extends Command
{
    private const DEFAULT_PATH = '~/beam-backups';

    private const NAME_PATTERN = '/^\d{8}-\d{6}$/';

    private const DEFAULT_ASSETS = [
        'path' => 'public/assets',
        'excludes' => [
            '*__Fill*',
            '*__Fit*',
            '*__Resized*',
            '*__ScaleWidth*',
            '*__ScaleHeight*',
        ],
    ];

    /**
     * Required free space as a multiple of the uncompressed database and assets size.
     */
    private const FREE_SPACE_FACTOR = 1.1;

    /**
     * @var array
     */
    protected $config;

    protected function configure()
    {
        $this->setName('backup')
            ->setDescription('Back up a server\'s database and assets on the server, and verify backups')
            ->addArgument(
                'target',
                InputArgument::REQUIRED,
                'Config name of target server'
            )
            ->addOption(
                'db-only',
                null,
                InputOption::VALUE_NONE,
                'Back up the database only'
            )
            ->addOption(
                'assets-only',
                null,
                InputOption::VALUE_NONE,
                'Back up the assets directory only'
            )
            ->addOption(
                'no-verify',
                null,
                InputOption::VALUE_NONE,
                'Skip verification after creating the backup'
            )
            ->addOption(
                'verify',
                null,
                InputOption::VALUE_OPTIONAL,
                'Verify an existing backup instead of creating one (name or "latest")',
                false
            )
            ->addOption(
                'list',
                null,
                InputOption::VALUE_NONE,
                'List backups stored on the server'
            )
            ->addOption(
                'keep',
                null,
                InputOption::VALUE_REQUIRED,
                'After a successful backup, delete all but the newest N backups (0 keeps all)',
                '0'
            )
            ->addOption(
                'path',
                null,
                InputOption::VALUE_REQUIRED,
                'Remote directory to store backups in ("~" is the remote user\'s home)',
                self::DEFAULT_PATH
            )
            ->addConfigOption();
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->config = BeamConfiguration::parseConfig($this->getConfig($input));
        BeamConfiguration::validateArguments($input, $this->config);

        if ($input->getOption('db-only') && $input->getOption('assets-only')) {
            throw new InvalidArgumentException('--db-only and --assets-only cannot be used together.');
        }

        if (!ctype_digit((string) $input->getOption('keep'))) {
            throw new InvalidArgumentException('--keep must be a whole number.');
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $target = $input->getArgument('target');
        $server = $this->config['servers'][$target];

        $type = $server['type'] ?? 'rsync';
        if ($type !== 'rsync') {
            throw new InvalidArgumentException(sprintf(
                'backup requires an rsync server; "%s" is configured as "%s".',
                $target,
                $type
            ));
        }

        $root = rtrim((string) $input->getOption('path'), '/') . '/'
            . preg_replace('/[^A-Za-z0-9_-]/', '-', $target);

        try {
            if ($input->getOption('list')) {
                return $this->runList($output, $server, $root);
            }

            $verify = $input->getOption('verify');
            if ($verify !== false) {
                return $this->runVerify($output, $server, $root, $verify ?: 'latest');
            }

            $name = $this->runCreate($input, $output, $server, $target, $root);

            if ($input->getOption('no-verify')) {
                return 0;
            }

            return $this->runVerify($output, $server, $root, $name);
        } catch (\Exception $e) {
            if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                throw $e;
            }

            $this->outputError($output, $e->getMessage());
            return 1;
        }
    }

    /**
     * @return string The name of the created backup
     * @throws RuntimeException
     */
    private function runCreate(
        InputInterface $input,
        OutputInterface $output,
        array $server,
        string $target,
        string $root
    ): string {
        $withDb = !$input->getOption('assets-only');
        $withAssets = !$input->getOption('db-only');
        $name = date('Ymd-His');
        $assets = $server['assets'] ?? self::DEFAULT_ASSETS;

        $this->writeln($output, sprintf(
            'Backing up %s on <info>%s</info> (%s) to %s/%s',
            implode(' and ', array_filter([$withDb ? 'database' : null, $withAssets ? 'assets' : null])),
            $target,
            SshRemoteShell::destinationHost($server),
            $root,
            $name
        ), 'comment');

        $env = [
            'BEAM_BACKUP_ROOT' => $root,
            'BEAM_BACKUP_NAME' => $name,
            'BEAM_WEBROOT' => rtrim($server['webroot'], '/'),
            'BEAM_WITH_DB' => $withDb ? '1' : '0',
            'BEAM_WITH_ASSETS' => $withAssets ? '1' : '0',
            'BEAM_ASSETS_PATH' => trim($assets['path'] ?? self::DEFAULT_ASSETS['path'], '/'),
            'BEAM_ASSETS_EXCLUDES' => implode("\n", $assets['excludes'] ?? self::DEFAULT_ASSETS['excludes']),
            'BEAM_DB_NAME' => '',
            'BEAM_DB_HOST' => '',
            'BEAM_DB_OPTIONS' => '',
        ];

        if ($withDb) {
            $database = new DatabaseTransfer(
                $server,
                $server['database'] ?? [],
                $target,
                getcwd(),
                $output,
                $this->formatterHelper
            );
            [$env['BEAM_DB_NAME'], $env['BEAM_DB_HOST']] = $database->resolveDatabaseIdentity();
            $env['BEAM_DB_OPTIONS'] = $database->optionsFile();
        }

        $preflight = $this->parseKeyValues($this->runRemote(
            $output,
            $server,
            'Checking remote tools, sizes, and free disk space',
            $this->preflightScript(),
            $env
        ));

        $neededKb = (int) (((int) ($preflight['DB_KB'] ?? 0) + (int) ($preflight['ASSETS_KB'] ?? 0))
            * self::FREE_SPACE_FACTOR);
        $freeKb = (int) ($preflight['FREE_KB'] ?? 0);

        $this->writeln($output, sprintf(
            '  %s%sFree space: %s',
            $withDb ? sprintf(
                'Database "%s": %s tables, ~%s · ',
                $env['BEAM_DB_NAME'],
                $preflight['DB_TABLES'] ?? '?',
                $this->formatKb((int) ($preflight['DB_KB'] ?? 0))
            ) : '',
            $withAssets ? sprintf('Assets: %s · ', $this->formatKb((int) ($preflight['ASSETS_KB'] ?? 0))) : '',
            $this->formatKb($freeKb)
        ));

        if ($freeKb < $neededKb) {
            throw new RuntimeException(sprintf(
                'Not enough free space in %s on the server: need ~%s, have %s.',
                $root,
                $this->formatKb($neededKb),
                $this->formatKb($freeKb)
            ));
        }

        $env['BEAM_DB_TABLES'] = (string) ($preflight['DB_TABLES'] ?? '');

        if ($withDb) {
            $this->runRemote(
                $output,
                $server,
                sprintf('Dumping database "%s"', $env['BEAM_DB_NAME']),
                $this->databaseScript(),
                $env
            );
        }

        $env['BEAM_ASSETS_FILES'] = '';
        if ($withAssets) {
            $result = $this->parseKeyValues($this->runRemote(
                $output,
                $server,
                sprintf('Archiving %s', $env['BEAM_ASSETS_PATH']),
                $this->assetsScript(),
                $env
            ));
            $env['BEAM_ASSETS_FILES'] = (string) ($result['ASSETS_FILES'] ?? '');
        }

        $env['BEAM_TARGET'] = $target;
        $env['BEAM_KEEP'] = (string) $input->getOption('keep');

        $this->runRemote(
            $output,
            $server,
            'Writing manifest and checksums',
            $this->finalizeScript(),
            $env
        );

        $this->writeln($output, sprintf('Backup saved: %s/%s', $root, $name), 'info');

        return $name;
    }

    /**
     * @throws RuntimeException
     */
    private function runVerify(OutputInterface $output, array $server, string $root, string $name): int
    {
        if ($name !== 'latest' && !preg_match(self::NAME_PATTERN, $name)) {
            throw new InvalidArgumentException(sprintf(
                'Backup name must be "latest" or YYYYMMDD-HHMMSS, got "%s".',
                $name
            ));
        }

        $raw = $this->runRemote(
            $output,
            $server,
            sprintf('Verifying backup %s/%s', $root, $name),
            $this->verifyScript(),
            [
                'BEAM_BACKUP_ROOT' => $root,
                'BEAM_BACKUP_NAME' => $name,
            ]
        );

        $failed = false;
        $checks = 0;

        foreach (preg_split('/\R/', trim($raw)) as $line) {
            if (str_starts_with($line, 'INFO|')) {
                [, $label, $detail] = array_pad(explode('|', $line, 3), 3, '');
                $this->writeln($output, sprintf('  %-18s %s', $label, $detail));
                continue;
            }

            if (!str_starts_with($line, 'RESULT|')) {
                continue;
            }

            [, $status, $label, $detail] = array_pad(explode('|', $line, 4), 4, '');
            $ok = $status === 'ok';
            $failed = $failed || !$ok;
            $checks++;

            $this->writeln($output, sprintf(
                '  %s %-18s %s',
                $ok ? '<info>✔ OK  </info>' : '<error>✖ FAIL</error>',
                $label,
                $detail
            ));
        }

        if ($checks === 0) {
            throw new RuntimeException('Verification produced no results.');
        }

        if ($failed) {
            $this->outputError($output, 'Backup verification FAILED.');
            return 1;
        }

        $this->writeln($output, 'Backup verified.', 'info');

        return 0;
    }

    private function runList(OutputInterface $output, array $server, string $root): int
    {
        $raw = $this->runRemote(
            $output,
            $server,
            sprintf('Listing backups in %s', $root),
            $this->listScript(),
            ['BEAM_BACKUP_ROOT' => $root]
        );

        $lines = array_filter(preg_split('/\R/', trim($raw)));
        if ($lines === []) {
            $this->writeln($output, 'No backups found.', 'comment');
            return 0;
        }

        foreach ($lines as $line) {
            [$name, $size, $latest] = array_pad(explode('|', $line, 3), 3, '');
            $this->writeln($output, sprintf(
                '  %s  %8s %s',
                $name,
                $size,
                $latest !== '' ? '<info>(latest)</info>' : ''
            ));
        }

        return 0;
    }

    /**
     * Run a remote bash script while showing a spinner with elapsed time.
     *
     * @param array<string, string> $env
     * @throws RuntimeException
     */
    protected function runRemote(
        OutputInterface $output,
        array $server,
        string $message,
        string $script,
        array $env
    ): string {
        $process = RemoteProcess::process($server, sprintf('bash -c %s', escapeshellarg($script)), $env, timeout: null);

        $indicator = new ProgressIndicator(
            $output,
            $output->isDecorated() ? 'verbose' : 'verbose_no_ansi',
            100,
            ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏']
        );
        $indicator->start($message . '…');

        $process->start();
        while ($process->isRunning()) {
            $indicator->advance();
            usleep(100000);
        }

        if (!$process->isSuccessful()) {
            $indicator->finish($message, '<error>✖</error>');
            RemoteProcess::assertSuccessful($process);
        }

        $indicator->finish($message, '<info>✔</info>');

        return $process->getOutput();
    }

    private function preflightScript(): string
    {
        return <<<'BASH'
set -eu -o pipefail
ROOT="${BEAM_BACKUP_ROOT/#\~/$HOME}"
for bin in tar gzip sha256sum find df du; do
    command -v "$bin" > /dev/null || { echo "Required command '$bin' not found on the server" >&2; exit 1; }
done
DB_KB=0
ASSETS_KB=0
if [ "$BEAM_WITH_DB" = 1 ]; then
    for bin in mysql mysqldump; do
        command -v "$bin" > /dev/null || { echo "Required command '$bin' not found on the server" >&2; exit 1; }
    done
    OPTS="${BEAM_DB_OPTIONS/#\~/$HOME}"
    [ -r "$OPTS" ] || { echo "MySQL option file $OPTS is missing or unreadable (database.optionsFile)" >&2; exit 1; }
    MYSQL=(mysql --defaults-extra-file="$OPTS" --host="$BEAM_DB_HOST" -N -B "$BEAM_DB_NAME")
    TABLES_SQL="FROM information_schema.tables WHERE table_schema = DATABASE()"
    DB_KB=$("${MYSQL[@]}" -e "SELECT COALESCE(CEIL(SUM(data_length + index_length) / 1024), 0) $TABLES_SQL")
    echo "DB_TABLES=$("${MYSQL[@]}" -e "SELECT COUNT(*) $TABLES_SQL AND table_type = 'BASE TABLE'")"
fi
if [ "$BEAM_WITH_ASSETS" = 1 ]; then
    ASSETS_DIR="$BEAM_WEBROOT/$BEAM_ASSETS_PATH"
    [ -d "$ASSETS_DIR" ] || { echo "Assets directory $ASSETS_DIR not found" >&2; exit 1; }
    ASSETS_KB=$(du -sk "$ASSETS_DIR" | cut -f1)
fi
mkdir -p "$ROOT"
echo "DB_KB=$DB_KB"
echo "ASSETS_KB=$ASSETS_KB"
echo "FREE_KB=$(df -Pk "$ROOT" | awk 'NR == 2 { print $4 }')"
BASH;
    }

    private function databaseScript(): string
    {
        return <<<'BASH'
set -eu -o pipefail
DEST="${BEAM_BACKUP_ROOT/#\~/$HOME}/$BEAM_BACKUP_NAME"
OPTS="${BEAM_DB_OPTIONS/#\~/$HOME}"
mkdir -p "$DEST"
EXTRA=()
# MariaDB's mysqldump has no --set-gtid-purged option.
if mysqldump --help 2> /dev/null | grep -q -- '--set-gtid-purged'; then
    EXTRA+=(--set-gtid-purged=OFF)
fi
mysqldump \
    --defaults-extra-file="$OPTS" \
    --host="$BEAM_DB_HOST" \
    --no-tablespaces \
    --single-transaction \
    ${EXTRA[@]+"${EXTRA[@]}"} \
    "$BEAM_DB_NAME" | gzip > "$DEST/db.sql.gz.partial"
mv "$DEST/db.sql.gz.partial" "$DEST/db.sql.gz"
BASH;
    }

    private function assetsScript(): string
    {
        return <<<'BASH'
set -eu -o pipefail
DEST="${BEAM_BACKUP_ROOT/#\~/$HOME}/$BEAM_BACKUP_NAME"
mkdir -p "$DEST"
cd "$BEAM_WEBROOT/$BEAM_ASSETS_PATH"
FIND_ARGS=()
while IFS= read -r pattern; do
    [ -n "$pattern" ] && FIND_ARGS+=(! -name "$pattern")
done <<< "$BEAM_ASSETS_EXCLUDES"
find . -type f ${FIND_ARGS[@]+"${FIND_ARGS[@]}"} -print0 > "$DEST/assets.files0"
COUNT=$(tr -cd '\0' < "$DEST/assets.files0" | wc -c | tr -d ' ')
# GNU tar exits 1 when a file changed while being read; the archive is still usable.
RC=0
tar -czf "$DEST/assets.tar.gz.partial" --null -T "$DEST/assets.files0" || RC=$?
[ "$RC" -le 1 ] || exit "$RC"
mv "$DEST/assets.tar.gz.partial" "$DEST/assets.tar.gz"
rm -f "$DEST/assets.files0"
echo "ASSETS_FILES=$COUNT"
BASH;
    }

    private function finalizeScript(): string
    {
        return <<<'BASH'
set -eu -o pipefail
ROOT="${BEAM_BACKUP_ROOT/#\~/$HOME}"
DEST="$ROOT/$BEAM_BACKUP_NAME"
cd "$DEST"
{
    echo "TARGET=$BEAM_TARGET"
    echo "CREATED=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo "HOST=$(hostname)"
    if [ -f db.sql.gz ]; then
        echo "DB_NAME=$BEAM_DB_NAME"
        echo "DB_TABLES=$BEAM_DB_TABLES"
    fi
    if [ -f assets.tar.gz ]; then
        echo "ASSETS_PATH=$BEAM_WEBROOT/$BEAM_ASSETS_PATH"
        echo "ASSETS_FILES=$BEAM_ASSETS_FILES"
    fi
} > manifest.env
FILES=()
for f in db.sql.gz assets.tar.gz; do
    [ -f "$f" ] && FILES+=("$f")
done
sha256sum "${FILES[@]}" > SHA256SUMS
ln -sfn "$BEAM_BACKUP_NAME" "$ROOT/latest"
if [ "$BEAM_KEEP" -gt 0 ]; then
    find "$ROOT" -mindepth 1 -maxdepth 1 -type d -name '[0-9]*-[0-9]*' -printf '%f\n' \
        | sort -r | tail -n +"$((BEAM_KEEP + 1))" \
        | while IFS= read -r old; do rm -rf "${ROOT:?}/$old"; done
fi
BASH;
    }

    private function verifyScript(): string
    {
        return <<<'BASH'
set -u -o pipefail
ROOT="${BEAM_BACKUP_ROOT/#\~/$HOME}"
DIR="$ROOT/$BEAM_BACKUP_NAME"
ok() { echo "RESULT|ok|$1|$2"; }
fail() { echo "RESULT|fail|$1|$2"; }
if [ ! -d "$DIR" ]; then
    fail "Backup" "$DIR not found"
    exit 0
fi
DIR="$(cd "$DIR" && pwd -P)"
cd "$DIR"
echo "INFO|Location|$DIR"
if [ ! -f manifest.env ] || [ ! -f SHA256SUMS ]; then
    fail "Manifest" "manifest.env or SHA256SUMS missing (backup incomplete?)"
    exit 0
fi
manifest() { grep -E "^$1=" manifest.env | head -n 1 | cut -d= -f2-; }
echo "INFO|Created|$(manifest CREATED) on $(manifest HOST)"
if sha256sum --quiet -c SHA256SUMS > /dev/null 2>&1; then
    ok "Checksums" "SHA256SUMS match"
else
    fail "Checksums" "sha256sum -c SHA256SUMS failed"
fi
if [ -f db.sql.gz ]; then
    SIZE=$(du -h db.sql.gz | cut -f1)
    DUMP_STATS='/^CREATE TABLE /{ n++ } { last = $0 } END { print n + 0; print last }'
    if STATS=$(gzip -dc db.sql.gz 2> /dev/null | awk "$DUMP_STATS"); then
        ok "Database archive" "gzip stream intact ($SIZE)"
        TABLES=$(echo "$STATS" | sed -n 1p)
        LAST=$(echo "$STATS" | sed -n 2p)
        case "$LAST" in
            "-- Dump completed"*) ok "Database dump" "complete (${LAST#-- })" ;;
            *) fail "Database dump" "truncated: no 'Dump completed' footer" ;;
        esac
        EXPECTED=$(manifest DB_TABLES)
        if [ "$TABLES" = "$EXPECTED" ]; then
            ok "Database tables" "$TABLES of $EXPECTED tables in $(manifest DB_NAME)"
        else
            fail "Database tables" "found $TABLES CREATE TABLE statements, expected $EXPECTED"
        fi
    else
        fail "Database archive" "gzip stream is corrupt ($SIZE)"
    fi
fi
if [ -f assets.tar.gz ]; then
    SIZE=$(du -h assets.tar.gz | cut -f1)
    EXPECTED=$(manifest ASSETS_FILES)
    if FOUND=$(tar -tzf assets.tar.gz | awk '!/\/$/ { n++ } END { print n + 0 }'); then
        ok "Assets archive" "tar stream intact ($SIZE)"
        if [ "$FOUND" = "$EXPECTED" ]; then
            ok "Assets files" "$FOUND of $EXPECTED files"
        else
            fail "Assets files" "archive has $FOUND files, expected $EXPECTED"
        fi
    else
        fail "Assets archive" "tar stream is corrupt ($SIZE)"
    fi
fi
if [ ! -f db.sql.gz ] && [ ! -f assets.tar.gz ]; then
    fail "Contents" "no db.sql.gz or assets.tar.gz in backup"
fi
BASH;
    }

    private function listScript(): string
    {
        return <<<'BASH'
set -eu -o pipefail
ROOT="${BEAM_BACKUP_ROOT/#\~/$HOME}"
[ -d "$ROOT" ] || exit 0
LATEST=$(readlink "$ROOT/latest" 2> /dev/null || true)
find "$ROOT" -mindepth 1 -maxdepth 1 -type d -name '[0-9]*-[0-9]*' -printf '%f\n' | sort | while IFS= read -r name; do
    MARK=""
    [ "$name" = "$LATEST" ] && MARK="latest"
    echo "$name|$(du -sh "$ROOT/$name" | cut -f1)|$MARK"
done
BASH;
    }

    /**
     * @return array<string, string>
     */
    private function parseKeyValues(string $raw): array
    {
        $values = [];
        foreach (preg_split('/\R/', trim($raw)) as $line) {
            if (preg_match('/^([A-Z_]+)=(.*)$/', $line, $matches)) {
                $values[$matches[1]] = trim($matches[2]);
            }
        }

        return $values;
    }

    private function formatKb(int $kb): string
    {
        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = (float) $kb;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return sprintf($unit === 0 ? '%d %s' : '%.1f %s', $value, $units[$unit]);
    }

    private function writeln(OutputInterface $output, string $message, string $style = 'info'): void
    {
        $output->writeln($this->formatterHelper->formatSection('backup', $message, $style));
    }
}
