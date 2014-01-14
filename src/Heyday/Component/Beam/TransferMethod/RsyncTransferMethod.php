<?php

namespace Heyday\Component\Beam\TransferMethod;

use Heyday\Component\Beam\DeploymentProvider\DeploymentResult;
use Heyday\Component\Beam\DeploymentProvider\Rsync;
use Symfony\Component\Console\Helper\ProgressHelper;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class RsyncTransferMethod
 * @package Heyday\Component\Beam\TransferMethod
 */
class RsyncTransferMethod extends TransferMethod
{
    /**
     * @var Rsync
     */
    protected $deploymentProvider;

    public function getName()
    {
        return 'Rsync';
    }

    public function getInputDefinition()
    {
        return new InputDefinition(array(
            new InputOption(
                'no-checksum',
                '',
                InputOption::VALUE_NONE,
                'Performs a faster file change check'
            ),
            new InputOption(
                'no-delete',
                '',
                InputOption::VALUE_NONE,
                'Don\'t delete extraneous files on the target'
            ),
            new InputOption(
                'no-compress',
                '',
                InputOption::VALUE_NONE,
                'Removes compression'
            ),
            new InputOption(
                'no-delay-updates',
                '',
                InputOption::VALUE_NONE,
                'Updates files directly on target <comment>(use if disk space is limited)</comment>'
            ),
            new InputOption(
                'args',
                '',
                InputOption::VALUE_REQUIRED,
                'Additional arguments for rsync'
            )
        ));
    }

    /**
     * @inheritdoc
     */
    public function getOptions(InputInterface $input, OutputInterface $output, $srcDir)
    {
        $options = parent::getOptions($input, $output, $srcDir);
        $options['deploymentprovider'] = $this->deploymentProvider = new Rsync(
            array(
                'checksum'      => !$input->getOption('no-checksum'),
                'delete'        => !$input->getOption('no-delete'),
                'compress'      => !$input->getOption('no-compress'),
                'delay-updates' => !$input->getOption('no-delay-updates'),
                'args'          => $input->getOption('args') ?: ''
            )
        );

        return $options;
    }
    /**
     * @{inheritDoc}
     */
    protected function getDeploymentOutputHandler(OutputInterface $output, DeploymentResult $deploymentResult)
    {
        $count = count($deploymentResult);
        $deploymentProvider = $this->deploymentProvider;
        $progressHelper = $this->progressHelper;
        $formatterHelper = $this->formatterHelper;

        return function (
            $type,
            $data
        ) use (
            $output,
            $progressHelper,
            $formatterHelper,
            $count,
            $deploymentProvider
        ) {
            static $totalSteps = 0;
            static $buffer = '';
            if ($totalSteps == 0) {
                $progressHelper->setAutoWidth($count);
                // Start the progress bar
                $progressHelper->start($output, $count, 'File: ');
            }
            if ($type == 'out') {
                // add the current data to the buffer
                $buffer .= $data;
                // get the pos of any last newline
                $pos = strrpos($buffer, PHP_EOL);
                // there isn't a last newline then skip and continue filling the buffer
                if ($pos !== false) {
                    // get the changes from the start of the buffer to the last newline
                    $changes = $deploymentProvider->formatOutput(substr($buffer, 0, $pos));
                    foreach ($changes as $change) {
                        // update the progress bar and increment the steps
                        $progressHelper->advance(1, false, $change['filename']);
                        $totalSteps++;
                    }
                    // clear the processed buffer keeping anything after the last newline
                    $buffer = ltrim(substr($buffer, $pos), PHP_EOL);
                }
                // Check if we have finished (rsync stops outputing data
                // before things have entirely finished)
                if ($totalSteps >= $count) {
                    $progressHelper->finish();
                    $output->writeln(
                        $formatterHelper->formatSection(
                            'info',
                            'Finalising deployment'
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
