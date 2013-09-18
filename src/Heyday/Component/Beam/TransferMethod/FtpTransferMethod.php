<?php

namespace Heyday\Component\Beam\TransferMethod;

use Heyday\Component\Beam\DeploymentProvider\Ftp;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class FtpTransferMethod
 * @package Heyday\Component\Beam\TransferMethod
 */
class FtpTransferMethod extends TransferMethod
{
    public function getName()
    {
        return 'FTP';
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
            ),
            new InputOption(
                'ssl',
                's',
                InputOption::VALUE_NONE,
                'Use ssl (ftps)'
            )
        ));
    }
    /**
     * @inheritdoc
     */
    public function getOptions(InputInterface $input, OutputInterface $output, $srcDir)
    {
        $options = parent::getOptions($input, $output, $srcDir);
        $options['deploymentprovider'] = new Ftp(
            $input->getOption('full'),
            !$input->getOption('no-delete'),
            $input->getOption('ssl')
        );

        return $options;
    }
}
