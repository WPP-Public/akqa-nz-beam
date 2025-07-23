<?php

namespace Heyday\Beam\Command;

use Heyday\Beam\Config\JsonConfigLoader;
use Stecman\Component\Symfony\Console\BashCompletion\Completion;
use Stecman\Component\Symfony\Console\BashCompletion\CompletionCommand;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * Class BeamCompletionCommand
 * @package Heyday\Beam\Command
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
        $context = $this->handler->getContext();

        // Trigger the merging of TransferMethod definition
        $commandName = $handler->getInput()->getFirstArgument();
        if ($application->has($commandName)) {
            $command = $application->get($commandName);

            if ($command instanceof TransferCommand) {
                try {
                    $words = $context->getWords();
                    unset($words[$context->getWordIndex()]);
                    array_shift($words);
                    $input = new ArgvInput($words, $command->getDefinition());
                } catch (\RuntimeException $e) {
                    // Input isn't parsable - ignore
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
            $projectPath = null;
        }

        // Add argument handlers
        $handler->addHandlers(
            [
                new Completion(
                    Completion::ALL_COMMANDS,
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
            ]
        );

        // Add option handlers
        $handler->addHandlers(
            [
                new Completion(
                    Completion::ALL_COMMANDS,
                    'ref',
                    Completion::TYPE_OPTION,
                    function () {
                        $raw = shell_exec('git show-ref --abbr');
                        if (preg_match_all('/refs\/(?:heads|tags)?\/?(.*)/', $raw, $matches)) {
                            return $matches[1];
                        }
                    }
                ),
                new Completion(
                    Completion::ALL_COMMANDS,
                    'tags',
                    Completion::TYPE_OPTION,
                    function () use ($config) {
                        if (!$config) {
                            return;
                        }

                        if (isset($config['commands']) && is_array($config['commands'])) {
                            $tags = [];
                            foreach ($config['commands'] as $command) {
                                if (isset($command['tag'])) {
                                    $tags[] = $command['tag'];
                                }
                            }

                            return $tags;
                        }
                    }
                ),
                new Completion\ShellPathCompletion(
                    Completion::ALL_COMMANDS,
                    'path',
                    Completion::TYPE_OPTION
                )
            ]
        );

        return $handler->runCompletion();
    }

    /**
     * Find and parse the beam config file as JSON
     * @return array
     */
    protected function getConfig()
    {
        $jsonConfigLoader = new JsonConfigLoader(
            $this->getFileLocator()
        );

        $words = $this->handler->getContext()->getWords();
        array_shift($words);
        $input = new ArrayInput($words);

        return $jsonConfigLoader->load(
            $input->hasOption('config-file') ? $input->getOption('config-file') : 'beam.json'
        );
    }

    /**
     * @return FileLocator
     */
    protected function getFileLocator()
    {
        $path = getcwd();
        $paths = [];

        while ($path !== end($paths)) {
            $paths[] = $path;
            $path = dirname($path);
        }

        return new FileLocator($paths);
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
