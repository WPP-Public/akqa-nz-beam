<?php

namespace Heyday\Component\Beam\Command;

use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class InitCommand
 * @package Heyday\Component\Beam\Command
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

        $template = array(
            'exclude' => array(
                'patterns' => array(),
            ),
            'servers' => array(
                's1'   => array(
                    'user'    => '',
                    'host'    => '',
                    'webroot' => '',
                ),
                'live' => array(
                    'user'    => '',
                    'host'    => '',
                    'webroot' => '',
                    'branch'  => 'remotes/origin/master'
                )
            ),
        );

        // Save template
        file_put_contents('beam.json', str_replace('\/', '/', $this->jSONFormat(json_encode($template))));

        $output->writeln(
            "<info>Success: beam.json file saved to " . getcwd() .
            "/beam.json - be sure to check the file before using it</info>"
        );

    }

    /**
     * @param $json
     * @return bool|string
     */
    private function jSONFormat($json)
    {
        $tab = "\t";
        $new_json = "";
        $indent_level = 0;
        $in_string = false;

        $json_obj = json_decode($json);

        if ($json_obj === false) {
            return false;
        }

        $json = json_encode($json_obj);
        $len = strlen($json);

        for ($c = 0; $c < $len; $c++) {
            $char = $json[$c];
            switch ($char) {
                case '{':
                case '[':
                    if (!$in_string) {
                        $new_json .= $char . "\n" . str_repeat($tab, $indent_level + 1);
                        $indent_level++;
                    } else {
                        $new_json .= $char;
                    }
                    break;
                case '}':
                case ']':
                    if (!$in_string) {
                        $indent_level--;
                        $new_json .= "\n" . str_repeat($tab, $indent_level) . $char;
                    } else {
                        $new_json .= $char;
                    }
                    break;
                case ',':
                    if (!$in_string) {
                        $new_json .= ",\n" . str_repeat($tab, $indent_level);
                    } else {
                        $new_json .= $char;
                    }
                    break;
                case ':':
                    if (!$in_string) {
                        $new_json .= ": ";
                    } else {
                        $new_json .= $char;
                    }
                    break;
                case '"':
                    if ($c > 0 && $json[$c - 1] != '\\') {
                        $in_string = !$in_string;
                    }
                // Fall through
                default:
                    $new_json .= $char;
                    break;
            }
        }

        return $new_json;
    }
}
