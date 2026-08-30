<?php

namespace Heyday\Beam\Command;

use Heyday\Beam\Config\BeamConfiguration;
use Heyday\Beam\Exception\RuntimeException;
use Heyday\Beam\Helper\SshRemoteShell;
use Heyday\Beam\VcsProvider\Git;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Process\Process;

class StatusCommand extends Command
{
    /**
     * @var array
     */
    protected $config;

    /**
     * @var QuestionHelper
     */
    protected $questionHelper;

    public function __construct($name = null)
    {
        parent::__construct($name);
        $this->questionHelper = new QuestionHelper();
    }

    protected function configure()
    {
        $this->setName('status')
            ->setDescription('Show deployed commit status for a server')
            ->addArgument(
                'target',
                InputArgument::OPTIONAL,
                'Config name of target server (prompted if omitted)'
            )
            ->addConfigOption();
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->config = BeamConfiguration::parseConfig($this->getConfig($input));

        if ($input->getArgument('target')) {
            BeamConfiguration::validateArguments($input, $this->config);
        }
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        if ($input->getArgument('target')) {
            return;
        }

        $targets = array_keys($this->config['servers']);

        $question = new ChoiceQuestion(
            $this->formatterHelper->formatSection(
                'prompt',
                'Which target do you want to check status for?',
                'comment'
            ),
            $targets,
            0
        );
        $question->setErrorMessage('Target "%s" is invalid.');

        $input->setArgument('target', $this->questionHelper->ask($input, $output, $question));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getArgument('target')) {
            throw new RuntimeException('The "target" argument is required.');
        }

        BeamConfiguration::validateArguments($input, $this->config);

        $target = $input->getArgument('target');
        $server = $this->config['servers'][$target];
        $hosts = $this->getHosts($server);
        $multipleHosts = count($hosts) > 1;

        foreach ($hosts as $index => $host) {
            if ($multipleHosts) {
                $output->writeln($this->formatterHelper->formatSection('info', sprintf('Host: %s', $host), 'info'));
            }

            $beamLog = $this->readBeamLog($server, $host);
            $output->writeln(rtrim($beamLog));

            $commit = $this->extractCommitHash($beamLog);
            $branches = $this->getBranchesContaining($commit, $this->getSrcDir($input));

            $output->writeln('');
            $output->writeln('This commit appears in:');
            foreach ($branches as $branch) {
                $output->writeln(sprintf('    %s', $branch));
            }

            if ($index < count($hosts) - 1) {
                $output->writeln('');
            }
        }

        return 0;
    }

    protected function getHosts(array $server): array
    {
        $hosts = [];

        if (isset($server['host']) && $server['host']) {
            $hosts[] = $server['host'];
        }
        if (isset($server['hosts']) && is_array($server['hosts'])) {
            $hosts = array_merge($hosts, $server['hosts']);
        }

        return $hosts;
    }

    protected function readBeamLog(array $server, string $host): string
    {
        $userComponent = isset($server['user']) && $server['user'] !== '' ? $server['user'] . '@' : '';
        $beamLogPath = rtrim($server['webroot'], '/') . '/.beamlog';
        $remoteCommand = sprintf('cat %s', escapeshellarg($beamLogPath));

        $process = $this->getProcess(
            sprintf(
                '%s %s %s',
                SshRemoteShell::build($server),
                escapeshellarg($userComponent . $host),
                escapeshellarg($remoteCommand)
            )
        );
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'Unable to read .beamlog from target');
        }

        return $process->getOutput();
    }

    protected function extractCommitHash(string $beamLog): string
    {
        if (!preg_match('/^commit\s+([a-f0-9]+)/m', $beamLog, $matches)) {
            throw new RuntimeException('Could not find a commit hash in .beamlog');
        }

        return $matches[1];
    }

    protected function getBranchesContaining(string $commit, string $srcDir): array
    {
        $git = new Git($srcDir);
        $process = $git->process(sprintf('git branch -a --contains %s', escapeshellarg($commit)));

        return $this->parseBranchOutput($process);
    }

    protected function parseBranchOutput(Process $process): array
    {
        $branches = [];

        foreach (explode("\n", trim($process->getOutput())) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $line = ltrim($line, '* ');
            if ($line !== '' && !in_array($line, $branches)) {
                $branches[] = $line;
            }
        }

        return $branches;
    }

    protected function getProcess($command): Process
    {
        return Process::fromShellCommandline($command, getcwd());
    }
}
