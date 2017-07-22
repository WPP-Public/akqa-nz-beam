<?php

namespace Heyday\Beam\TransferMethod;

use Heyday\Beam\DeploymentProvider\DeploymentResult;
use Heyday\Beam\DeploymentProvider\Rsync;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class RsyncTransferMethod
 * @package Heyday\Beam\TransferMethod
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
}
