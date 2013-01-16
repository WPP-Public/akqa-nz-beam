<?php

namespace Heyday\Component\Beam\Command;

use Colors\Color;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * An tool for deploying files to a remote server using rsync and git.
 *
 * This class is optimized for a standard CLI environment.
 *
 * Usage:
 *
 *     $app = new Application('myapp', '1.0 (stable)');
 *     $app->add(new SimpleCommand());
 *     $app->run();
 *
 * @author Shane Garelja <shane@heyday.co.nz>
 *
 * @api
 */
class Beam extends Command
{

    const
        INFO = 0,
        WARN = 1,
        ERROR = 2,
        DATA = 3,
        PROMPT = 4;

    // Application arguments
    private $direction;
    private $remote_conf_name;

    // Application options
    private $git_branch;
    private $sync_sub_directory;
    private $is_dryrun = false;
    private $is_fast = false;
    private $is_delete = false;
    private $is_verbose = false;
    private $is_upload_from_workingcopy = false;

    // Project properties
    private $source_root_path;
    private $config_file_name = 'deploy.json';
    private $git_export_dir = '_temp';


    /**
     * Set the config file name for the json formatted config file. File MUST be located in the project source root.
     *
     * @param string $config_file_name  The name of the .json formatted config file
     *
     * @api
     */
    public function setConfigFileName()
    {
        $this->config_file_name = $config_file_name;
    }


    /**
     * Set the name of the directory to export from git to
     *
     * @param string $git_export_dir    The directory name to export to from git
     *
     * @api
     */
    public function setGitExportDir()
    {
        $this->git_export_dir = $git_export_dir;
    }


    /**
     * Command configuration
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
                'remote_conf_name',
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
                'If set, no files will be beamed'
            )
            ->addOption(
                'fast',
                'f',
                InputOption::VALUE_NONE,
                'Turns off checksum comparison and uses the faster, timestamp/filesize comparison'
            )
            ->addOption(
                'delete',
                'D',
                InputOption::VALUE_NONE,
                'USE WITH CAUTION!! adds the delete flag to remove items that don\'t exist at the destination'
            )
            ->addOption(
                'workingcopy',
                'W',
                InputOption::VALUE_NONE,
                'USE WITH CAUTION!! adds the delete flag to remove items that don\'t exist at the destination'
            );
    }


    /**
     * Command execution
     *
     * @param InputInterface  $input  An Input instance
     * @param OutputInterface $output An Output instance
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output("starting...", Beam::WARN);

        $this->direction = $input->getArgument('direction');
        $this->remote_conf_name = $input->getArgument('remote_conf_name');
        if ($input->getOption('branch')) {
            $this->git_branch = str_replace('=', '', $input->getOption('branch'));
        }
        if ($input->getOption('path')) {
            $this->sync_sub_directory = str_replace('=', '', $input->getOption('path'));
        }
        if ($input->getOption('dryrun')) {
            $this->is_dryrun = true;
        }
        if ($input->getOption('fast')) {
            $this->is_fast = true;
        }
        if ($input->getOption('delete')) {
            $this->is_delete = true;
        }
        if ($input->getOption('verbose')) {
            $this->is_verbose = true;
        }
        if ($input->getOption('workingcopy')) {
            $this->is_upload_from_workingcopy = true;
        }

        $this->transfer();
    }


    /**
     * Perform deployment
     */
    private function transfer()
    {

        $deploy_properties_obj = null;
        $this->output("Current directory: <magenta>" . getcwd() . "</magenta>", Beam::INFO);
        if ($this->source_root_path = $this->findSourceRoot()) {
            $deploy_properties_obj = $this->parseJSONConfFile($this->source_root_path, $this->config_file_name);
        } else {
            $this->output(
                'Config file ' . $this->config_file_name .
                ' not found!! `Beam` must be run inside project source directory or deeper',
                Beam::ERROR
            );
        }

        $project_application_pattern_ids = array_merge(
            array('_base'),
            $deploy_properties_obj['exclude']['applications']
        );
        $exclude_properties_file_path = realpath($this->source_root_path . '/../exclude.properties');
        $this->generateExcludePropertiesFile(
            $exclude_properties_file_path,
            $project_application_pattern_ids,
            $deploy_properties_obj['exclude']['patterns']
        );

        $remote_server_user = null;
        $remote_server_host = null;
        $remote_server_path = null;
        $branch = null;
        $valid_servers = array();

        foreach ($deploy_properties_obj['servers'] as $key => $config) {
            if ($key == $this->remote_conf_name) {
                $remote_server_user = $config['user'];
                $remote_server_host = $config['host'];
                $remote_server_path = $config['webroot'];
                if (array_key_exists('branch', $config)) {
                    $branch = $config['branch'];
                }
            }
            array_push($valid_servers, $key);
        }

        if (!isset($remote_server_user) || !isset($remote_server_host) || !isset($remote_server_path)) {
            $this->output(
                "Server config not specified or not found. Valid servers for this project are: <magenta>" .
                implode(", ", $valid_servers) . "</magenta>",
                Beam::ERROR
            );
        }


        $local_sync_path = '';
        $server_sync_path = sprintf('%s@%s:%s', $remote_server_user, $remote_server_host, $remote_server_path);
        if (file_exists($this->source_root_path . '/.git') && $this->direction == 'up') {
            $local_sync_path = $this->gitExport($branch);
        } else {
            $local_sync_path = $this->source_root_path;
        }

        $source_dir = null;
        $destination_dir = null;
        if ($this->direction == 'down') {
            $source_dir = $server_sync_path;
            $destination_dir = $local_sync_path;
        } elseif ($this->direction == 'up') {
            $source_dir = $local_sync_path;
            $destination_dir = $server_sync_path;
        } else {
            $this->output(
                "Invalid direction argument specified - should be 'up' or 'down'",
                Beam::ERROR
            );
        }

        $this->syncFiles(
            $source_dir,
            $destination_dir,
            $exclude_properties_file_path,
            $this->sync_sub_directory,
            $this->is_dryrun,
            $this->is_delete,
            $this->is_fast
        );
    }


    /**
     * Searches for config file to determine source root
     *
     * @return string Directory of .json config file (source root), otherwise null
     *
     * @api
     */
    private function findSourceRoot()
    {
        $dir = getcwd();
        $source_root = null;
        $found = false;
        while (!$found) {
            if (file_exists($dir."/".$this->config_file_name)) {
                $source_root = $dir;
                $found = true;
                break;
            }
            if (strpos('/', $dir) === false) {
                break; // not found
            }
            $dir = substr_replace($dir, '', strrpos($dir, '/'));
        }
        return $source_root;
    }


    /**
     * Set the deployment properties
     */
    protected function parseJSONConfFile($source_root_path, $config_file_name)
    {
        $project_config_props = array();
        $config_path = $source_root_path .'/'. $config_file_name;
        if (file_exists($config_path)) {
            $json_string = file_get_contents($config_path);
            if ($json = json_decode($json_string, true)) {
                return $json;
            } else {
                $this->output("Invalid $config_file_name file", Beam::ERROR);
            }
        } else {
            $this->output("No $config_file_name file found", Beam::ERROR);
        }
    }


    /**
     * Export git repo to temp directory
     */
    private function gitExport($branch)
    {
        $local_sync_path = realpath($this->source_root_path . '/../' . $this->git_export_dir);
        $git_branch = '';
        if (isset($branch)) {
            $git_branch = $branch;
        } elseif ($this->git_branch) {
            $git_branch_process = new Process('git branch');
            $git_branch_process->run();
            if (!$git_branch_process->isSuccessful()) {
                throw new RuntimeException($git_branch_process->getErrorOutput());
            }
            $valid_branches = explode("\n", $git_branch_process->getOutput());
            array_walk($valid_branches, array($this, 'gitTidyBranchName'));
            if (in_array($this->git_branch, $valid_branches)) {
                $git_branch = $this->git_branch;
            } else {
                $this->output("That branch is invalid", Beam::ERROR);
            }
        } else {
            $git_branch_process = new Process('git rev-parse --abbrev-ref HEAD');
            $git_branch_process->run();
            if (!$git_branch_process->isSuccessful()) {
                throw new RuntimeException($git_branch_process->getErrorOutput());
            }
            $git_branch = trim($git_branch_process->getOutput());
        }

        if (stripos($branch, 'remotes') === 0) {
            if ($this->is_upload_from_workingcopy) {
                $this->output("You cannot upload to a production server from your local working copy", Beam::ERROR);
            }
            $this->updateRemoteGitBranch($this->source_root_path, $git_branch);
        } else {
            if ($this->is_upload_from_workingcopy) {
                $local_sync_path = $this->source_root_path;
            } else {
                $this->exportGitBranch($this->source_root_path, $git_branch, $local_sync_path);
            }
        }
        return $local_sync_path;
    }


    /**
     * Update local copy of remote git branch
     */
    private function updateRemoteGitBranch($path, $branch)
    {
        $this->output("Updating <magenta>$branch</magenta>", Beam::INFO);
        $branch_parts = explode("/", $branch);
        $cmd_git_update = sprintf('(cd %s && git remote update --prune %s)', $path, $branch_parts[1]);
        $this->output($cmd_git_update, Beam::DATA);
        $git_process = new Process($cmd_git_update);
        $git_process->run();
        if (!$git_process->isSuccessful()) {
            throw new RuntimeException($git_process->getErrorOutput());
        }
    }


    /**
     * Archive and extract git branch
     */
    private function exportGitBranch($path, $branch, $export_path)
    {
        $this->createGitExportDirectory($export_path);
        $this->output("Exporting branch <magenta>$branch</magenta>", Beam::INFO);
        $cmd_git_archive = sprintf(
            '(cd %s && git archive %s) | (cd %s && tar -xf -)',
            $path,
            $branch,
            $export_path
        );
        $this->output($cmd_git_archive, Beam::DATA);
        $git_archive_process = new Process($cmd_git_archive);
        $git_archive_process->run();
        if (!$git_archive_process->isSuccessful()) {
            throw new RuntimeException($git_archive_process->getErrorOutput());
        }
    }


    /**
     * Update local copy of remote git branch
     */
    private function createGitExportDirectory($export_path)
    {
        if (isset($export_path) && file_exists($export_path)) {
            if (preg_match('#^.*/'.$this->git_export_dir.'$#', $export_path)) {
                $this->output("Removing TEMP folder: <magenta>$export_path</magenta>", Beam::INFO);
                $rm_dir_process = new Process('rm -rf "' . $export_path . '"');
                $rm_dir_process->run();
                if (!$rm_dir_process->isSuccessful()) {
                    throw new RuntimeException($rm_dir_process->getErrorOutput());
                }
            } else {
                $this->output(
                    "Invalid git export directory detected: <magenta>$export_path</magenta",
                    Beam::ERROR
                );
            }
        }
        mkdir($export_path, 0755);
    }


    /**
     * Generate exclude.properties file
     */
    private function generateExcludePropertiesFile(
        $exclude_properties_file_path,
        $project_application_pattern_ids,
        $project_exclude_patterns_array
    ) {
        $exclude_patterns = "";
        $exclude_patterns_file = realpath(__DIR__ . "/../../../../../config/exclude-patterns.json");
        $json_string = file_get_contents($exclude_patterns_file);
        if ($exclude_patterns_json = json_decode($json_string, true)) {
            
            foreach ($exclude_patterns_json as $name => $patterns) {
                if (array_search($name, $project_application_pattern_ids) !== false) {
                    $exclude_patterns .= implode(PHP_EOL, $patterns).PHP_EOL;
                }
            }
        } else {
            $this->output('Invalid exclude-patterns.json file', Beam::ERROR);
        }

        if (file_exists($exclude_properties_file_path)) {
            unlink($exclude_properties_file_path);
        }
        $exclude_patterns .= implode(PHP_EOL, $project_exclude_patterns_array);
        file_put_contents($exclude_properties_file_path, $exclude_patterns);
    }


    /**
     * Sync files using rsync
     */
    public function syncFiles(
        $source_path,
        $destination_path,
        $exclude_properties_file_path,
        $sync_sub_directory = null,
        $dryrun = false,
        $delete = false,
        $fast = false
    ) {

        $include_path = '';
        $rsync_include_path = '';
        if ($sync_sub_directory) {
            $path = trim($sync_sub_directory);

            // Ensure path starts with a slash
            if (substr($path, 0, 1) != '/') {
                $path = "/" . $path;
            }
            // Add trailing slash if required
            if (substr($path, -1) != '/') {
                $path = $path . '/';
            }

            $full_include_path = $path;
            $include_path = $full_include_path;
            $rsync_include_path = '--include="' . $full_include_path . '" --exclude="/*"';

            $fileArr = file($exclude_properties_file_path);
            foreach ($fileArr as $key => $line) {
                if (trim($line) == $path) {
                    unset($fileArr[$key]);
                }
            }
            file_put_contents($exclude_properties_file_path, implode('', $fileArr));
        }

        $rsync = sprintf(
            'rsync -avz %s/ %s %s --exclude-from="%s"',
            $source_path,
            $destination_path,
            $rsync_include_path,
            $exclude_properties_file_path
        );

        if ($this->is_dryrun) {
            $rsync = $rsync . ' --dry-run';
            $this->output(
                "##################################################\n".
                "\t#                                                #\n".
                "\t#                   TEST MODE                    #\n".
                "\t#          NO FILES WILL BE TRANSFERRED          #\n".
                "\t#                                                #\n".
                "\t##################################################",
                Beam::WARN
            );
        }

        if ($this->is_delete) {
            $this->output("Running with --delete option - files at destination may be deleted", Beam::WARN);
            $rsync = $rsync . ' --delete';
        }

        if (!$this->is_fast) {
            $this->output("Running a fast comparison - modified files list may be inaccurate", Beam::WARN);
            $rsync = $rsync . ' --checksum';
        }

        $c = new Color();
        $c->setTheme(
            array(
                'setting' => 'yellow',
                'warn' => 'red',
           )
        );
        $this->output("This will sync files...", Beam::WARN);
        $this->output("FROM:\t<magenta>$source_path$include_path</magenta>", Beam::WARN);
        $this->output("TO:\t<magenta>$destination_path$include_path</magenta>", Beam::WARN);
        $this->output("CMD:\t$rsync", Beam::DATA);
        $this->output("<white>EXCLUSIONS:</white>", Beam::DATA);
        $exclusions = file($exclude_properties_file_path);
        foreach ($exclusions as $exclusion) {
            $this->output("    " . trim("$exclusion"), Beam::DATA);
        }
        $rsync = $rsync . " --delay-updates";

        if ($this->confirmAction("is this ok? (y/n)")) {
            $this->output("Syncing files...", Beam::INFO);
            $rsync_process = new Process(
                $rsync . " | grep -E '^deleting|[^/]$' | sed -e 's/^.*$/\033[90mdata\033[97m:\t    \033[90m&\033[0m/'"
            );
            $rsync_process->setTimeout(300);
            $rsync_process->run(
                function ($type, $buffer) {
                    if ('err' === $type) {
                        print 'ERROR > '.$buffer;
                    } else {
                        print $buffer;
                    }
                }
            );
            if (!$rsync_process->isSuccessful()) {
                throw new RuntimeException($rsync_process->getErrorOutput());
            }
        }
        $this->output("STATUS: <green><bold>ok</bold></green>", Beam::WARN);
    }


    /**
     * Print action confirmation
     */
    private function confirmAction($message)
    {
        $this->output("Is this <green><bold>ok?</bold></green> (y/n) ", Beam::PROMPT);
        $handle = fopen("php://stdin", "r");
        $line = fgets($handle);
        if (trim($line) != 'y') {
            $this->output('PROCESS ABORTED!!', Beam::ERROR);
        }
        return true;
    }


    /**
     * Print info message
     */
    private function output($message, $level)
    {
        if ($this->is_verbose && ($level == Beam::INFO || $level == Beam::DATA)) {
            return;
        }

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
            Beam::INFO => array('name' => 'info', 'lcolor' => 'green', 'mcolor' => 'white'),
            Beam::WARN => array('name' => 'warn', 'lcolor' => 'brown', 'mcolor' => 'brown'),
            Beam::ERROR => array('name' => 'error', 'lcolor' => 'red', 'mcolor' => 'red'),
            Beam::DATA => array('name' => 'data', 'lcolor' => 'darkgray', 'mcolor' => 'darkgray'),
            Beam::PROMPT => array('name' => 'prompt', 'lcolor' => 'white', 'mcolor' => 'darkgray')
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
            case Beam::ERROR:
                $line .= "<red>$message</red>";
                break;
            case Beam::WARN:
                $line .= "<brown>$message</brown>";
                break;
            case Beam::DATA:
                $line .= "<darkgray>$message</darkgray>";
                break;
            case Beam::PROMPT:
                $line .= "<darkgray>$message</darkgray>";
                break;
            default:
                $line .= "<white>$message</white>";
        }
        $line = $c($line)->colorize();
        if ($level != Beam::PROMPT) {
            $line .= PHP_EOL;
        }
        print $line;
        if ($level == Beam::ERROR) {
            exit;
        }
    }


    /**
     * Tidy the branch name
     */
    private function gitTidyBranchName (&$value)
    {
        $value = str_replace(array('*', ' '), array(' ', ''), $value);
    }
}
