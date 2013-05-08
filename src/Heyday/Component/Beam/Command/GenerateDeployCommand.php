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
            ->setName('gendeploy')
            ->setDescription('Generate deploy.json file')
            ->addArgument(
                'client_code',
                InputArgument::OPTIONAL,
                'Option client code for corresponding xxx.properties file (located in ~/build/config/~/build/sync)'
            )
            ->addOption(
                'replace',
                'r',
                InputOption::VALUE_NONE,
                'If set, any existing deploy.json files will be overwritten'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        // Check a deploy.json file doesn't already exist
        if (file_exists('deploy.json') && !$input->getOption('replace')) {

            $output->writeln(
                "<error>Error: deploy.json file already exists in this directory, use '-r (or --replace)'
                flag to overwrite file</error>"
            );
            exit;

        }


        $properties = array();

        // Set defaults
        $defaults = array(
            'project.applications' => 'silverstripe',
            'exclude.patterns' => array(''),
            'staging.server.user' => 'dev',
            'staging.server.host' => 'snarl.heyday.net.nz',
            'staging.server.webroot' => 'FIX_ME!!',
            'staging.db.username' => '',
            'staging.db.password' => '',
            'staging.db.database' => '',
            'production.server.user' => 'FIX_ME!!',
            'production.server.host' => 'FIX_ME!!',
            'production.server.webroot' => 'FIX_ME!!',
            'production.db.username' => '',
            'production.db.password' => '',
            'production.db.database' => ''
        );

        if ($input->getArgument('client_code')) {

            // If we have a client code then set code and get config properties file
            $project_code = $input->getArgument('client_code');
            $defaults['staging.server.webroot'] = '/home/dev/subdomains/test' . $project_code;
            $defaults['production.server.user'] = $project_code;

            // Check if properties file exists
            $properties_file = $_SERVER['HOME'].'/build/config/' . $project_code . '.properties';
            if (!file_exists($properties_file)) {

                $output->writeln("<error>Error: properties file $properties_file does not exist</error>");
                exit;

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
            $sync_file = $_SERVER['HOME'].'/build/sync/' . $project_code . '.properties';
            if (file_exists($sync_file)) {

                $defaults['exclude.patterns'] = explode("\n", trim(file_get_contents($sync_file)));

            }

        } else {

            // If no client code is specified then ask a a default deploy.json shoul dbe created
            $dialog = $this->getHelperSet()->get('dialog');
            if (!$dialog->askConfirmation(
                $output,
                "<question>You haven't sepcified a client code, would you like to generate a default deploy.json file?".
                " (y,n)</question>",
                false
            )) {
                exit;
            }

        }



        // Merge the arrays
        $new_properties = array_merge($defaults, $properties);

        // Store properties into deploy.json template
        $json_deploy_template = array(
            'exclude' => array(
                'applications' => explode(',', $new_properties['project.applications']),
                'patterns' => $new_properties['exclude.patterns'],
           ),
            'servers' => array(
                's1' => array(
                    'user' => $new_properties['staging.server.user'],
                    'host' => $new_properties['staging.server.host'],
                    'webroot' => $new_properties['staging.server.webroot'],
                    'db-user' => $new_properties['staging.db.username'],
                    'db-pass' => $new_properties['staging.db.password'],
                    'database' => $new_properties['staging.db.database']
               )
           ),
        );

        // Create live server config if allowed
        if (!array_key_exists('production.server.cansync', $new_properties)) {

            $json_deploy_template['servers']['live'] = array(
                'user' => $new_properties['production.server.user'],
                'host' => $new_properties['production.server.host'],
                'webroot' => $new_properties['production.server.webroot'],
                'db-user' => $new_properties['production.db.username'],
                'db-pass' => $new_properties['production.db.password'],
                'database' => $new_properties['production.db.database'],
                'branch' => 'remotes/origin/master'
            );

        }

        // Save template
        file_put_contents('deploy.json', str_replace('\/', '/', $this->jSONFormat(json_encode($json_deploy_template))));

        $output->writeln(
            "<info>Success: deploy.json file saved to ". getcwd() .
            "/deploy.json - be sure to check the file before using it</info>"
        );

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
                        $new_json .= $char . "\n" . str_repeat($tab, $indent_level+1);
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
                    if ($c > 0 && $json[$c-1] != '\\') {
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
