<?php

namespace Heyday\Beam\Command;

use Heyday\Beam\Exception\RuntimeException;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class SelfUpdateCommand
 * @package Heyday\Beam\Command
 */
class SelfUpdateCommand extends SymfonyCommand
{
    protected function configure(): void
    {
        $this
            ->setName('self-update')
            ->setDescription('Update beam')
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Download even if the version is the same'
            )
            ->addOption(
                'host',
                null,
                InputOption::VALUE_REQUIRED,
                'The host to download from',
                'https://getbeam.io'
            );
    }


    /**
     * @param  InputInterface    $input
     * @param  OutputInterface   $output
     * @throws RuntimeException
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = rtrim($input->getOption('host'), '/');
        $url = "$host/beam.phar";
        $version = trim($this->getApplication()->getVersion());

        if ($version === '~package_version~') {
            throw new RuntimeException("This command is only available for compiled phar files which you can obtain at $url");
        }

        $latest = @file_get_contents("$url.version");

        if (false === $latest) {
            throw new RuntimeException(sprintf('Could not fetch latest version. Please try again later.'));
        }

        if ($version !== trim($latest) || $input->getOption('force')) {
            $output->writeln(
                sprintf(
                    'Updating from <info>%s</info> to <info>%s</info>',
                    $version,
                    $latest
                )
            );

            $tmpFile = tempnam(sys_get_temp_dir(), 'beam') . '.phar';

            if (false === @copy($url, $tmpFile)) {
                throw new RuntimeException(sprintf('Could not download latest version. Please try again later.'));
            }

            $phar = new \Phar($tmpFile);

            unset($phar);

            $permissions = fileperms($_SERVER['argv'][0]);

            if (false === @rename($tmpFile, $_SERVER['argv'][0])) {
                throw new RuntimeException(sprintf('Could not deploy new file to "%s".', $_SERVER['argv'][0]));
            }

            chmod($_SERVER['argv'][0], $permissions);

            $output->writeln('Beam updated');
        } else {
            $output->writeln('You are already using the latest version');
        }

        return 0;
    }
}
