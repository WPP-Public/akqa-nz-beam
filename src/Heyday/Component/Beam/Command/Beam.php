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
    private $exclude_props_file_name = 'exclude.properties';
    private $colors;


    /**
     * Set the config file name for the json formatted config file. File MUST be located in the project source root.
     *
     * @param string $config_file_name  The name of the .json formatted config file
     *
     * @api
     */
    public function setConfigFileName($config_file_name)
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
    public function setGitExportDir($git_export_dir)
    {
        $this->git_export_dir = $git_export_dir;
    }


    /**
     * Set the name of the exclude properties file
     *
     * @param string $exclude_props_file_name    The name of the exclude properties file
     *
     * @api
     */
    public function setExcludePropsFileName($exclude_props_file_name)
    {
        $this->exclude_props_file_name = $exclude_props_file_name;
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
                'If set, no files will be transferred'
            )
            ->addOption(
                'fast',
                'f',
                InputOption::VALUE_NONE,
                'Skips the pre-sync check prior to syncing files'
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
            );

        $this->setThemeColors();
    }


    /**
     * Command execution
     *
     * @param InputInterface  $input  An Input instance
     * @param OutputInterface $output An Output instance
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output("Starting...", Beam::WARN);

        $this->direction = $input->getArgument('direction');
        if ($this->direction != 'up' && $this->direction != 'down') {
            $this->output(
                "Invalid direction specified - should be 'up' or 'down'",
                Beam::ERROR
            );
        }
        $this->remote_conf_name = $input->getArgument('remote_conf_name');
        if ($input->getOption('branch')) {
            $this->git_branch = $input->getOption('branch');
        }
        if ($input->getOption('path')) {
            $this->sync_sub_directory = $input->getOption('path');
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

        $this->process();
    }


    /**
     * Perform deployment
     */
    private function process()
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

        $include_path = '';
        if ($this->sync_sub_directory) {
            $include_path = '/' . trim($this->sync_sub_directory, '/') . '/';
        }
        $project_application_pattern_ids = array_merge(
            array('_base'),
            $deploy_properties_obj['exclude']['applications']
        );
        $exclude_properties_file_path = preg_replace('/\w+\/\.\.\//', '', $this->source_root_path . '/../' . $this->exclude_props_file_name);
        $this->generateExcludePropertiesFile(
            $exclude_properties_file_path,
            $project_application_pattern_ids,
            $deploy_properties_obj['exclude']['patterns'],
            $include_path
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
        }

        $this->perform($source_dir, $destination_dir, $include_path, $exclude_properties_file_path);

    }


    /**
     * Perform deployment
     */
    private function perform($source_dir, $destination_dir, $include_path, $exclude_properties_file_path)
    {

        if ($this->is_delete) {
            $this->output('DELETE option enabled - files at destination may be deleted', Beam::WARN);
        }
        if ($this->is_dryrun) {
            $this->output('DRYRUN option enabled - no files will be transferred', Beam::WARN);
        } else {
            $this->output('You\'re about to sync files between', Beam::WARN);
        }
        $this->output("FROM:\t<magenta>$source_dir$include_path</magenta>", Beam::WARN);
        $this->output("TO:\t<magenta>$destination_dir$include_path</magenta>", Beam::WARN);

        if ($this->is_fast) {
            $this->output("Running in `fast` mode so skipping pre-check...", Beam::WARN);
        } else {
            $this->output("The following files ".($this->is_dryrun ? "would" : "will")." be modified:", Beam::WARN);
            $rsync = $this->runRSYNC(
                $source_dir,
                $destination_dir,
                $exclude_properties_file_path,
                $include_path,
                true,
                $this->is_delete,
                true
            );
            $this->output("CMD:\t$rsync", Beam::DATA);
        }

        if (!$this->is_dryrun) {
            if ($this->confirmAction("is this ok? (y/n)")) {
                $this->output("Syncing files...", Beam::INFO);
                $this->runRSYNC(
                    $source_dir,
                    $destination_dir,
                    $exclude_properties_file_path,
                    $include_path,
                    false,
                    $this->is_delete,
                    false
                );
                $this->output("STATUS: <green><bold>ok</bold></green>", Beam::WARN);
            }
        }
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
        $local_sync_path = preg_replace('/\w+\/\.\.\//', '', $this->source_root_path . '/../' . $this->git_export_dir);
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
                $this->output(
                    "You cannot upload from your local working copy to a server that is locked to a remnote branch",
                    Beam::ERROR
                );
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
        $cmd_git_update = sprintf('(git remote update --prune %s)', $branch_parts[1]);
        $this->output($cmd_git_update, Beam::DATA);
        $git_process = new Process($cmd_git_update, $path);
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
            '(git archive %s) | (cd %s && tar -xf -)',
            $branch,
            $export_path
        );
        $this->output($cmd_git_archive, Beam::DATA);
        $git_archive_process = new Process($cmd_git_archive, $path);
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
        $project_exclude_patterns_array,
        $sync_sub_directory
    ) {
        if (file_exists($exclude_properties_file_path) && strpos($exclude_properties_file_path, $this->exclude_props_file_name) !== false) {
            unlink($exclude_properties_file_path);
        }

        $exclude_patterns = array();
        $exclude_patterns_file = preg_replace('/\w+\/\.\.\//', '', __DIR__ . "/../../../../../config/exclude-patterns.json");
        $json_string = file_get_contents($exclude_patterns_file);
        if ($exclude_patterns_json = json_decode($json_string, true)) {
            foreach ($exclude_patterns_json as $name => $patterns) {
                if (array_search($name, $project_application_pattern_ids) !== false) {
                    $exclude_patterns = array_merge($exclude_patterns, $patterns);
                }
            }
        } else {
            $this->output('Invalid exclude-patterns.json file', Beam::ERROR);
        }
        $exclude_patterns = array_merge($exclude_patterns, $project_exclude_patterns_array);
        if ($sync_sub_directory) {
            foreach ($exclude_patterns as $key => $value) {
                if (trim($value) == $sync_sub_directory) {
                    unset($exclude_patterns[$key]);
                }
            }
        }
        $exclude_patterns_str = implode(PHP_EOL, $exclude_patterns).PHP_EOL;
        file_put_contents($exclude_properties_file_path, $exclude_patterns_str);
    }


    /**
     * Sync files using rsync
     */
    private function runRSYNC(
        $source_path,
        $destination_path,
        $exclude_properties_file_path,
        $sync_sub_directory = null,
        $dryrun = false,
        $delete = false,
        $output = true
    ) {

        $rsync = sprintf('rsync -az %s/ %s --delay-updates --checksum', $source_path, $destination_path);

        if ($sync_sub_directory) {
            $rsync .= sprintf(' --include="%s" --exclude="/*"', '/' . trim($sync_sub_directory, '/') . '/');
        }
        $rsync .= sprintf(' --exclude-from="%s"', $exclude_properties_file_path);
        if ($dryrun) {
            $rsync .= ' --dry-run';
        }
        if ($delete) {
            $rsync .= ' --delete';
        }
        if ($output) {
            $rsync .= ' --verbose';
        }

        $rsync_process = new Process(
            $rsync . " | grep -E '^deleting|[^/]$' | sed -e 's/^.*$/\033[90mdata\033[97m:\t    \033[90m&\033[0m/'"
        );
        $rsync_process->setTimeout(300);
        $rsync_process->run(
            function ($type, $buffer) {
                if ('err' === $type) {
                    print "error:\t".$buffer;
                } else {
                    print $buffer;
                }
            }
        );
        if (!$rsync_process->isSuccessful()) {
            throw new RuntimeException($rsync_process->getErrorOutput());
        }

        return $rsync;
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


    private function setThemeColors()
    {
        $this->colors =
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
    }

    /**
     * Print info message
     */
    private function output($message, $level)
    {
        if (!$this->is_verbose && ($level == Beam::INFO || $level == Beam::DATA)) {
            return;
        }

        $c = new Color();
        $c->setTheme(
            $this->colors
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
        foreach ($this->colors as $color) {
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
