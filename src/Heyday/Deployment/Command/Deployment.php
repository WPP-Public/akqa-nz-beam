<?php
namespace Heyday\Deployment\Command;

use Colors\Color;

class Deployment
{

    const
        INFO = 0,
        WARN = 1,
        ERROR = 2,
        DATA = 3,
        PROMPT = 4;

    // Project properties
    private $arguments;
    private $sites_path;
    private $project_path;
    private $project_is_git = false;

    // Deployment settings
    private $deploy_properties_obj;
    private $exclude_properties_file;


    /**
     * Constructor sets up paths and deployment parameters
     */
    public function __construct($argv, $sites_path)
    {

        $this->output(
            "Running <magenta>deploy</magenta> with commands: <magenta>" .
            implode(' ', array_slice($argv, 1)) . "</magenta>",
            Deployment::INFO
        );

        $arguments = array();
        parse_str(implode('&', array_slice($argv, 1)), $arguments);

        // Show help
        if (array_key_exists('help', $arguments)) {
            Deployment::help();
            exit;
        }

        $this->arguments = $arguments;

        // Set up paths
        $this->sites_path = $sites_path;

        // todo: Does not match /Users/user/Sites/project - only matches deeper dirs
        $this->output("Current directory: <magenta>" . getcwd() . "</magenta>", Deployment::INFO);
        $project_dir = array();
        preg_match('#' . $this->sites_path . '/(.*?)/.*?#', getcwd(), $project_dir);

        if (!array_key_exists(1, $project_dir)) {
            $this->output('Something went horribly wrong!', Deployment::ERROR);
        }

        // Set root project path
        $this->project_path = $this->sites_path . '/' . $project_dir[1];

        // Set project sourcecode path
        if (file_exists($this->project_path . '/source/.git')) {

            $this->project_is_git = true;
            $this->parseDeployJSONFile($this->project_path . '/source');

        } elseif (file_exists($this->project_path . '/trunk')) {

            $this->parseDeployJSONFile($this->project_path . '/trunk');

        } else {

            $this->output('Source code directory not found', Deployment::ERROR);

        }

        $this->generateExcludePropertiesFile();

    }


    /**
     * Export git repo to temp directory
     */
    private function gitExport($branch)
    {

        // Set paths
        $git_repo_path = $this->project_path . "/source";
        $project_sync_path = $this->project_path . '/_deploy';
        $git_branch = '';


        // Determine branch to export
        if (isset($branch)) {

            $git_branch = $branch;

        } elseif (array_key_exists('b', $this->arguments)) {

            exec('git branch', $valid_branches);
            array_walk($valid_branches, array($this, 'gitTidyBranchName'));
            if (in_array($this->arguments['b'], $valid_branches)) {
                $git_branch = $this->arguments['b'];
            } else {
                $this->output("That branch is invalid", Deployment::ERROR);
            }

        } else {

            exec('git branch 2> /dev/null | sed -e \'/^[^*]/d\' -e \'s/* \(.*\)/\1/\'', $output);
            $git_branch = $output[0];

        }


        // Create "temp" directory for Git "export"
        if (isset($project_sync_path) && file_exists($project_sync_path)) {

            // Safeguard: Only allow deletetions of _deploy directories
            if (preg_match('#^/Users/.*/Sites/.*/_deploy$#', $project_sync_path)) {
                $this->output("Removing TEMP folder: <magenta>$project_sync_path</magenta>", Deployment::INFO);
                exec('rm -rf "' . $project_sync_path . '"');
            } else {
                $this->output(
                    "Invalid _deploy directory detected: <magenta>$project_sync_path</magenta",
                    Deployment::ERROR
                );
            }

        }
        mkdir($project_sync_path, 0755);


        // Update local copy of remote branch if needed
        if (stripos($branch, 'remotes') === 0) {

            $this->output("Updating <magenta>$git_branch</magenta>", Deployment::INFO);
            $branch_parts = explode("/", $git_branch);
            $cmd_git_update = sprintf('(cd %s && git remote update --prune %s)', $git_repo_path, $branch_parts[1]);
            $this->output("$cmd_git_update", Deployment::DATA);
            exec($cmd_git_update);

        }

        // Archive and extract
        $this->output("Exporting branch <magenta>$git_branch</magenta>", Deployment::INFO);
        $cmd_git_archive = sprintf(
            '(cd %s && git archive %s) | (cd %s && tar -xf -)',
            $git_repo_path,
            $git_branch,
            $project_sync_path
        );
        $this->output("$cmd_git_archive", Deployment::DATA);
        exec($cmd_git_archive);

        return $project_sync_path;

    }



    /**
     * Set the deployment properties
     */
    private function parseDeployJSONFile($source_code_path)
    {

        $project_config_path = $source_code_path . '/deploy.json';

        // Parse deploy.properties files
        $project_config_props = array();
        if (file_exists($project_config_path)) {

            $json_string = file_get_contents($project_config_path);
            if ($json = json_decode($json_string, true)) {

                $this->deploy_properties_obj = $json;

            } else {

                $this->output('Invalid deploy.json file', Deployment::ERROR);

            }

        } else {

            $this->output('No deploy.json file found', Deployment::ERROR);

        }

    }



    /**
     * Generate exclude.properties file
     */
    private function generateExcludePropertiesFile()
    {

        // Set up exclude patterns
        $exclude_patterns = "";
        $exclude_patterns_file = __DIR__ . "/../../../../config/exclude-patterns.json";
        $json_string = file_get_contents($exclude_patterns_file);
        if ($exclude_patterns_json = json_decode($json_string, true)) {

            $project_applications = array_merge(
                array('_base'),
                $this->deploy_properties_obj['exclude']['applications']
            );
            foreach ($exclude_patterns_json as $name => $patterns) {

                if (array_search($name, $project_applications) !== false) {
                    $exclude_patterns .= implode(PHP_EOL, $patterns).PHP_EOL;
                }

            }

        } else {

            $this->output('Invalid exclude-patterns.json file', Deployment::ERROR);

        }

        // Set path of exclude.properties file
        $this->exclude_properties_file = $this->project_path . '/exclude.properties';

        // Delete current exclude.properties if it exists
        if (file_exists($this->exclude_properties_file)) {
            unlink($this->exclude_properties_file);
        }

        // Append any project-specific exclusions
        $exclude_patterns .= implode(PHP_EOL, $this->deploy_properties_obj['exclude']['patterns']);

        // Output the file
        file_put_contents($this->exclude_properties_file, $exclude_patterns);

    }



    /**
     * Perform deployment
     */
    public function deploy()
    {

        // Set up project sync path
        $project_sync_path = '';

        // Get list of servers
        $remote_server_user = null;
        $remote_server_host = null;
        $remote_server_path = null;
        $git_branch = null;
        $valid_servers = array();
        foreach ($this->deploy_properties_obj['servers'] as $key => $config) {

            if (array_key_exists($key, $this->arguments)) {
                $remote_server_user = $config['user'];
                $remote_server_host = $config['host'];
                $remote_server_path = $config['webroot'];
                if (array_key_exists('branch', $config)) {
                    $git_branch = $config['branch'];
                }
            }
            array_push($valid_servers, $key);

        }

        if (!isset($remote_server_user) || !isset($remote_server_host) || !isset($remote_server_path)) {

            $this->output(
                "Server config not specified or not found. Valid servers for this project are: <magenta>" .
                implode(", ", $valid_servers) . "</magenta>",
                Deployment::ERROR
            );

        }


        // Check if git project
        if ($this->project_is_git) {

            // If we're downloading then our sync directory is simply the source dir
            if (array_key_exists('pull', $this->arguments)) {

                $project_sync_path = $this->project_path . '/source';

            } else {

                // Otherwise let gitExport() determine the sync path (depends on test or live deployment)
                $project_sync_path = $this->gitExport($git_branch);

            }

        } else {

            $project_sync_path = $this->project_path . '/trunk';

        }


        // Set remote server path
        $server_sync_path = sprintf('%s@%s:%s', $remote_server_user, $remote_server_host, $remote_server_path);

        // Uploading or downloading?
        if (array_key_exists('pull', $this->arguments)) {

            // Downloading (syncing from remote to local)
            $this->syncFiles($server_sync_path, $project_sync_path);


        } else {

            // Uploading (syncing from local to remote)
            $this->syncFiles($project_sync_path, $server_sync_path);

        }

    }


    /**
     * Sync files using rsync
     * Made this function static so it can be used easily without instantiating the class
     */
    private function syncFiles($source_path, $destination_path)
    {

        // Add a path, if one has been set
        $include_path = '';
        $rsync_include_path = '';
        if (array_key_exists('p', $this->arguments)) {

            $path = trim($this->arguments['p']);

            // Ensure path starts with a slash
            if (substr($path, 0, 1) != '/') {
                $path = "/" . $path;
            }

            // Add trailing slash if required
            if (substr($path, -1) != '/') {
                $path = $path . '/';
            }

            $full_include_path = $path . '**';
            $include_path = $full_include_path;
            $rsync_include_path = '--include "' . $full_include_path . '"';

            // Read file into array
            $fileArr = file($this->exclude_properties_file);

            // Remove any entries that match the specified path
            foreach ($fileArr as $key => $line) {
                if (trim($line) == $path) {
                    unset($fileArr[$key]);
                }
            }

            // Rewrite exclusions file
            file_put_contents($this->exclude_properties_file, implode('', $fileArr));

        }

        // Build the rsync command
        $rsync = sprintf(
            'rsync -avz %s/ %s --exclude-from="%s" %s',
            $source_path,
            $destination_path,
            $this->exclude_properties_file,
            $rsync_include_path
        );

        // Are we doing a dry run?
        if (array_key_exists('dryrun', $this->arguments)) {

            $rsync = $rsync . ' --dry-run';
            $this->output("#################### TEST MODE ####################", Deployment::WARN);

        }
        if (array_key_exists('delete', $this->arguments)) {

            $rsync = $rsync . ' --delete';

        }
        if (!array_key_exists('quick', $this->arguments)) {

            $rsync = $rsync . ' --checksum';

        }

        // Build the message to the user
        $c = new Color();
        $c->setTheme(
            array(
                'setting' => 'yellow',
                'warn' => 'red',
           )
        );



        $this->output("You are about to perform the following sync...", Deployment::WARN);
        $this->output("FROM:\t<magenta>$source_path$include_path</magenta>", Deployment::WARN);
        $this->output("TO:\t<magenta>$destination_path$include_path</magenta>", Deployment::WARN);
        $this->output("<white>CMD:</white>\t$rsync", Deployment::DATA);
        $this->output("<white>EXCLUSIONS:</white>", Deployment::DATA);
        $exclusions = file($this->exclude_properties_file);
        foreach ($exclusions as $exclusion) {
            $this->output("    " . trim("$exclusion"), Deployment::DATA);
        }

        // $message = $c($message)->colorize();

        // Add additional global flags
        $rsync = $rsync . " --delay-updates";// --out-format='\x1b\033[90mdata\x1b\033[0m\t%n%L'";

        // Perform the sync
        if ($this->confirmAction("Is this ok? (y/n)")) {

            $this->output("Syncing files...", Deployment::INFO);
            // Only want to show files that will change or directories that will be deleted,
            // not directories that will change
            system(
                $rsync . " | grep -E '^deleting|[^/]$' | sed -e 's/^.*$/\033[90mdata\033[97m:\t    \033[90m&\033[0m/'"
            );
        }

        $this->output("deployment <green><bold>ok</bold></green>", Deployment::INFO);

    }




    /**
     * Print action confirmation
     */
    private function confirmAction($message)
    {

        $this->output("Is this <green><bold>ok?</bold></green> (y/n) ", Deployment::PROMPT);
        $handle = fopen("php://stdin", "r");
        $line = fgets($handle);
        if (trim($line) != 'y') {
            $this->output('PROCESS ABORTED!!', Deployment::ERROR);
        }

        return true;

    }

    /**
     * Print info message
     */
    private function output($message, $level)
    {

        $colors =
            array(
                // Colours
                'black' => 'black',
                'red' => 'red',
                'green' => 'green',
                'brown' => 'brown',
                'blue' => 'blue',
                'magenta' => 'magenta',
                'cyan' => 'cyan',
                'lightgray' => 'lightgray',
                'darkgray' => 'darkgray',
                'lightred' => 'lightred',
                'lightgreen' => 'lightgreen',
                'yellow' => 'yellow',
                'lightblue' => 'lightblue',
                'lightmagenta' => 'lightmagenta',
                'lightcyan' => 'lightcyan',
                'white' => 'white'
           );

        $c = new Color();
        $c->setTheme(
            $colors
        );

        $levels = array(
            Deployment::INFO => array('name' => 'info', 'lcolor' => 'green', 'mcolor' => 'white'),
            Deployment::WARN => array('name' => 'warn', 'lcolor' => 'brown', 'mcolor' => 'white'),
            Deployment::ERROR => array('name' => 'error', 'lcolor' => 'red', 'mcolor' => 'red'),
            Deployment::DATA => array('name' => 'data', 'lcolor' => 'darkgray', 'mcolor' => 'darkgray'),
            Deployment::PROMPT => array('name' => 'prompt', 'lcolor' => 'white', 'mcolor' => 'darkgray')
        );

        $line = "<". $levels[$level]['lcolor'] .">".
            $levels[$level]['name']."</". $levels[$level]['lcolor']."><white>:\t</white>";

        $pattern = array();
        $replacement = array();

        foreach ($colors as $color) {
            $pattern[] = "#<$color>#";
            $pattern[] = "#</$color>#";
            $replacement[] = "<X-X-X/". $levels[$level]['mcolor'] ."><X-X-X$color>";
            $replacement[] = "<X-X-X/$color><X-X-X". $levels[$level]['mcolor'] .">";
        }

        // "X-X-X" prevents replacement of replaced strings, now remove them
        $pattern[] = "#X-X-X#";
        $replacement[] = "";

        $message = preg_replace($pattern, $replacement, $message);

        switch ($level) {
            case Deployment::ERROR:
                $line .= "<red>$message</red>";
                break;
            case Deployment::DATA:
                $line .= "<darkgray>$message</darkgray>";
                break;
            case Deployment::PROMPT:
                $line .= "<darkgray>$message</darkgray>";
                break;
            default:
                $line .= "<white>$message</white>";
        }

        $line = $c($line)->colorize();

        if ($level != Deployment::PROMPT) {
            $line .= PHP_EOL;
        }

        echo $line;

        if ($level == Deployment::ERROR) {
            exit;
        }

    }


    /**
     * Tidy the branch name
     */
    private function gitTidyBranchName (&$value)
    {

        $value = str_replace(array('*', ' '), array('', ''), $value);

    }
}
