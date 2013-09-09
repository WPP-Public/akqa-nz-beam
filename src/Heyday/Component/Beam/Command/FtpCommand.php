<?php

namespace Heyday\Component\Beam\Command;

use Heyday\Component\Beam\DeploymentProvider\Ftp;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class FtpCommand
 * @package Heyday\Component\Beam\Command
 */
class FtpCommand extends BeamCommand
{
    /**
     *
     */
    protected function configure()
    {
        parent::configure();
        $this
            ->setName('ftp')
            ->setDescription('A file upload/download tool that utilises ftp and git')
            ->addOption(
                'full',
                'f',
                InputOption::VALUE_NONE,
                'Does a more full check on the target, relying less on the checksums file'
            )->addOption(
                'no-delete',
                '',
                InputOption::VALUE_NONE,
                'Don\'t delete extraneous files on the target'
            )->addOption(
                'ssl',
                's',
                InputOption::VALUE_NONE,
                'Use ssl (ftps)'
            );
    }
    /**
     * @param \Symfony\Component\Console\Input\InputInterface   $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return array
     */
    protected function getOptions(InputInterface $input, OutputInterface $output)
    {
        $options = parent::getOptions($input, $output);
        $options['deploymentprovider'] = new Ftp(
            $input->getOption('full'),
            !$input->getOption('no-delete'),
            $input->getOption('ssl')
        );

        return $options;
    }
}
