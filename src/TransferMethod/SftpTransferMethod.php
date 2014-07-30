<?php

namespace Heyday\Beam\TransferMethod;

use Heyday\Beam\DeploymentProvider\Sftp;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class SftpTransferMethod
 * @package Heyday\Beam\TransferMethod
 */
class SftpTransferMethod extends TransferMethod
{
    public function getName()
    {
        return 'SFTP';
    }

    public function getInputDefinition()
    {
        return new InputDefinition(array(
            new InputOption(
                'full',
                'f',
                InputOption::VALUE_NONE,
                'Does a more full check on the target, relying less on the checksums file'
            ),
            new InputOption(
                'no-delete',
                '',
                InputOption::VALUE_NONE,
                'Don\'t delete extraneous files on the target'
            )
        ));
    }
    /**
     * @inheritdoc
     */
    public function getOptions(InputInterface $input, OutputInterface $output, $srcDir)
    {
        $options = parent::getOptions($input, $output, $srcDir);
        $options['deploymentprovider'] = new Sftp(
            $input->getOption('full'),
            !$input->getOption('no-delete')
        );

        return $options;
    }
}
