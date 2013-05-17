<?php

namespace Heyday\Component\Beam\Command;

use Heyday\Component\Beam\Deployment\DeploymentResult;
use Heyday\Component\Beam\Deployment\Rsync;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\ProgressHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class RsyncCommand
 * @package Heyday\Component\Beam\Command
 */
class RsyncCommand extends BeamCommand
{
    /**
     *
     */
    protected function configure()
    {
        parent::configure();
        $this
            ->setName('rsync')
            ->setDescription('A deployment tool using rsync')
            ->addOption(
                'no-checksum',
                '',
                InputOption::VALUE_NONE,
                'Performs a faster file change check'
            )
            ->addOption(
                'delete',
                '',
                InputOption::VALUE_NONE,
                'Use with caution, deletes items that don\'t exist at the target'
            )
            ->addOption(
                'no-compress',
                '',
                InputOption::VALUE_NONE,
                'Removes compression'
            )
            ->addOption(
                'no-delay-updates',
                '',
                InputOption::VALUE_NONE,
                'Transfers as it runs not all at the end'
            );
    }
    /**
     * @param  InputInterface $input
     * @return array
     */
    protected function getOptions(InputInterface $input, OutputInterface $output)
    {
        $options = parent::getOptions($input, $output);
        $options['deploymentprovider'] = new Rsync(
            array(
                'checksum'      => !$input->getOption('no-checksum'),
                'delete'        => $input->getOption('delete'),
                'compress'      => !$input->getOption('no-compress'),
                'delay-updates' => !$input->getOption('no-delay-updates')
            )
        );

        return $options;
    }
    /**
     * @{inheritDoc}
     */
    protected function getDeploymentOutputHandler(
        OutputInterface $output,
        ProgressHelper $progressHelper,
        FormatterHelper $formatterHelper,
        DeploymentResult $deploymentResult
    ) {
        $count = count($deploymentResult);

        return function (
            $type,
            $data
        ) use (
            $output,
            $progressHelper,
            $formatterHelper,
            $count,
            $deploymentResult
        ) {
            static $totalSteps = 0;
            if ($totalSteps == 0) {
                $progressHelper->setAutoWidth($count);
                // Start the progress bar
                $progressHelper->start($output, $count, 'File: ');
            }
            if ($type == 'out') {
                // Advance 1 for each line we get in the data
                $steps = substr_count($data, PHP_EOL);
                // We call advance once per step as opposed to all steps at once
                // so the redrawFrequency can be applied correctly
                for ($i = 0; $i < $steps; $i++) {
                    $progressHelper->advance(1, false, $deploymentResult[$totalSteps + $i]['filename']);
                }
                $totalSteps += $steps;
                // Check if we have finished (rsync stops outputing data
                // before things have entirely finished)
                if ($totalSteps >= $count) {
                    $progressHelper->finish();
                    $output->writeln(
                        $formatterHelper->formatSection(
                            'info',
                            'Finalizing deployment'
                        )
                    );
                }
            } elseif ($type == 'err') {
                $output->write(
                    $formatterHelper->formatSection(
                        'error',
                        $data,
                        'error'
                    )
                );
            }
        };
    }
}
