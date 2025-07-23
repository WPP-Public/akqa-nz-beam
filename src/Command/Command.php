<?php

namespace Heyday\Beam\Command;

use Heyday\Beam\Config\JsonConfigLoader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Command\Command as BaseCommand;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class Command
 * @package Heyday\Beam\Command
 */
abstract class Command extends BaseCommand
{
    /**
     * @var \Symfony\Component\Console\Helper\FormatterHelper
     */
    protected $formatterHelper;

    /**
     * @var JsonConfigLoader|null
     */
    protected $jsonConfigLoader;
    /**
     * @param null $name
     */
    public function __construct($name = null)
    {
        $this->formatterHelper = new FormatterHelper();
        parent::__construct($name);
    }

    /**
     * @param $path
     * @return JsonConfigLoader
     */
    protected function getJsonConfigLoader($path = null)
    {
        if (null === $this->jsonConfigLoader) {
            $path = $path ?: getcwd();
            $paths = [];

            while ($path !== end($paths)) {
                $paths[] = $path;
                $path = dirname($path);
            }

            $this->jsonConfigLoader = new JsonConfigLoader(
                new FileLocator(
                    $paths
                )
            );
        }

        return $this->jsonConfigLoader;
    }

    /**
     * @param  InputInterface $input
     * @param null $path
     * @return mixed
     */
    protected function getConfig(InputInterface $input, $path = null)
    {
        return $this->getJsonConfigLoader($path)->load(
            $input->getOption('config-file')
        );
    }
    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @internal param $configFile
     * @return string
     */
    protected function getSrcDir(InputInterface $input)
    {
        return dirname(
            $this->getJsonConfigLoader()->locate(
                $input->getOption('config-file')
            )
        );
    }
    /**
     * @return $this
     */
    protected function addConfigOption()
    {
        $this->addOption(
            'config-file',
            '',
            InputOption::VALUE_REQUIRED,
            'The config file name',
            'beam.json'
        );

        return $this;
    }
    /**
     * @param OutputInterface $output
     * @param                 $error
     * @return void
     */
    public function outputError(OutputInterface $output, $error)
    {
        $output->writeln(
            $this->formatterHelper->formatSection(
                'error',
                sprintf(
                    '<error>%s</error>',
                    $error
                ),
                'error'
            )
        );
    }
    /**
     * @param OutputInterface $output
     * @param                 $message
     * @param                 $section
     * @param                 $style
     * @return void
     */
    protected function outputMultiline(OutputInterface $output, $message, $section, $style)
    {
        foreach (explode(PHP_EOL, $message) as $line) {
            $output->writeln(
                $this->formatterHelper->formatSection(
                    $section,
                    $line,
                    $style
                )
            );
        }
    }
}
