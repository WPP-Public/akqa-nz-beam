<?php

namespace Heyday\Component\Beam\Command;

use Heyday\Component\Beam\DeploymentProvider\Sftp;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class SftpCommand
 * @package Heyday\Component\Beam\Command
 */
class SftpCommand extends BeamCommand
{
    /**
     *
     */
    protected function configure()
    {
        parent::configure();
        $this
            ->setName('sftp')
            ->setDescription('A file upload/download tool that utilises sftp and git')
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
            );
    }
    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @return array
     */
    protected function getOptions(InputInterface $input, OutputInterface $output)
    {
        $options = parent::getOptions($input, $output);
        $options['deploymentprovider'] = new Sftp(
            $input->getOption('full'),
            !$input->getOption('no-delete')
        );

        return $options;
    }
}
