<?php

namespace Heyday\Component\Beam\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class ValidateCommand
 * @package Heyday\Component\Beam\Command
 */
class ValidateCommand extends Command
{
    /**
     *
     */
    protected function configure()
    {
        $this->setName('validate')
            ->addConfigOption()
            ->setDescription('Validate the nearest beam.json file');
    }
    /**
     * @param  InputInterface  $input
     * @param  OutputInterface $output
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $this->getConfig($input);
            $configPath = $this->getConfigPath($input);

            $output->writeln(
                array(
                    $this->formatterHelper->formatSection(
                        'info',
                        "Schema valid in <comment>$configPath</comment>",
                        'info'
                    )
                )
            );

        } catch (\Exception $e) {
            $this->outputError($output, $e->getMessage());
        }
    }

    protected function getConfigPath(InputInterface $input)
    {
        return $this->getJsonConfigLoader()->locate(
            $input->getOption('config-file')
        );
    }

}
