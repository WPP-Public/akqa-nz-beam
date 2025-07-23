<?php

namespace Heyday\Beam\Command;

use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class InitCommand
 * @package Heyday\Beam\Command
 */
class InitCommand extends SymfonyCommand
{
    protected function configure()
    {
        $this
            ->setName('init')
            ->setDescription('Generate a beam.json file')
            ->addOption(
                'replace',
                'r',
                InputOption::VALUE_NONE,
                'If set, any existing beam.json files will be overwritten'
            );
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {

        // Check a beam.json file doesn't already exist
        if (file_exists('beam.json') && !$input->getOption('replace')) {
            $output->writeln(
                "<error>Error: beam.json file already exists in this directory, use '-r (or --replace)'
                flag to overwrite file</error>"
            );
            exit;
        }

        $template = [
            'exclude' => [
                'patterns' => [],
            ],
            'servers' => [
                's1'   => [
                    'user'    => '',
                    'host'    => '',
                    'webroot' => '',
                ],
                'live' => [
                    'user'    => '',
                    'host'    => '',
                    'webroot' => '',
                    'branch'  => 'remotes/origin/master'
                ]
            ],
        ];

        // Save template
        file_put_contents('beam.json', json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $output->writeln(
            "<info>Success: beam.json file saved to " . getcwd() .
            "/beam.json - be sure to check the file before using it</info>"
        );
    }
}
