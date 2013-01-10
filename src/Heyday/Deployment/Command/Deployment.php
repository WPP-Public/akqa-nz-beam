<?php
namespace Heyday\Deployment\Command;

use Colors\Color;

class Deployment {

	const 
		INFO = 0,
		WARN = 1,
		ERROR = 2,
		DATA = 3,
		PROMPT = 4;

	// Project properties
	private 
		$arguments,
		$sites_path,
		$project_path,
		$project_is_git = false;

	// Deployment settings
	private
		$deploy_properties_obj,
		$exclude_properties_file;


	/**
	 * Constructor sets up paths and deployment parameters
	 */
	function __construct($argv, $sites_path) {

		$this->output("Running <magenta>deploy</magenta> with commands: <magenta>" . implode(' ', array_slice($argv, 1)) . "</magenta>", Deployment::INFO);

		$arguments = array();
		parse_str( implode('&', array_slice($argv, 1)), $arguments );

		// Show help
		if ( array_key_exists('help', $arguments) ) {
			Deployment::help();
			exit;
		}

		$this->arguments = $arguments;

		// Set up paths
		$this->sites_path = $sites_path;

		// todo: Does not match /Users/user/Sites/project - only matches deeper dirs
		$this->output("Current directory: <magenta>" . getcwd() . "</magenta>", Deployment::INFO);
		$project_dir = array();
		preg_match( '#' . $this->sites_path . '/(.*?)/.*?#', getcwd(), $project_dir );

		if ( !array_key_exists(1, $project_dir)) {
			$this->output('Something went horribly wrong!', Deployment::ERROR);
		}

		// Set root project path
		$this->project_path = $this->sites_path . '/' . $project_dir[1];

		// Set project sourcecode path
		if ( file_exists($this->project_path . '/source/.git') ) {

			$this->project_is_git = true;
			$this->parse_deploy_json_file($this->project_path . '/source');

		} else if ( file_exists($this->project_path . '/trunk') ) {

			$this->parse_deploy_json_file($this->project_path . '/trunk');

		} else {

			$this->output('Source code directory not found', Deployment::ERROR);

		}

		$this->generate_exclude_properties_file();

	}


	/**
	 * Export git repo to temp directory
	 */
	private function git_export($branch) {

		// Set paths
		$git_repo_path = $this->project_path . "/source";
		$project_sync_path = $this->project_path . '/_deploy';
		$git_branch = '';


		// Determine branch to export
		if ( isset($branch) ) {

			$git_branch = $branch;

		} else if ( array_key_exists( 'b', $this->arguments ) ) {

			exec( 'git branch', $valid_branches );
			array_walk($valid_branches, array($this, 'git_tidy_branch_name') );
			if ( in_array($this->arguments['b'], $valid_branches) ) {
				$git_branch = $this->arguments['b'];
			} else {
				$this->output("That branch is invalid", Deployment::ERROR);
			}

		} else {

			exec( 'git branch 2> /dev/null | sed -e \'/^[^*]/d\' -e \'s/* \(.*\)/\1/\'', $output );
			$git_branch = $output[0];

		}


		// Create "temp" directory for Git "export"
		if ( isset($project_sync_path) && file_exists($project_sync_path) ) {

			// Safeguard: Only allow deletetions of _deploy directories
			if ( preg_match('#^/Users/.*/Sites/.*/_deploy$#', $project_sync_path) ) {
				$this->output("Removing TEMP folder: <magenta>$project_sync_path</magenta>", Deployment::INFO);
				exec('rm -rf "' . $project_sync_path . '"');
			} else {
				$this->output("Invalid _deploy directory detected: <magenta>$project_sync_path</magenta", Deployment::ERROR);
			}

		}
		mkdir( $project_sync_path, 0755 );


		// Update local copy of remote branch if needed
		if ( stripos($branch, 'remotes') === 0 ) {

			$this->output("Updating <magenta>$git_branch</magenta>", Deployment::INFO);
			$branch_parts = explode("/", $git_branch);
			$cmd_git_update = sprintf( '(cd %s && git remote update --prune %s)', $git_repo_path, $branch_parts[1] );
			$this->output("$cmd_git_update", Deployment::DATA);
			exec( $cmd_git_update );

		}

		// Archive and extract
		$this->output("Exporting branch <magenta>$git_branch</magenta>", Deployment::INFO);
		$cmd_git_archive = sprintf( '(cd %s && git archive %s) | (cd %s && tar -xf -)', $git_repo_path, $git_branch, $project_sync_path );
		$this->output("$cmd_git_archive", Deployment::DATA);
		exec( $cmd_git_archive );

		return $project_sync_path;
		
	}



	/**
	 * Set the deployment properties
	 */
	private function parse_deploy_json_file($source_code_path) {

		$project_config_path = $source_code_path . '/deploy.json';

		// Parse deploy.properties files
		$project_config_props = array();
		if ( file_exists($project_config_path) ) {

			$json_string = file_get_contents( $project_config_path );
			if ($json = json_decode( $json_string, true ) ) {

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
	private function generate_exclude_properties_file() {

		// Set up exclude patterns
		$project_applications = $this->deploy_properties_obj['exclude']['applications'];

		$rsync_exclude_patterns = $this->deploy_properties_obj['exclude']['patterns'];

		$patterns_path = realpath( dirname(__FILE__) ) . "/../assets/deploy/patterns/";
		$base_pattern_file = $patterns_path . "_base.properties";

		$this->exclude_properties_file = $this->project_path . '/exclude.properties';

		// Delete current exclude.properties
		if(file_exists($this->exclude_properties_file)) {
			unlink($this->exclude_properties_file);
		}
		// Copy the base properties file
		copy( $base_pattern_file, $this->exclude_properties_file );

		// Append application patterns files
		$fp = fopen( $this->exclude_properties_file, 'a' );
		foreach ( $project_applications as $application ) {
			if ( !empty( $application) ) {
				$application_pattern_file = $patterns_path . $application . ".properties";
				fwrite($fp, file_get_contents($application_pattern_file));
			}
		}
		
		// Append any project-specific exclusions
		fwrite( $fp, implode("\n", $rsync_exclude_patterns ) );
		fclose( $fp );

	}



	/**
	 * Perform deployment
	 */
	public function deploy() {

		// Set up project sync path
		$project_sync_path = '';

		// Get list of servers
		$remote_server_user = null;
		$remote_server_host = null;
		$remote_server_path = null;
		$git_branch = null;
		$valid_servers = array();
		foreach ( $this->deploy_properties_obj['servers'] as $key => $config) {

			if ( array_key_exists( $key, $this->arguments ) ) {
				$remote_server_user = $config['user'];
				$remote_server_host = $config['host'];
				$remote_server_path = $config['webroot'];
				if ( array_key_exists('branch', $config) ) {
					$git_branch = $config['branch'];
				}
			}
			array_push($valid_servers, $key);

		}

		if ( !isset($remote_server_user) || !isset($remote_server_host) || !isset($remote_server_path) ) {

			$this->output("Server config not specified or not found. Valid servers for this project are: <magenta>" . implode(", ", $valid_servers) . "</magenta>", Deployment::ERROR);

		}


 		// Check if git project
		if ( $this->project_is_git ) {

			// If we're downloading then our sync directory is simply the source dir
			if ( array_key_exists( 'pull', $this->arguments ) ) {

				$project_sync_path = $this->project_path . '/source';

			} else {

				// Otherwise let git_export() determine the sync path (depends on test or live deployment)
				$project_sync_path = $this->git_export($git_branch);

			}

		} else {

			$project_sync_path = $this->project_path . '/trunk';

		}


		// Set remote server path
		$server_sync_path = sprintf( '%s@%s:%s', $remote_server_user, $remote_server_host, $remote_server_path );

		// Uploading or downloading?
		if ( array_key_exists( 'pull', $this->arguments ) ) {

			// Downloading (syncing from remote to local)
			$this->sync_files( $server_sync_path, $project_sync_path );


		} else {

			// Uploading (syncing from local to remote)
			$this->sync_files( $project_sync_path, $server_sync_path );
		
		}

	}


	/**
	 * Sync files using rsync
	 * Made this function static so it can be used easily without instantiating the class
	 */
	private function sync_files( $source_path, $destination_path ) {

		// Add a path, if one has been set
		$include_path = '';
		$rsync_include_path = '';
		if ( array_key_exists( 'p', $this->arguments ) ) {

			$path = trim($this->arguments['p']);

			// Ensure path starts with a slash
			if( substr($path, 0, 1) != '/') {
				$path = "/" . $path;
			}

			// Add trailing slash if required
			if(substr($path, -1) != '/') {
				$path = $path . '/';
			}

			$full_include_path = $path . '**';
			$include_path = $full_include_path;
			$rsync_include_path = '--include "' . $full_include_path . '"';

			// Read file into array
			$fileArr = file( $this->exclude_properties_file );

			// Remove any entries that match the specified path
			foreach ( $fileArr as $key => $line ) {
				if ( trim($line) == $path ) {
					unset( $fileArr[$key] );
				}
			}

			// Rewrite exclusions file
			file_put_contents( $this->exclude_properties_file, implode( '', $fileArr ) );

		}

		// Build the rsync command
		$rsync = sprintf( 'rsync -avz %s/ %s --exclude-from="%s" %s', $source_path, $destination_path, $this->exclude_properties_file, $rsync_include_path );

		// Are we doing a dry run?
		if ( array_key_exists( 'dryrun', $this->arguments ) ) {

			$rsync = $rsync . ' --dry-run';
			$this->output( "#################### TEST MODE ####################", Deployment::WARN);

		}
		if ( array_key_exists( 'delete', $this->arguments ) ) {

			$rsync = $rsync . ' --delete';

		}
		if ( !array_key_exists( 'quick', $this->arguments ) ) {

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
		$exclusions = file( $this->exclude_properties_file );
		foreach ( $exclusions as $exclusion ) {
			$this->output("    " . trim("$exclusion"), Deployment::DATA);
		}

		// $message = $c($message)->colorize();

		// Add additional global flags
		$rsync = $rsync . " --delay-updates";// --out-format='\x1b\033[90mdata\x1b\033[0m\t%n%L'";

		// Perform the sync
		if ( $this->confirm_action("Is this ok? (y/n)") ) {

			$this->output("Syncing files...", Deployment::INFO);
			// Only want to show files that will change or directories that will be deleted, not directories that will change
			system( $rsync . " | grep -E '^deleting|[^/]$' | sed -e 's/^.*$/\033[90mdata\033[97m:\t    \033[90m&\033[0m/'");
		}

		$this->output("deployment <green><bold>ok</bold></green>", Deployment::INFO);

	}




	/**
	 * Print action confirmation
	 */
	private function confirm_action( $message ) {

		$this->output("Is this <green><bold>ok?</bold></green> (y/n) ", Deployment::PROMPT);
		$handle = fopen("php://stdin", "r");
		$line = fgets($handle);
		if(trim($line) != 'y'){
			$this->output('PROCESS ABORTED!!', Deployment::ERROR);
		}

		return true;

	}

	/**
	 * Print info message
	 */
	private function output( $message , $level) {

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

		$line = "<". $levels[$level]['lcolor'] .">". $levels[$level]['name'] ."</". $levels[$level]['lcolor'] ."><white>:\t</white>";

		$pattern = array();
		$replacement = array();

		foreach ( $colors as $color ) {
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

		if ( $level != Deployment::PROMPT ) {
			$line .= PHP_EOL;
		}

		echo $line;

		if ( $level == Deployment::ERROR ) {
			exit;
		}

	}


	/**
	 * Tidy the branch name
	 */
	private function git_tidy_branch_name ( &$value ) {

		$value = str_replace(array('*', ' '), array('', ''), $value);

	}


	/**
	 * Display help
	 */
	static function help() {

		$c = new Color();

		$c->setTheme(
		    array(
		        'option' => 'yellow'
		    )
		);

		$text = <<<EOF
<bold>USAGE:</bold> 
	<option>deploy [options]</option>

	<bold>Parameters:</bold>
		<option>b</option> = Branch: defaults to current branch for staging and 'master' for live
		<option>p</option> = Path: sync a specifc path e.g. /assets
	
	<bold>Actions:</bold>
		<option>push</option> | <option>pull</option>   = Choose to upload or download (default is 'push' or upload)
		<option>live</option>        = Deploy to or from production (USE WITH CAUTION!!)
		<option>delete</option>      = Adds the delete flag to remove items that don't exist at destination 
				  (USE WITH CAUTION!!)
		<option>dryrun</option>      = Test mode - review what will happen
		<option>quick</option>       = Runs without checksum flag - this means rsync may list files that it says will change but won't

		<option>help</option>        = Displays this help

	<bold>Examples:</bold>
		<option>deploy</option>                    - lists valid servers available for deployment, no files are transferred
		<option>deploy s1 dryrun</option>          - runs in test mode, no files are transferred

		<option>deploy s1</option>                 - pushes current branch to staging site #1 from TEMP directory
		<option>deploy live</option>               - updates local copy of origin/master and pushes to production from TEMP directory
		<option>deploy s1 b=develop</option>       - pushes develop branch to staging site #1 from TEMP directory
		<option>deploy s1 pull p=/assets</option>  - download assets directory from staging site #1 to SOURCE directory


EOF;

		echo $c($text)->colorize();

	}

}
