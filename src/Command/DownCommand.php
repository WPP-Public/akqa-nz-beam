<?php

namespace Heyday\Beam\Command;

use Heyday\Beam\Exception\InvalidArgumentException;
use Heyday\Beam\Exception\RuntimeException;
use Heyday\Beam\Helper\AssetsTransfer;
use Heyday\Beam\Helper\DatabaseTransfer;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class DownCommand
 * @package Heyday\Beam\Command
 */
class DownCommand extends TransferCommand
{
    protected function configure()
    {
        parent::configure();
        $this
            ->setName('down')
            ->setDescription('Transfer from a server')
            ->addOption(
                'db',
                null,
                InputOption::VALUE_NONE,
                'Pull the configured database (see servers.<target>.database in beam.json)'
            )
            ->addOption(
                'assets',
                null,
                InputOption::VALUE_NONE,
                'Pull the configured assets directory (see servers.<target>.assets in beam.json)'
            )
            ->addOption(
                'keep-dump',
                null,
                InputOption::VALUE_NONE,
                'With --db, keep the remote dump file after download'
            )
            ->addOption(
                'skip-import',
                null,
                InputOption::VALUE_NONE,
                'With --db, download the dump but skip importCommand'
            )
            ->addOption(
                'delete',
                null,
                InputOption::VALUE_NONE,
                'With --assets, delete local files that are not present remotely'
            );
    }

    /**
     * @return string
     */
    protected function getDirection()
    {
        return 'down';
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pullDb = (bool) $input->getOption('db');
        $pullAssets = (bool) $input->getOption('assets');

        if (!$pullDb && !$pullAssets) {
            return parent::execute($input, $output);
        }

        if (!$input->getArgument('target')) {
            throw new RuntimeException('The "target" argument is required.');
        }

        $target = $input->getArgument('target');
        $server = $this->config['servers'][$target];
        $srcDir = $this->getSrcDir($input);
        $dryRun = (bool) $input->getOption('dry-run');

        $this->assertRsyncServer($server, $target);

        $actions = array_filter([
            $pullDb ? 'database' : null,
            $pullAssets ? 'assets' : null,
        ]);

        $output->writeln(
            $this->formatterHelper->formatSection(
                'warn',
                sprintf(
                    'Pulling %s from <info>%s</info>%s',
                    implode(' and ', $actions),
                    $target,
                    $dryRun ? ' <comment>(dry-run)</comment>' : ''
                ),
                'comment'
            )
        );

        if (!$dryRun && !$input->getOption('no-prompt')) {
            if (!$this->isOkay($input, $output, 'Continue with content pull?')) {
                $this->outputError($output, 'User cancelled');
                return 1;
            }
        }

        try {
            if ($pullDb) {
                if (empty($server['database'])) {
                    throw new InvalidArgumentException(sprintf(
                        'Server "%s" has no "database" config. Add servers.%s.database to beam.json.',
                        $target,
                        $target
                    ));
                }

                (new DatabaseTransfer(
                    $server,
                    $server['database'],
                    $target,
                    $srcDir,
                    $output,
                    $this->formatterHelper
                ))->pull(
                    $dryRun,
                    (bool) $input->getOption('keep-dump'),
                    (bool) $input->getOption('skip-import')
                );
            }

            if ($pullAssets) {
                $assetsConfig = $server['assets'] ?? [
                    'path' => 'public/assets',
                    'localPath' => null,
                    'ensureWritable' => false,
                    'excludes' => [
                        '*__Fill*',
                        '*__Fit*',
                        '*__Resized*',
                        '*__ScaleWidth*',
                        '*__ScaleHeight*',
                    ],
                ];

                (new AssetsTransfer(
                    $server,
                    $assetsConfig,
                    $srcDir,
                    $output,
                    $this->formatterHelper
                ))->pull($dryRun, (bool) $input->getOption('delete'));
            }
        } catch (\Exception $e) {
            if ($output->getVerbosity() === OutputInterface::VERBOSITY_VERBOSE) {
                throw $e;
            }

            $this->outputError($output, $e->getMessage());
            return 1;
        }

        $output->writeln($this->formatterHelper->formatSection('info', 'Done.'));

        return 0;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertRsyncServer(array $server, string $target): void
    {
        $type = $server['type'] ?? 'rsync';
        if ($type !== 'rsync') {
            throw new InvalidArgumentException(sprintf(
                '--db/--assets require an rsync server; "%s" is configured as "%s".',
                $target,
                $type
            ));
        }
    }
}
