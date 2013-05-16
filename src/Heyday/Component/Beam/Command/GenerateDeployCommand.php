<?php

namespace Heyday\Component\Beam\Command;

use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateDeployCommand extends SymfonyCommand
{

    protected function configure()
    {
        $this
            ->setName('genconfig')
            ->setDescription('Generate beam.json file')
            ->addArgument(
                'client_code',
                InputArgument::OPTIONAL,
                'Option client code for corresponding xxx.properties file (located in ~/build/config/~/build/sync)'
            )
            ->addOption(
                'replace',
                'r',
                InputOption::VALUE_NONE,
                'If set, any existing beam.json files will be overwritten'
            );
    }

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

        if ($clientCode = $input->getArgument('client_code')) {
            $template = $this->makeTemplateFromClientCode($clientCode);
        } else {
            $template = array(
                'exclude' => array(
                    'patterns'     => array(),
                ),
                'servers' => array(
                    's1' => array(
                        'user'     => '',
                        'host'     => '',
                        'webroot'  => '',
                    ),
                    'live' => array(
                        'user'     => '',
                        'host'     => '',
                        'webroot'  => '',
                        'branch'   => 'remotes/origin/master'
                    )
                ),
            );
        }

        // Save template
        file_put_contents('beam.json', str_replace('\/', '/', $this->jSONFormat(json_encode($template))));

        $output->writeln(
            "<info>Success: beam.json file saved to " . getcwd() .
                "/beam.json - be sure to check the file before using it</info>"
        );

    }

    /**
     * @param $clientCode
     * @return array
     */
    protected function makeTemplateFromClientCode($clientCode)
    {

        $properties = array();

        // Set defaults
        $defaults = array(
            'project.applications'      => 'silverstripe',
            'exclude.patterns'          => array(''),
            'staging.server.user'       => 'dev',
            'staging.server.host'       => 'snarl.heyday.net.nz',
            'staging.server.webroot'    => 'FIX_ME!!',
            'production.server.user'    => 'FIX_ME!!',
            'production.server.host'    => 'FIX_ME!!',
            'production.server.webroot' => 'FIX_ME!!'
        );

        // If we have a client code then set code and get config properties file
        $project_code = $clientCode;
        $defaults['staging.server.webroot'] = '/home/dev/subdomains/test' . $project_code;
        $defaults['production.server.user'] = $project_code;

        // Check if properties file exists
        $properties_file = $_SERVER['HOME'] . '/build/config/' . $project_code . '.properties';
        if (!file_exists($properties_file)) {

            throw new \RuntimeException("Properties file $properties_file does not exist.");

        }

        // Extract current properties
        $properties_file = file($properties_file);
        foreach ($properties_file as $line) {

            $trimmed_line = trim($line);
            if (!empty ($trimmed_line)) {

                $key_value = explode("=", $trimmed_line);

                if (strpos($key_value[1], "~") === 0) {

                    // Try to guess full path
                    $key_value[1] = "/home/$project_code" . str_replace("~", '', $key_value[1]);

                }
                $properties[$key_value[0]] = $key_value[1];
            }

        }

        // Check if sync file exists
        $sync_file = $_SERVER['HOME'] . '/build/sync/' . $project_code . '.properties';
        if (file_exists($sync_file)) {

            $defaults['exclude.patterns'] = explode("\n", trim(file_get_contents($sync_file)));

        }

        // Merge the arrays
        $new_properties = array_merge($defaults, $properties);

        // Store properties into beam.json template
        $template = array(
            'exclude' => array(
                'applications' => explode(',', $new_properties['project.applications']),
                'patterns'     => $new_properties['exclude.patterns'],
            ),
            'servers' => array(
                's1' => array(
                    'user'     => $new_properties['staging.server.user'],
                    'host'     => $new_properties['staging.server.host'],
                    'webroot'  => $new_properties['staging.server.webroot'],
                )
            ),
        );

        // Create live server config if allowed
        if (!array_key_exists('production.server.cansync', $new_properties)) {

            $template['servers']['live'] = array(
                'user'     => $new_properties['production.server.user'],
                'host'     => $new_properties['production.server.host'],
                'webroot'  => $new_properties['production.server.webroot'],
                'branch'   => 'remotes/origin/master'
            );

        }

        return $template;
    }


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
