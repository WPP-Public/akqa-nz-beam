<?php

namespace Heyday\Component\Beam\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Heyday\Component\Beam\Deployment\Ftp;

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
            );
    }
    /**
     * @param  InputInterface $input
     * @return array
     */
    protected function getOptions(InputInterface $input)
    {
        $options = parent::getOptions($input);
        $options['deploymentprovider'] = new Ftp($input->getOption('full'));

        return $options;
    }
}
