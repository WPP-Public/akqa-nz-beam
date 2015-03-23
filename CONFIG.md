# Beam configuration

This file is a complete documentation of the file `beam.json` that is used to configure `beam`. Beam cannot operate without a valid `beam.json` file in the working directory, so creating one is a good place to start if you want to use `beam`.

If you are looking for an example configuration to get you started, look in [`README.md`](README.md) or run `beam init` to create a minimal config file in your working directory.

## Brief overview

Beam has the following workflow assumptions:

 * You are using a version control system (Git is the only supported VCS at this time).
 * You want to sync the head of a branch or a specific commit to a server.
 * You want to be very sure about what is going to happen when you sync
 * You may want to exclude files and folders from the deployment
 * You may have multiple servers with different purposes (ie. testing and live)
 * You may want to run custom commands at different stages of deployment, locally and on the target server.
 * You want to do all of this with a command as simple as `beam up live`

As well as 'beaming' up, `beam` can also 'beam' down; synchronising your working copy with what's on a server. You can also do a working copy `beam up`, send a specific branch, and do a dry-run to simulate a sync. For a full list of options run `beam up --help`.

### Order of operations

To give a clear picture of what a `beam up my-target` does with no command line options, here's a high-level list:

1. Export the head of a branch from your repository to a temporary directory
1. Run commands defined as `phase: pre`, `location: local` in the temporary export location
1. Do a dry-run and display a breakdown of exactly what will happen if you sync
1. Prompt to continue (or exit when no changes to sync)
1. Prompt again if files will be deleted
1. Run commands defined as `phase: pre`, `location: target` in the deployment location on the target server
1. Perform the actual sync
1. Run commands defined as `phase: post`, `location: local` in the temporary export location
1. Run commands defined as `phase: post`, `location: target` in the deployment location on the target server
1. Clean up the temporary export location


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

 * `user` - User to log into the server with
 * `host` - Host name or IP address of the server
 * `webroot` - Path to the deployment directory on the server. Relative paths are relative to the user's home directory. A trailing slash is optional.

### Optional properties
 * `type` *(string: rsync)* - Transfer method to use with the server. This must be one of `rsync`, `ftp`, and `sftp` (FTP over SSH).
 * `branch` *(string)* - Branch to lock this server to. When specified, a `beam up` to this server will always send this branch, regardless of the currently checked out branch and the `--ref` and `--working-copy` flags. This is useful for ensuring that only one branch can be deployed to, for example, your production server. Any git branch is valid here, including remote branches like `remotes/origin/master`.

### (S)FTP properties

When `type` is set to 'ftp' or 'sftp', a number of FTP specific properties are available:

**FTP & SFTP:**

 * `password` *(string)* - Password to connect with. Beam will prompt for a password where one is not specified in the config.

**FTP only:**

 * `passive` *(boolean: false)* - Run the FTP session in passive mode.
 * `ssl` *(boolean: false)* - Make the FTP connection over SSL (FTPS)

### Rsync properties

 * `sshpass` *(boolean: false)* - Use the program [`sshpass`](http://sourceforge.net/projects/sshpass/) to enter your SSH password automatically when using password authentication. With this option enabled, Beam will prompt for an SSH password once instead of an SSH client prompting for each new connection. Key-based authentication is reccommeded, though this may not suit everyone. To use this option you will need to have the `sshpass` program accessible on your path.


## Exclude

    "exclude" : {
        "patterns" : [
            "/cache/*",
            "/silverstripe-cache/*",
            "*.tmp"
        ]
    }

The `exclude` section allows you to exclude files from all deployments. Pre-defined exclusion patterns for specific applications can also be specified. A built-in list of excludes is always applied, which excludes the 'beam.json' file amongst others (`*~`, `composer.json`, `.git`, `.gitignore`, etc).

When using the `rsync` deployment method (default), patterns are passed directly to `rsync`'s `--exclude` option. Rsync has fairly extensive pattern support which will not be covered here, but can be found in the Rsync man page.

When using (S)FTP, exclusion patterns are handled internally by beam (crudely relative to rsync) and follow the basic rules of rsync's path matching.


## Commands

    "commands": [
        {
            "command": "composer install --prefer-dist --no-dev",
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

Beam allows arbitrary shell commands to be executed at certain points in the deployment process on both the local machine and the target. Commands are executed in order of location, phase, and defined order. Commands are always executed with the working directory set to the temporary git export for `local` commands, and in the defined `webroot` for `target` commands.

Command output is suppressed unless beam is run with the verbose (`-v`) flag, a command's `tty` option is true, or if a command exits with a non-zero status. In the case a command fails, beam will prompt to continue unless the failed command is marked as required.

Note that running commands on a target requires an SSH connection to the target. The SFTP and FTP deployment methods do not support running commands on the target due to this limitation.

**Each command must define:**

 * `command` - Command to execute. This can be is anything you would normally type on a shell
 * `phase` - Phase of deployment to execute the command in: `pre` or `post` upload to the target
 * `location` - What machine to run the command on: `local` or `target`

**Additionally, the following can be specified:**

 * `servers` *(array)* - A list of server configs by name that a command is limited to. When this option is defined, the command will only run on the specified servers. When not defined a command will run when deploying to any server.
 * `tag` *(string)* - A tag for use with the `--tags (-t)` option. Tagged commands are not run unless their tag is specified when `beam` is run. Multiple commands can have the same tag.
 * `required` *(boolean: false)* - Specifies that a command is required for the deployment to complete successfully. Required commands do not prompt when `--command-prompt` is used, are run regardless of tags, and beam will abort if a required command fails.
 * `tty` *(boolean: false)* - Whether the command should be run in a terminal (TTY) environment. Any command that requires user input/interaction will need this option enabled to work correctly. When set to true, the i/o streams (stdin, stderr, stdout) of the command process are connected to the current terminal instead of being managed internally by `beam`.


## Import

    "import": [
        "~/configs/another-beam-config.json",
        "http://example.com/silverstripe-config.json"
    ]
    
The `import` config option is an array of filenames that provides a way to merge multiple beam.json files together. Using imports, common settings can be used across multiple projects without duplication and managing shared options becomes easier.

The values in `import` can be anything accepted by PHP's `file_get_contents`, including but not limited to HTTP URLs and local file paths. A tilde at the start of a path is replaced with the path to the current user's home directory. Imports are fetched recursively (ie. imported configs can import further configs) with each unique path being fetched only the first time it appears.

## Dynamic interpolated values

The following tokens are recognized as dynamically interpolated values:

<dl>
<dt>%%branch%%
    <dd>the branch that is being pushed from (`git symbolic-ref --short HEAD`)
<dt>%%branch_pathsafe%%
    <dd>same as %%branch%%, but changes each path separator to a hyphen
<dt>%%commit%%
    <dd>the commit hash being pushed up
<dt>%%commit_abbrev%%
    <dd>the abbreviated hash being pushed up
<dt>%%user%%
    <dd>the username of the user running the beam process (`id -un`)
<dt>%%user_fullname%%
    <dd>the full name of the user running the beam process (`id -F`)
</dl>

Sample configuration fragment:

```json
"servers": {
    "live": {
        "user": "operator",
        "host": "www.example.com",
        "webroot": "/usr/local/www/%%branch_pathsafe%%/shared/cached-copy",
        "branch": "master"
    },
```