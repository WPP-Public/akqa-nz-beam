<?php

namespace Heyday\Component\Beam\Command;

use Heyday\Component\Beam\Config\JsonConfigLoader;
use Stecman\Component\Symfony\Console\BashCompletion\Completion;
use Stecman\Component\Symfony\Console\BashCompletion\CompletionCommand;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputOption;

/**
 * Class BeamCompletionCommand
 * @package Heyday\Component\Beam\Command
 */
class BeamCompletionCommand extends CompletionCommand
{
    protected function configure()
    {
        parent::configure();
    }

    /**
     * @return string
     */
    protected function runCompletion()
    {
        $application = $this->getApplication();
        $handler = $this->handler;
        $handler->configureFromEnvironment();

        // Trigger the merging of TransferMethod definition
        $commandName = $handler->getInput()->getFirstArgument();
        if($application->has($commandName)){
            $command = $application->get($commandName);

            if ($command instanceof TransferCommand) {

                try {
                    $words = $handler->getWords();
                    unset($words[$handler->getWordIndex()]);
                    array_shift($words);
                    $input = new ArgvInput($words, $command->getDefinition());
                } catch (\RuntimeException $e) {
                    // Input isn't parsable
                }

                if (isset($input)) {
                    $command->guessTarget($input);
                }
            }
        }

        try {
            $config = $this->getConfig();
        } catch (\Exception $e) {
            $config = null;
        }

        // Add argument handlers
        $handler->addHandlers(
            array(
                Completion::makeGlobalHandler(
                    'target',
                    Completion::TYPE_ARGUMENT,
                    function () use ($config) {
                        if (!$config) {
                            return null;
                        } elseif (is_array($config['servers'])) {
                            return array_keys($config['servers']);
                        }
                    }
                )
            )
        );

        // Add option handlers
        $handler->addHandlers(
            array(
                Completion::makeGlobalHandler(
                    'ref',
                    Completion::TYPE_OPTION,
                    function () {
                        $raw = shell_exec('git show-ref --abbr');
                        if (preg_match_all('/refs\/(?:heads|tags)?\/?(.*)/', $raw, $matches)) {
                            return $matches[1];
                        }
                    }
                ),
                Completion::makeGlobalHandler(
                    'tags',
                    Completion::TYPE_OPTION,
                    function () use ($config) {
                        if (!$config) {
                            return;
                        }

                        if (isset($config['commands']) && is_array($config['commands'])) {

                            $tags = array();
                            foreach ($config['commands'] as $command) {
                                if (isset($command['tag'])) {
                                    $tags[] = $command['tag'];
                                }
                            }

                            return $tags;
                        }
                    }
                )

            )
        );

        return $handler->runCompletion();
    }

    /**
     * Find and parse the beam config file as JSON
     * @return array
     */
    protected function getConfig()
    {
        $path = getcwd();
        $paths = array();

        while ($path !== end($paths)) {
            $paths[] = $path;
            $path = dirname($path);
        }

        $jsonConfigLoader = new JsonConfigLoader(
            new FileLocator($paths)
        );

        $words = $this->handler->getWords();
        array_shift($words);
        $input = new ArrayInput($words);

        return $jsonConfigLoader->load(
            $input->hasOption('config-file') ? $input->getOption('config-file') : 'beam.json'
        );
    }

    /**
     * @return mixed
     */
    protected function getInputCommandName()
    {
        $commandLine = getenv('COMP_LINE');
        if (preg_match('/[a-zA-Z\-_0-9]+ ([a-zA-Z\-_0-9]+)/', $commandLine, $matches)) {
            return $matches[1];
        }
    }
}
