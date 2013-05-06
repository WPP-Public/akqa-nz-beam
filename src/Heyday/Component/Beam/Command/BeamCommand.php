<?php

namespace Heyday\Component\Beam\Command;

use Heyday\Component\Beam\Beam;
use Heyday\Component\Beam\Config\JsonConfigLoader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class BeamCommand
 * @package Heyday\Component\Beam\Command
 */
class BeamCommand extends Command
{
    /**
     * @var
     */
    protected $jsonConfigLoader;

    /**
     *
     */
    protected function configure()
    {
        $this
            ->setName('beam')
            ->setDescription('A file upload/download tool that utilises rsync and git')
            ->addArgument(
                'direction',
                InputArgument::REQUIRED,
                'Valid values are \'up\' or \'down\''
            )
            ->addArgument(
                'remote',
                InputArgument::REQUIRED,
                'Config name of remote location to be beamed from or to'
            )
            ->addOption(
                'branch',
                'b',
                InputOption::VALUE_REQUIRED,
                'The branch to be beamed up'
            )
            ->addOption(
                'path',
                'p',
                InputOption::VALUE_REQUIRED,
                'The path to be beamed up or down'
            )
            ->addOption(
                'dryrun',
                'd',
                InputOption::VALUE_NONE,
                'If set, no files will be transferred'
            )
            ->addOption(
                'noprompt',
                '',
                InputOption::VALUE_NONE,
                'Skips the pre-sync check and prompt'
            )
            ->addOption(
                'nochecksum',
                '',
                InputOption::VALUE_NONE,
                'Performs a faster file change check'
            )
            ->addOption(
                'delete',
                '',
                InputOption::VALUE_NONE,
                'USE WITH CAUTION!! adds the delete flag to remove items that don\'t exist at the destination'
            )
            ->addOption(
                'workingcopy',
                '',
                InputOption::VALUE_NONE,
                'When uploading, syncs files from the working copy rather than exported git copy'
            )
            ->addOption(
                'configfile',
                '',
                InputOption::VALUE_REQUIRED,
                'The config file name',
                'beam.json'
            )
            ->addOption(
                'exportdir',
                '',
                InputOption::VALUE_REQUIRED,
                'The export directory name'
            );
    }
    /**
     * @param  InputInterface  $input
     * @param  OutputInterface $output
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $helperset = $this->getHelperSet();
        $formatterHelper = $helperset->get('formatter');
        $progressHelper = $helperset->get('progress');
        $progressHelper->setFormat('[%bar%] %percent%% %elapsed%');
        $progressHelper->setBarWidth(50);
        $changesHelper = $helperset->get('changes');
        $dialogHelper = $helperset->get('dialog');

        try {

            $options = $this->getOptions($input);

            $options['commandoutputhandler'] = function ($type, $data) use ($output, $formatterHelper) {
                if ($type == 'out') {
                    $output->write(
                        $formatterHelper->formatSection(
                            'command',
                            $data
                        )
                    );
                } elseif ($type == 'err') {
                    $output->write(
                        $formatterHelper->formatSection(
                            'error',
                            $data,
                            'error'
                        )
                    );
                }
            };

            $beam = new Beam(
                array(
                    $this->getConfig($input)
                ),
                $options
            );

            $this->outputSummary(
                $output,
                $formatterHelper,
                $beam
            );

            if (!$this->isOkay($output, $dialogHelper, $formatterHelper)) {
                throw new \RuntimeException('User canceled');
            }

            $output->writeln(
                $formatterHelper->formatSection(
                    'warn',
                    'Determining list of files that will be modified...',
                    'comment'
                )
            );

            // Prompt the user with the affected files and a confirmation dialog
            if (!$input->getOption('noprompt')) {
                // Get the affected files
                $changedFiles = $beam->getChangedFiles();
                // If there are any show them
                $count = count($changedFiles);
                // If there is more that 1 item there are updates,
                // If there is 1 and it is nochange treat it as no update
                if ($count > 1 || (isset($changedFiles[0]) && $changedFiles[0]['update'] != 'nochange')) {
                    // Output all the changes
                    $changesHelper->outputChanges($formatterHelper, $output, $changedFiles);
                    // Output a summary of the changes
                    $changesHelper->outputChangesSummary($formatterHelper, $output, $changedFiles);
                    // If we have confirmation do the beam
                    if ($this->isOkay($output, $dialogHelper, $formatterHelper)) {
                        // Set the frequency of redraws to be a unit that produces 100 updates or less
                        $progressHelper->setRedrawFrequency(
                            max(
                                floor($count / 100),
                                1
                            )
                        );
                        $progressHelper->start($output, $count);

                        $beam->setOption(
                            'deploymentoutputhandler',
                            function (
                                $type,
                                $data
                            ) use (
                                $output,
                                $progressHelper,
                                $formatterHelper,
                                $count
                            ) {
                                static $totalSteps = 0;
                                if ($type == 'out') {
                                    // Advance 1 for each line we get in the data
                                    $steps = substr_count($data, PHP_EOL);
                                    // We call advance once per step as opposed to all steps at once
                                    // so the redrawFrequency can be applied correctly
                                    for ($i = 0; $i < $steps; $i++) {
                                        $progressHelper->advance();
                                    }
                                    $totalSteps += $steps;
                                    // Check if we have finished (rsync stops outputing data
                                    // before things have entirely finished)
                                    if ($totalSteps >= $count) {
                                        $progressHelper->finish();
                                        $output->writeln(
                                            $formatterHelper->formatSection(
                                                'info',
                                                'Finalizing deployment'
                                            )
                                        );
                                    }
                                } elseif ($type == 'err') {
                                    $output->write(
                                        $formatterHelper->formatSection(
                                            'error',
                                            $data,
                                            'error'
                                        )
                                    );
                                }
                            }
                        );

                        $changedFiles = $beam->run();

                        $changesHelper->outputChangesSummary(
                            $formatterHelper,
                            $output,
                            $changedFiles
                        );
                    } else {
                        throw new \RuntimeException('User canceled');
                    }
                } else {
                    throw new \RuntimeException('No changed files');
                }
            } else {
                $changesHelper->outputChangesSummary(
                    $formatterHelper,
                    $output,
                    $beam->run()
                );
            }

        } catch (\Exception $e) {
            $this->outputError(
                $output,
                $formatterHelper,
                $e->getMessage()
            );
        }

    }
    /**
     * @param  InputInterface $input
     * @return mixed
     */
    protected function getConfig(InputInterface $input)
    {
        return $this->getJsonConfigLoader()->load(
            $input->getOption('configfile')
        );
    }
    /**
     * @param  InputInterface $input
     * @return array
     */
    protected function getOptions(InputInterface $input)
    {
        $options = array(
            'direction' => $input->getArgument('direction'),
            'remote'    => $input->getArgument('remote')
        );

        if ($input->getOption('branch')) {
            $options['branch'] = $input->getOption('branch');
        }
        if ($input->getOption('path')) {
            $options['path'] = $input->getOption('path');
        }
        if ($input->getOption('exportdir')) {
            $options['exportdir'] = $input->getOption('exportdir');
        }
        if ($input->getOption('dryrun')) {
            $options['dry-run'] = true;
        }
        if ($input->getOption('nochecksum')) {
            $options['checksum'] = false;
        }
        if ($input->getOption('delete')) {
            $options['delete'] = true;
        }
        if ($input->getOption('workingcopy')) {
            $options['workingcopy'] = true;
        }

        $options['srcdir'] = dirname(
            $this->getJsonConfigLoader()->locate(
                $input->getOption('configfile')
            )
        );

        return $options;
    }
    /**
     * @return JsonLoader
     */
    protected function getJsonConfigLoader()
    {
        if (null === $this->jsonConfigLoader) {

            $paths = array();
            $path = getcwd();

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
     * @param         $question
     * @param  null   $default
     * @return string
     */
    protected function getQuestion($question, $default = null)
    {
        if ($default !== null) {
            return sprintf(
                '<question>%s</question> [<comment>%s</comment>]: ',
                $question,
                $default
            );
        } else {
            return sprintf(
                '<question>%s</question>: ',
                $question
            );
        }
    }
    /**
     * @param OutputInterface $output
     * @param                 $formatterHelper
     * @param                 $beam
     */
    protected function outputSummary(OutputInterface $output, $formatterHelper, $beam)
    {
        $output->writeln(
            array(
                $formatterHelper->formatSection(
                    'warn',
                    'Starting...',
                    'comment'
                ),
                $formatterHelper->formatSection(
                    'warn',
                    'You\'re about to sync files between',
                    'comment'
                ),
                $formatterHelper->formatSection(
                    'warn',
                    sprintf(
                        'From: %s @ %s',
                        $beam->getCombinedPath($beam->getLocalPath()),
                        $beam->getOption('branch')
                    ),
                    'comment'
                ),
                $formatterHelper->formatSection(
                    'warn',
                    sprintf(
                        'To:   %s',
                        $beam->getRemotePath()
                    ),
                    'comment'
                )
            )
        );

    }
    /**
     * @param OutputInterface $output
     * @param                 $formatterHelper
     * @param                 $error
     */
    public function outputError(OutputInterface $output, $formatterHelper, $error)
    {
        $output->writeln(
            $formatterHelper->formatSection(
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
     * @param  OutputInterface $output
     * @param                  $dialogHelper
     * @param                  $formatterHelper
     * @return mixed
     */
    protected function isOkay(OutputInterface $output, $dialogHelper, $formatterHelper)
    {
        return in_array(
            $dialogHelper->askConfirmation(
                $output,
                $formatterHelper->formatSection(
                    'prompt',
                    $this->getQuestion('Is this okay?', 'y'),
                    'comment'
                ),
                'y'
            ),
            array(
                'y',
                'yes'
            )
        );
    }
}
