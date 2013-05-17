# Beam configuration

This file is a complete documentation of the file `beam.json` used to configure `beam`. Beam cannot operate without a valid `beam.json` file in the working directory, so creating one is a good place to start if you want to use `beam`.

If you are looking for an example configuration to get you started, look in `README.md` or run `beam genconfig` to create a minimal config file in your working directory.

## Brief overview

Beam is a command line utility for deploying websites to servers. Its basic function is the synchronization of files between a version control system and a host + location. It can also be configured to run commands to further automate the deployment process. Beam works best using `rsync` over `ssh`, though it also has support for intelligent deployment through SFTP and FTP.

Beam has the following workflow assumptions:

 * You are using a version control system (Git is the only supported VCS at this time).
 * You want to sync the head of a branch to a server.
 * You want to be very sure about what is going to happen when you sync
 * You may want to exclude files and folders from the deployment
 * You may have multiple servers with different purposes (ie. testing and live)
 * You may want to run custom commands at different stages of deployment, locally and on the target server.
 * You want to do all of this with a command as simple as `beam up live`

As well as 'beaming' up, `beam` can also 'beam' down; synchronizing your working copy with what's on a server. You can also do a working copy `beam up`, send a specific branch, and do a dry-run to simulate a sync. For a full list of options run `beam --help`.

### Order of operations

To give a clear picture of what a `beam up` does with no command line options, here's a high-level list:

1. Export the head of a branch from your repository to a temporary directory
2. Run commands defined as `phase: pre`, `location: local` in the temporary export location
3. Do a dry-run and display a breakdown of exactly what will happen if you sync
4. Prompt to continue (or exit when no changes to sync)
5. Run commands defined as `phase: pre`, `location: target` in the deployment location on the target server
6. Perform the actual sync
7. Run commands defined as `phase: post`, `location: local` in the temporary export location
8. Run commands defined as `phase: post`, `location: target` in the deployment location on the target server


## Servers

    "servers": {
        "test": {
            "user": "user",
            "host": "some.hostname",
            "webroot": "subdomains/staging"
        },
        "live": {
            "user": "user",
            "host": "some.host.name",
            "webroot": "public_html",
            "branch": "remotes/origin/master"
        }
    }

Servers are individual, named deployment targets. When using `beam up` or `beam down`, a server name referencing a server config is required. You can only work with one server per beam command, and at least one server must be defined to use Beam.

**The following properties are required for each defined server:**

 * **`user`** - Username to log into the server with
 * **`host`** - Host name or IP address to log into ther server with
 * **`webroot`** - Path to the deployment directory on the server. Relative paths are relative to the user's home directory. A trailing slash is optional.

**Optional properties:**

 * **`branch`** *(string)* - Branch to lock this server to. When specified, a `beam up` to this server will always send the branch specified here, regardless of the currently checked out branch and the `--branch` and `--workingcopy` flags. This is useful for ensuring that only one branch can be deployed to, for example, your production server. Any git branch is valid here, including remote branches like `remotes/origin/master`.

 * **`password`** *(string)* - Password to use for (S)FTP deployments. This is not used by the default (`rsync`) deployment method.


## Exclude

    "exclude" : {
        "applications" : [
            "silverstripe"
        ],
        "patterns" : [
            "/heystack/cache/container.php",
            "/heystack/cache/countries.cache",
            "/heystack/cache/currencies.cache"
            "/cache/*",
            "*.tmp"
        ]
    }

The `exclude` section allows you to exclude files from syncs. Pre-defined exclude patterns for specific applications can also be specified. A built-in list of excludes is always applied, which excludes the 'beam.json' file amongst others (`*~`, `composer.json`, `.git`, `.gitignore`, etc).

When using the `rsync` deployment method (default), patterns are passed directly to `rsync`'s' `--exclude` option. Rsync has fairly extensive pattern support which will not be covered here, but can be found in the Rsync man page.

When using (S)FTP, patterns are interpreted internally by beam and follow the basic rules of rsync's path matching.

Valid values for **`applications`** are: `gear`, `silverstripe`, `symfony`, `wordpress` and `zf`


## Commands

    "commands": [
        {
            "command": "composer install",
            "location": "local",
            "phase": "pre",
            "required": true
        },
        {
            "command": "composer dump-autoload -o",
            "location": "local",
            "phase": "pre"
        },
        {
            "command": "clearcachetask",
            "location": "target",
            "phase": "post"
        }
    ]

Beam allows arbitrary commands to be executed at certain points in the deployment process on both the local machine and the target. Commands are executed in order of location and phase, then order defined. Commands are always executed in the temporary git export for `local` commands, and in the defined `webroot` for `target` commands.

Command output is suppressed unless the `-v` option is used, or if a command exits with a non-zero status. In the case a command fails, beam will prompt to continue unless the failed command is marked as required.

Note that running commands on a target requires an SSH connection to the target. The SFTP and FTP deployment methods do not support running commands on the target due to this limitation.

**Each command must define:**

 * **`command`** - Command to execute. This can be is anything you would normally type on a shell
 * **`phase`** - Phase of deployment to execute the command in: `pre` or `post` for before or after the sync occurs
 * **`location`** - What machine to run the command on: `local` or `target`

**Additionally, the following can be specified:**

 * **`servers`** *(array)* - A list of server configs by name that a command is limited to. When this option is not defined, the command will run when deploying to any server.
 * **`tag`** *(string)* - A tag for use with the `--tags (-t)` option. Tagged commands are not run unless a their tag is specified when `beam` is run. Multiple commands can have the same tag.
 * **`required`** *(boolean: false)* - Specifies that a command is required for the deployment to complete successfully. Required commands do not prompt when `--command-prompt` is used, are run regardless of tags, and beam will abort if a required command fails.
 * **`tty`** *(boolean: false)* - Whether the command requires a terminal (TTY) environment. Any command that requires user input/interaction will need this option enabled to work correctly. When set to true, the i/o streams (stdin, stderr, stdout) of the command process are connected to the current terminal instead of being managed internally by `beam`.