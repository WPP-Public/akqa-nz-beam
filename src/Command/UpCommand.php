<?php

namespace Heyday\Beam\Command;

use Heyday\Beam\Exception\InvalidArgumentException;
use Heyday\Beam\Exception\RuntimeException;
use Heyday\Beam\Helper\AssetsTransfer;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class UpCommand
 * @package Heyday\Beam\Command
 */
class UpCommand extends TransferCommand
{
    protected function configure()
    {
        parent::configure();
        $this
            ->setName('up')
            ->setDescription('Transfer to a server')
            ->addOption(
                'assets',
                null,
                InputOption::VALUE_NONE,
                'Push the configured assets directory (see servers.<target>.assets in beam.json)'
            )
            ->addOption(
                'delete',
                null,
                InputOption::VALUE_NONE,
                'With --assets, delete remote files that are not present locally'
            );
    }

    /**
     * @return string
     */
    protected function getDirection(): string
    {
        return 'up';
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('assets')) {
            return parent::execute($input, $output);
        }

        if (!$input->getArgument('target')) {
            throw new RuntimeException('The "target" argument is required.');
        }

        $target = $input->getArgument('target');
        $server = $this->config['servers'][$target];
        $srcDir = $this->getSrcDir($input);
        $dryRun = (bool) $input->getOption('dry-run');

        $type = $server['type'] ?? 'rsync';
        if ($type !== 'rsync') {
            throw new InvalidArgumentException(sprintf(
                '--assets requires an rsync server; "%s" is configured as "%s".',
                $target,
                $type
            ));
        }

        $output->writeln(
            $this->formatterHelper->formatSection(
                'warn',
                sprintf(
                    'Pushing assets to <info>%s</info>%s',
                    $target,
                    $dryRun ? ' <comment>(dry-run)</comment>' : ''
                ),
                'comment'
            )
        );

        if (!$dryRun && !$input->getOption('no-prompt')) {
            if (!$this->isOkay($input, $output, sprintf('Push assets to %s?', $target))) {
                $this->outputError($output, 'User cancelled');
                return 1;
            }
        }

        try {
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
            ))->push($dryRun, (bool) $input->getOption('delete'));
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
}
