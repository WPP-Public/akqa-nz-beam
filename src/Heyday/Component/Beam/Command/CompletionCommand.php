<?php

namespace Heyday\Component\Beam\Command;

use Heyday\Component\Beam\CompletionHandler;
use Heyday\Component\Beam\Application;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CompletionCommand extends SymfonyCommand {

    protected function configure()
    {
        $this
            ->setName('completion')
            ->setDescription('BASH completion hook.')
            ->setHelp('To enable BASH completion, run: <comment>eval `beam completion --genhook`</comment>')
            ->addOption(
                'genhook',
                null,
                InputOption::VALUE_NONE,
                'Generate BASH script to enable completion using this command'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $app = new Application();
        $handler = new CompletionHandler($app);

        if ($input->getOption('genhook')) {
            $bash = $handler->generateBashCompletionHook();
            $output->write($bash, true);
        } else {
            $handler->configureFromEnvironment();
            $output->write($this->doCompletion($handler, $app), true);
        }
    }



    protected function doCompletion(CompletionHandler $handler, $app)
    {
        $handler->setArgumentHandlers(array(
            'direction' => array('up', 'down'),

            'target' => function(){
                // TODO: Actually parse config
                return array('live', 's1', 's2', 's3');
            },

            'ref' => function(){
                $raw = shell_exec('git show-ref --abbr');
                if (preg_match_all('/refs\/(?:heads|tags)?\/?(.*)/', $raw, $matches)) {
                    return $matches[1];
                }
            }
        ));

        return $handler->runCompletion();
    }

}