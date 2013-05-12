<?php

namespace Heyday\Component\Beam\Command;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressHelper;
use Symfony\Component\Console\Helper\FormatterHelper;
use Heyday\Component\Beam\Deployment\DeploymentResult;

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
            ->setDescription('A file upload/download tool that utilises rsync and git');
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
