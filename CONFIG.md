# Beam configuration

This file is a complete documentation of the file `beam.json` that is used to
configure `beam`. Beam cannot operate without a valid `beam.json` file in the
working directory, so creating one is a good place to start if you want to use
`beam`.

If you are looking for an example configuration to get you started, look in
[`README.md`](README.md) or run `beam init` to create a minimal config file in
your working directory.

## Brief overview

Beam has the following workflow assumptions:

-   You are using a version control system (Git is the only supported VCS at
    this time).
-   You want to sync the head of a branch or a specific commit to a server.
-   You want to be very sure about what is going to happen when you sync
-   You may want to exclude files and folders from the deployment
-   You may have multiple servers with different purposes (ie. testing and live)
-   You may want to run custom commands at different stages of deployment,
    locally and on the target server.
-   You want to do all of this with a command as simple as `beam up live`

As well as 'beaming' up, `beam` can also 'beam' down; synchronising your working
copy with what's on a server. You can also do a working copy `beam up`, send a
specific branch, and do a dry-run to simulate a sync. For a full list of options
run `beam up --help`.

### Order of operations

To give a clear picture of what a `beam up my-target` does with no command line
options, here's a high-level list:

1. Export the head of a branch from your repository to a temporary directory
1. Run commands defined as `phase: pre`, `location: local` in the temporary
   export location
1. Do a dry-run and display a breakdown of exactly what will happen if you sync
1. Prompt to continue (or exit when no changes to sync)
1. Prompt again if files will be deleted
1. Run commands defined as `phase: pre`, `location: target` in the deployment
   location on the target server
1. Perform the actual sync
1. Run commands defined as `phase: post`, `location: local` in the temporary
   export location
1. Run commands defined as `phase: post`, `location: target` in the deployment
   location on the target server
1. Clean up the temporary export location

## Servers

    "servers": {
        "test": {
            "user": "user",
            "host": "some.hostname",
            "webroot": "subdomains/staging"
        },
        "live": {
            "host": "some.host.name",
            "webroot": "public_html",
            "branch": "remotes/origin/master"
        }
    }

Servers are individual, named deployment targets. When using `beam up` or `beam
down`, pass a server name referencing a server config, or omit it to be prompted
for one. You can only work with one server per beam command, and at least one
server must be defined to use Beam.

**The following properties are required for each defined server:**

-   `host` - Host name or IP address of the server. When using `ssm`, this
    should be the EC2 instance ID (for example `i-0abc123def456`).
-   `webroot` - Path to the deployment directory on the server. Relative paths
    are relative to the user's home directory. A trailing slash is optional.

### Optional properties

-   `user` - User to log into the server with
-   `type` _(string: rsync)_ - Transfer method to use with the server. This must
    be one of `rsync`, `ftp`, and `sftp` (FTP over SSH).
-   `branch` _(string)_ - Branch to lock this server to. When specified, a `beam
    up` to this server will always send this branch, regardless of the currently
    checked out branch and the `--ref` and `--working-copy` flags. This is
    useful for ensuring that only one branch can be deployed to, for example,
    your production server. Any git branch is valid here, including remote
    branches like `remotes/origin/master`.

### (S)FTP properties

When `type` is set to 'ftp' or 'sftp', a number of FTP specific properties are
available:

**FTP & SFTP:**

-   `password` _(string)_ - Password to connect with. Beam will prompt for a
    password where one is not specified in the config.

**FTP only:**

-   `passive` _(boolean: false)_ - Run the FTP session in passive mode.
-   `ssl` _(boolean: false)_ - Make the FTP connection over SSL (FTPS)

### Rsync properties

-   `identityFile` _(string)_ - Path to the SSH private key to authenticate
    with, passed to SSH as `-i`. A leading `~` is expanded to your home
    directory. Beam also sets `IdentitiesOnly=yes` so keys held by `ssh-agent`
    are not offered ahead of this one. This is normally only needed with `ssm`,
    where the `host` is an instance ID and so `Host` blocks in `~/.ssh/config`
    can't supply a key.
-   `sshOptions` _(array of string)_ - Extra OpenSSH options, each in
    `Keyword=value` form, passed through as `-o`. For example
    `["StrictHostKeyChecking=accept-new", "ServerAliveInterval=30"]`.
-   `sshpass` _(boolean: false)_ - Use the program
    [`sshpass`](http://sourceforge.net/projects/sshpass/) to enter your SSH
    password automatically when using password authentication. With this option
    enabled, Beam will prompt for an SSH password once instead of an SSH client
    prompting for each new connection. Key-based authentication is reccommeded,
    though this may not suit everyone. To use this option you will need to have
    the `sshpass` program accessible on your path. Cannot be combined with
    `ssm`.
-   `ssm` _(boolean|object: false)_ - Tunnel SSH/rsync through [AWS Systems
    Manager Session
    Manager](https://docs.aws.amazon.com/systems-manager/latest/userguide/session-manager.html)
    using `aws ssm start-session` as an OpenSSH `ProxyCommand`. Set `host` to
    the EC2 instance ID (for example `i-0abc123def456`). Requires the AWS CLI
    and Session Manager plugin on your `PATH`. Cannot be combined with
    `sshpass`.

    Beam always passes `--document-name AWS-StartSSHSession` so the session can
    be used as an OpenSSH `ProxyCommand`. IAM must allow `ssm:StartSession` on
    that document as well as the instance — permission for interactive
    `aws ssm start-session --target …` alone is not enough.

    Because `host` is an instance ID, `Host`/`HostName` blocks in
    `~/.ssh/config` no longer match it, so SSH cannot inherit a login user or
    key from there. `user` is therefore **required** when `ssm` is enabled —
    Beam fails with a clear error rather than letting SSH fall back to your
    local username and die with `Permission denied (publickey)`. Set
    `identityFile` too unless the key is one of SSH's defaults.

    When `ssm` is `true`, Beam uses your default AWS credentials/region. When
    set as an object, the following optional fields are available:

    -   `region` _(string)_ - Passed to the AWS CLI as `--region`
    -   `profile` _(string)_ - Passed to the AWS CLI as `--profile`
    -   `portalUrl` _(string)_ - AWS access portal URL opened by
        `beam ssm login`. Required for login; set per environment in
        `beam.json`.

    Example:

        "live": {
            "user": "ec2-user",
            "host": "i-0abc123def456",
            "identityFile": "~/.ssh/id_ed25519",
            "webroot": "/var/www/html",
            "ssm": {
                "region": "ap-southeast-2",
                "profile": "deploy",
                "portalUrl": "https://example.awsapps.com/start/#/"
            }
        }

    Helpers:

    -   `beam ssm login [target]` — opens `ssm.portalUrl`, prompts for
        temporary access keys, and writes them to `~/.aws/credentials` under
        `ssm.profile` (or `default`).
    -   `beam ssm tunnel [target]` — opens an interactive SSH session using the
        same SSM `ProxyCommand` as deployments. Omit `target` to choose from
        SSM-enabled servers.

-   `database` _(object)_ - Optional configuration for `beam down <target>
    --db`. Pulls a MySQL dump over SSH/SSM. Authentication uses the remote
    deploy user's `~/.mysql.cnf` (MySQL option file) — never store passwords in
    `beam.json`.

    -   `name` _(string)_ - Database name. When omitted, Beam reads
        `SS_DATABASE_NAME` from the remote `.env` under `webroot`.
    -   `host` _(string: localhost)_ - Database host as reachable from the
        remote server. When `name` is omitted, `SS_DATABASE_SERVER` from the
        remote `.env` is used when present.
    -   `remoteDumpPath` _(string)_ - Remote path for the gzipped dump. Defaults
        to `/tmp/beam-<target>-db.sql.gz`.
    -   `localPath` _(string: .ddev/.downloads/%target%-db.sql.gz)_ - Where to
        save the dump locally. `%target%` is replaced with the server name.
        Relative paths are relative to the project (beam.json) directory.
    -   `importCommand` _(string)_ - Optional local command run after download.
        `%s` is replaced with a shell-escaped path to the dump file (for
        example `ddev import-db --file=%s`).
    -   `compatTransforms` _(boolean: true)_ - Rewrite utf8mb4 charset/
        collation markers for older local MariaDB/MySQL compatibility.

    Example remote `~/.mysql.cnf` on the deploy user:

        [client]
        user=dbuser
        password=secret

    Example `beam.json` fragment:

        "database": {
            "importCommand": "ddev import-db --file=%s"
        }

-   `assets` _(object)_ - Optional configuration for `beam down <target>
    --assets` and `beam up <target> --assets`.

    -   `path` _(string: public/assets)_ - Assets directory relative to
        `webroot` on the remote.
    -   `localPath` _(string)_ - Local directory relative to the project.
        Defaults to the same value as `path`.
    -   `ensureWritable` _(boolean: false)_ - When pushing, run
        `sudo chmod -R 777` on the remote assets directory first.
    -   `excludes` _(array)_ - Extra rsync `--exclude` patterns. Defaults to
        common Silverstripe resampled image suffixes (`*__Fill*`, etc).

-   `syncPermissions` _(boolean: true)_ - Sync permissions (file mode) of
    transferred files and directories. Set this to `false` to let the target
    filesystem control file mode. This is on by default for backwards
    compatibility.
-   `timeout` _(int: 120)_ - Timeout for rsync call.

## Exclude

    "exclude" : {
        "patterns" : [
            "/cache/*",
            "/silverstripe-cache/*",
            "*.tmp"
        ]
    }

The `exclude` section allows you to exclude files from all deployments.
Pre-defined exclusion patterns for specific applications can also be specified.
A built-in list of excludes is always applied, which excludes the 'beam.json'
file amongst others (`*~`, `composer.json`, `.git`, `.gitignore`, etc).

When using the `rsync` deployment method (default), patterns are passed directly
to `rsync`'s `--exclude` option. Rsync has fairly extensive pattern support
which will not be covered here, but can be found in the Rsync man page.

When using (S)FTP, exclusion patterns are handled internally by beam (crudely
relative to rsync) and follow the basic rules of rsync's path matching.

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

Beam allows arbitrary shell commands to be executed at certain points in the
deployment process on both the local machine and the target. Commands are
executed in order of location, phase, and defined order. Commands are always
executed with the working directory set to the temporary git export for `local`
commands, and in the defined `webroot` for `target` commands.

Command output is suppressed unless beam is run with the verbose (`-v`) flag, a
command's `tty` option is true, or if a command exits with a non-zero status. In
the case a command fails, beam will prompt to continue unless the failed command
is marked as required.

Note that running commands on a target requires an SSH connection to the target.
The SFTP and FTP deployment methods do not support running commands on the
target due to this limitation.

**Each command must define:**

-   `command` - Command to execute. This can be is anything you would normally
    type on a shell
-   `phase` - Phase of deployment to execute the command in: `pre` or `post`
    upload to the target
-   `location` - What machine to run the command on: `local` or `target`

**Additionally, the following can be specified:**

-   `servers` _(array)_ - A list of server configs by name that a command is
    limited to. When this option is defined, the command will only run on the
    specified servers. When not defined a command will run when deploying to any
    server.
-   `tag` _(string)_ - A tag for use with the `--tags (-t)` option. Tagged
    commands are not run unless their tag is specified when `beam` is run.
    Multiple commands can have the same tag.
-   `required` _(boolean: false)_ - Specifies that a command is required for the
    deployment to complete successfully. Required commands do not prompt when
    `--command-prompt` is used, are run regardless of tags, and beam will abort
    if a required command fails.
-   `tty` _(boolean: false)_ - Whether the command should be run in a terminal
    (TTY) environment. Any command that requires user input/interaction will
    need this option enabled to work correctly. When set to true, the i/o
    streams (stdin, stderr, stdout) of the command process are connected to the
    current terminal instead of being managed internally by `beam`.

## Import

    "import": [
        "~/configs/another-beam-config.json",
        "http://example.com/silverstripe-config.json"
    ]

The `import` config option is an array of filenames that provides a way to merge
multiple beam.json files together. Using imports, common settings can be used
across multiple projects without duplication and managing shared options becomes
easier.

The values in `import` can be anything accepted by PHP's `file_get_contents`,
including but not limited to HTTP URLs and local file paths. A tilde at the
start of a path is replaced with the path to the current user's home directory.
Imports are fetched recursively (ie. imported configs can import further
configs) with each unique path being fetched only the first time it appears.

## Dynamic interpolated values

```json
"servers": [
    "live": {
        "user": "%%username%%",
        "host": "www.example.com",
        "webroot": "/usr/local/www/%%branch_pathsafe%%/shared/cached-copy",
        "branch": "master"
    },
]
```

Beam offers some support for using dynamic values in configs by way of token
replacement. The following tokens are recognized and will be replaced
automatically in free-text config values where they are used:

<dl>
<dt>%%branch%%
    <dd>The branch name that is being deployed. If a commit hash is passed to <code>--ref</code> on the command line, this is a best-guess of what the branch is, since a commit can be on multiple branches.
<dt>%%branch_pathsafe%%
    <dd>The same as <code>%%branch%%</code>, but changes each path separator to a hyphen
<dt>%%commit%%
    <dd>The commit hash being deployed
<dt>%%commit_abbrev%%
    <dd>The abbreviated hash being deployed
<dt>%%target%%
    <dd>The name of the server config being used for deployment.
<dt>%%username%%
    <dd>The username of the user running the beam process
<dt>%%user_identity%%
    <dd>The full name and email address of the current user according to the VCS. Eg. <code>Joe Smith &lt;joe.smith@example.com&gt;</code>
</dl>
