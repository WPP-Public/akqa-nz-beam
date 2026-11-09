<?php

namespace Heyday\Beam\Command;

use Heyday\Beam\Config\BeamConfiguration;
use Heyday\Beam\Exception\InvalidArgumentException;
use Heyday\Beam\Exception\RuntimeException;
use Heyday\Beam\Helper\AwsCredentials;
use Heyday\Beam\Helper\CredentialPrompt;
use Heyday\Beam\Helper\InteractivePrompt;
use Heyday\Beam\Helper\RemoteProcess;
use Heyday\Beam\Helper\SshRemoteShell;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * AWS access-portal login, connectivity check, and interactive SSM tunnel helpers.
 *
 *   beam ssm login [target]
 *   beam ssm status [target]
 *   beam ssm tunnel [target]
 */
class SsmCommand extends Command
{
    private const ACTIONS = ['login', 'status', 'tunnel'];

    private const STATUS_SSH_TIMEOUT = 45;

    /**
     * @var array
     */
    protected $config;

    private bool $cancelled = false;

    public function __construct($name = null)
    {
        parent::__construct($name);
    }

    protected function configure()
    {
        $this->setName('ssm')
            ->setDescription('AWS SSM helpers: write portal credentials, check connectivity, or open a tunnel')
            ->addArgument(
                'action',
                InputArgument::REQUIRED,
                'Action to run: login, status, or tunnel'
            )
            ->addArgument(
                'target',
                InputArgument::OPTIONAL,
                'SSM-enabled server from beam.json (prompted if omitted; status checks all when omitted)'
            )
            ->addOption(
                'credentials-file',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to a file containing AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, and AWS_SESSION_TOKEN'
            )
            ->addConfigOption();
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->config = BeamConfiguration::parseConfig($this->getConfig($input));
        $this->assertValidAction($input->getArgument('action'));

        if ($input->getArgument('target')) {
            $this->assertSsmTarget($input->getArgument('target'));
        }
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        if ($input->getArgument('action') === 'status') {
            return;
        }

        $this->registerCancelHandler($output);

        if (!$input->getArgument('target')) {
            $targets = $this->ssmTargets();

            if ($targets === []) {
                throw new RuntimeException(
                    'No SSM-enabled servers found in beam.json. Enable "ssm" on an rsync server first.'
                );
            }

            $output->writeln($this->formatterHelper->formatSection(
                'ssm',
                'Ctrl+C or choose cancel to abort',
                'comment'
            ));

            $target = InteractivePrompt::choose(
                $output,
                $this->formatterHelper,
                'Which environment?',
                $targets
            );

            if ($target === null) {
                $this->cancelled = true;
                return;
            }

            $input->setArgument('target', $target);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->cancelled) {
            $this->outputError($output, 'Cancelled');
            return 1;
        }

        $action = $input->getArgument('action');
        $target = $input->getArgument('target');

        if ($action === 'status') {
            return $this->runStatus($output, $target ? [$target] : $this->ssmTargets());
        }

        if (!$target) {
            throw new RuntimeException('The "target" argument is required.');
        }

        $this->assertSsmTarget($target);
        $server = $this->config['servers'][$target];

        return match ($action) {
            'login' => $this->runLogin($input, $output, $target, $server),
            'tunnel' => $this->runTunnel($output, $target, $server),
            default => throw new InvalidArgumentException(sprintf('Unknown action "%s".', $action)),
        };
    }

    /**
     * @param array $server
     */
    private function runLogin(InputInterface $input, OutputInterface $output, string $target, array $server): int
    {
        $this->registerCancelHandler($output);

        $portal = $this->resolvePortalUrl($server, $target);
        $profile = $server['ssm']['profile'] ?? null;
        if ($profile === null || $profile === '') {
            $profile = 'default';
        }

        $output->writeln($this->formatterHelper->formatSection(
            'ssm',
            sprintf('Opening AWS access portal for <info>%s</info>…', $target),
            'comment'
        ));
        $this->openUrl($portal);

        $output->writeln($this->formatterHelper->formatSection(
            'ssm',
            sprintf(
                'Copy access keys from the portal. An editor will open to paste them'
                . ' (profile <info>%s</info> in ~/.aws/credentials).',
                $profile
            ),
            'comment'
        ));

        $credentialsFile = $input->getOption('credentials-file');
        if ($credentialsFile !== null) {
            $credentials = CredentialPrompt::parseCredentialsFile(
                SshRemoteShell::expandHome($credentialsFile)
            );
        } else {
            $output->writeln($this->formatterHelper->formatSection(
                'ssm',
                'Save and close the editor when all three values are set.',
                'comment'
            ));
            $credentials = CredentialPrompt::collectViaEditor();
        }

        $path = AwsCredentials::writeProfile(
            $profile,
            $credentials['accessKeyId'],
            $credentials['secretAccessKey'],
            $credentials['sessionToken']
        );

        $output->writeln($this->formatterHelper->formatSection(
            'ssm',
            sprintf('Wrote credentials for profile <info>%s</info> to %s', $profile, $path),
            'info'
        ));

        if (!empty($server['ssm']['region'])) {
            $output->writeln($this->formatterHelper->formatSection(
                'ssm',
                sprintf(
                    'Region for this target is <info>%s</info> (from beam.json). Use: aws --profile %s --region %s …',
                    $server['ssm']['region'],
                    $profile,
                    $server['ssm']['region']
                ),
                'comment'
            ));
        }

        return 0;
    }

    /**
     * @param array $server
     */
    private function runTunnel(OutputInterface $output, string $target, array $server): int
    {
        $this->registerCancelHandler($output);

        $destination = SshRemoteShell::destinationHost($server);
        $ssh = SshRemoteShell::build($server);
        $command = sprintf('%s -t %s', $ssh, escapeshellarg($destination));

        $output->writeln($this->formatterHelper->formatSection(
            'ssm',
            sprintf('Opening interactive SSH tunnel to <info>%s</info> (%s)…', $target, $destination),
            'comment'
        ));
        $output->writeln($this->formatterHelper->formatSection('ssm', $command, 'comment'));

        $process = Process::fromShellCommandline($command);
        $process->setTimeout(null);

        if (Process::isTtySupported()) {
            $process->setTty(true);
        }

        $process->run(function ($type, $buffer) {
            echo $buffer;
        });

        return $process->getExitCode() ?? 1;
    }

    /**
     * Check AWS credentials and SSH-over-SSM for each target. Exits non-zero if any fail.
     *
     * @param list<string> $targets
     */
    private function runStatus(OutputInterface $output, array $targets): int
    {
        if ($targets === []) {
            throw new RuntimeException(
                'No SSM-enabled servers found in beam.json. Enable "ssm" on an rsync server first.'
            );
        }

        $failed = [];

        foreach ($targets as $target) {
            $this->assertSsmTarget($target);
            $server = $this->config['servers'][$target];
            $profile = $server['ssm']['profile'] ?? null;

            $output->writeln($this->formatterHelper->formatSection(
                'ssm',
                sprintf(
                    '<info>%s</info> (%s%s)',
                    $target,
                    SshRemoteShell::destinationHost($server),
                    $profile ? ', profile ' . $profile : ''
                ),
                'comment'
            ));

            [$credentialsOk, $credentialsDetail] = $this->checkAwsCredentials($server['ssm']);
            $this->writeStatusLine($output, $credentialsOk, 'AWS credentials', $credentialsDetail);

            if (!$credentialsOk) {
                $this->writeStatusLine($output, null, 'SSH over SSM', 'skipped (no valid AWS credentials)');
                $failed[] = $target;
                continue;
            }

            [$sshOk, $sshDetail] = $this->checkSsh($server);
            $this->writeStatusLine($output, $sshOk, 'SSH over SSM', $sshDetail);

            if (!$sshOk) {
                $failed[] = $target;
            }
        }

        if ($failed === []) {
            $output->writeln($this->formatterHelper->formatSection('ssm', 'All connections OK.', 'info'));
            return 0;
        }

        foreach ($failed as $target) {
            $this->outputError($output, sprintf(
                'Cannot connect to "%s". Log in with: beam ssm login %s',
                $target,
                $target
            ));
        }

        return 1;
    }

    /**
     * @param array $ssm Processed ssm configuration node
     * @return array{0: bool, 1: string}
     */
    protected function checkAwsCredentials(array $ssm): array
    {
        $command = ['aws', 'sts', 'get-caller-identity', '--query', 'Arn', '--output', 'text'];
        if (!empty($ssm['profile'])) {
            array_push($command, '--profile', $ssm['profile']);
        }
        if (!empty($ssm['region'])) {
            array_push($command, '--region', $ssm['region']);
        }

        $process = new Process($command);
        $process->setTimeout(30);

        try {
            $process->run();
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }

        if (!$process->isSuccessful()) {
            return [false, $this->firstLine($process->getErrorOutput() ?: $process->getOutput())];
        }

        return [true, trim($process->getOutput())];
    }

    /**
     * @param array $server Processed server configuration
     * @return array{0: bool, 1: string}
     */
    protected function checkSsh(array $server): array
    {
        // BatchMode stops a host-key or passphrase prompt from hanging the check.
        $process = RemoteProcess::process(
            $server,
            'echo "$(whoami)@$(hostname)"',
            timeout: self::STATUS_SSH_TIMEOUT,
            sshOptions: ['BatchMode=yes', 'ConnectTimeout=30']
        );

        try {
            $process->run();
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }

        if (!$process->isSuccessful()) {
            return [false, $this->firstLine($process->getErrorOutput() ?: $process->getOutput())];
        }

        return [true, 'connected as ' . trim($process->getOutput())];
    }

    private function writeStatusLine(OutputInterface $output, ?bool $ok, string $label, string $detail): void
    {
        $badge = match ($ok) {
            true => '<info>✔ OK  </info>',
            false => '<error>✖ FAIL</error>',
            null => '<comment>- SKIP</comment>',
        };

        $output->writeln($this->formatterHelper->formatSection(
            'ssm',
            sprintf('  %s %-16s %s', $badge, $label, $detail)
        ));
    }

    private function firstLine(string $text): string
    {
        foreach (preg_split('/\R/', trim($text)) as $line) {
            if (trim($line) !== '') {
                return trim($line);
            }
        }

        return 'unknown error';
    }

    private function registerCancelHandler(OutputInterface $output): void
    {
        InteractivePrompt::enableCancelHandler(function () use ($output): void {
            $this->outputError($output, 'Cancelled');
        });
    }

    private function openUrl(string $url): void
    {
        $command = match (PHP_OS_FAMILY) {
            'Darwin' => ['open', $url],
            'Windows' => ['cmd', '/c', 'start', '', $url],
            default => ['xdg-open', $url],
        };

        $process = new Process($command);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                'Unable to open "%s" in a browser. Open it manually, then continue.',
                $url
            ));
        }
    }

    /**
     * @return list<string>
     */
    private function ssmTargets(): array
    {
        $targets = [];
        foreach ($this->config['servers'] as $name => $server) {
            if (SshRemoteShell::isEnabled($server)) {
                $targets[] = $name;
            }
        }

        return $targets;
    }

    private function assertValidAction(mixed $action): void
    {
        if (!is_string($action) || !in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Action must be one of: %s.',
                implode(', ', self::ACTIONS)
            ));
        }
    }

    private function assertSsmTarget(string $target): void
    {
        if (!isset($this->config['servers'][$target])) {
            throw new InvalidArgumentException(sprintf(
                'Target "%s" is not defined. Available: %s',
                $target,
                implode(', ', array_keys($this->config['servers']))
            ));
        }

        $server = $this->config['servers'][$target];
        if (!SshRemoteShell::isEnabled($server)) {
            throw new InvalidArgumentException(sprintf(
                'Target "%s" does not have SSM enabled. Set servers.%s.ssm in beam.json.',
                $target,
                $target
            ));
        }
    }

    /**
     * @param array $server
     */
    private function resolvePortalUrl(array $server, string $target): string
    {
        $portal = trim((string) ($server['ssm']['portalUrl'] ?? ''));

        if ($portal === '') {
            throw new InvalidArgumentException(sprintf(
                'Target "%s" has no SSM portal URL. Set servers.%s.ssm.portalUrl in beam.json.',
                $target,
                $target
            ));
        }

        if (!preg_match('#^https?://#', $portal)) {
            throw new InvalidArgumentException(sprintf(
                'servers.%s.ssm.portalUrl must be an http or https URL.',
                $target
            ));
        }

        return $portal;
    }
}
