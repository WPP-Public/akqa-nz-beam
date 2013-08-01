<?php

namespace Heyday\Component\Beam\Command;

use Heyday\Component\Beam\Config\JsonConfigLoader;
use Stecman\Component\Symfony\Console\BashCompletion\Completion;
use Stecman\Component\Symfony\Console\BashCompletion\CompletionCommand;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * Class BeamCompletionCommand
 * @package Heyday\Component\Beam\Command
 */
class BeamCompletionCommand extends CompletionCommand
{
    /**
     * @return string
     */
    protected function runCompletion()
    {
        $handler = $this->handler;
        $app = $this->getApplication();

        // Manipulate input values so beam's single command mode works
        $handler->configureWithArray($this->getHandlerConfiguration());

        try {
            $config = $this->getConfig();
        } catch (\Exception $e) {
            $config = null;
        }

        // Get the real second word
        $realCommandName = $this->getInputCommandName();

        // Add argument handlers
        $handler->addHandlers(
            array(
                Completion::makeGlobalHandler(
                    'direction',
                    Completion::TYPE_ARGUMENT,
                    function () use ($app, $realCommandName) {
                        $values = array('up', 'down');

                        // Fix for single command mode
                        foreach ($app->all() as $cmd) {
                            $name = $cmd->getName();

                            if ($realCommandName == $name) {
                                return array('up', 'down');
                            }

                            if ($name == '_completion') {
                                continue;
                            }

                            $values[] = $name;
                        }

                        return $values;
                    }
                ),
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
     * Get a modified version of the bash completion variables to patch for beam's single command mode
     * @return array
     * @throws \RuntimeException
     */
    protected function getHandlerConfiguration()
    {
        $commandLine = getenv('COMP_LINE');
        $wordIndex = intval(getenv('COMP_CWORD'));
        $charIndex = intval(getenv('COMP_POINT'));
        $breaks = preg_quote(getenv('COMP_WORDBREAKS'));

        if ($commandLine === false) {
            throw new \RuntimeException('Failed to configure from environment; Environment var COMP_LINE not set');
        }

        // Inject rsync as the command if another command is not specified
        $cmdNames = '';
        foreach ($this->getApplication()->all() as $cmd) {
            $cmdNames .= $cmd->getName() . '|';
        }
        $cmdNames = trim($cmdNames, '|');

        if ($commandLine = preg_replace("/^([a-zA-Z\-_0-9]+) (?!$cmdNames)/", '${1} rsync ', $commandLine, 1, $count)) {
            if ($count == 1) {
                $charIndex += 6;
                $wordIndex++;
            }
        }

        $words = array_filter(
            preg_split("/[$breaks]+/", $commandLine),
            function ($val) {
                return $val != ' ';
            }
        );

        return array(
            'commandLine' => $commandLine,
            'wordIndex'   => $wordIndex,
            'charIndex'   => $charIndex,
            'words'       => $words
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
