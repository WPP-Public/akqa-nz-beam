<?php

namespace Heyday\Component\Beam\Command;

use Heyday\Component\Beam\Compiler;
use Ssh\Session;
use Ssh\SshConfigFileConfiguration;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CompileCommand extends SymfonyCommand
{
    protected function configure()
    {
        $this
            ->setName('compile')
            ->setDescription('Compile')
            ->addOption(
                'host',
                null,
                InputOption::VALUE_REQUIRED,
                'The host to deploy to',
                'dev2.heyday.net.nz'
            )->addOption(
                'user',
                null,
                InputOption::VALUE_REQUIRED,
                'The user to deploy with',
                'dev'
            )->addOption(
                'path',
                null,
                InputOption::VALUE_REQUIRED,
                'The path to deploy to',
                '/home/dev/subdomains/heyday/beam'
            )->addOption(
                'skipcleanup',
                null,
                InputOption::VALUE_NONE
            )->addOption(
                'skipdeploy',
                null,
                InputOption::VALUE_NONE
            );
    }
    /**
     * @param  InputInterface  $input
     * @param  OutputInterface $output
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $compiler = new Compiler();
            $output->writeln('Compiling');
            $output->writeln($compiler->compile());
            $rootdir = realpath(__DIR__ . '/../../../../../');

            if (!$input->getOption('skipdeploy')) {
                $host = $input->getOption('host');
                $path = $input->getOption('path');
                $output->writeln('Publishing');
                $configuration = new SshConfigFileConfiguration(
                    '~/.ssh/config',
                    $host
                );
                $session = new Session(
                    $configuration,
                    $configuration->getAuthentication(
                        null,
                        $input->getOption('user')
                    )
                );
                $sftp = $session->getSftp();
                $output->writeln(
                    sprintf(
                        'Sending beam.phar to %s',
                        $host
                    )
                );
                $sftp->send($rootdir . '/beam.phar', $path . '/beam.phar');
                $output->writeln(
                    sprintf(
                        'Sending beam.phar.version to %s',
                        $host
                    )
                );
                $sftp->send($rootdir . '/beam.phar.version', $path . '/beam.phar.version');

            }

            if (!$input->getOption('skipcleanup')) {
                unlink($rootdir . '/beam.phar');
                unlink($rootdir . '/beam.phar.version');
            }
        } catch (\Exception $e) {
            $output->writeln('Failed to compile phar: ['.get_class($e).'] '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine());
            exit(1);
        }
    }
}
