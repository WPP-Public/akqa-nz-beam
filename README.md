# Beam

Beam is a command line utility for deploying websites to servers. It allows you to sync files between a version control
system and a remote host, and run shell commands at fixed stages to further automate the deployment process. Beam works
best (and by default) using `rsync` over `ssh`, though it also has support for intelligent deployment through SFTP
and FTP.

## Installation

Download the [`beam.phar`](http://beam.heyday.net.nz/beam.phar) file and store it somewhere on your computer.

#### With `wget`:

    $ sudo wget http://beam.heyday.net.nz/beam.phar -O /usr/local/bin/beam

#### With `curl`:

    $ sudo curl http://beam.heyday.net.nz/beam.phar -o /usr/local/bin/beam

then:

    $ sudo chmod +x /usr/local/bin/beam
    
## Requirements

* PHP 5.3+
* `--with-zlib` compile flag
* `detect_unicode=Off` (php.ini setting)
* `php-ssh2` extension (only if you need SFTP)

## Updating

    $ beam self-update

## Configuration

Beam requires a config file named `beam.json` to know where to sync your files. Typically, each project you use Beam
with will have its own `beam.json` file, as each project will have it's own deployment location(s). When a config file
is not found in the current directory, Beam will backtrack through parent directories and use the first config it finds.

To generate a blank config with a valid schema run:

```bash
$ beam init
```

For further configuration, see the [documentation for the `beam.json` file](CONFIG.md).

### Basic `beam.json`

At a minimum to use, one or more server needs to be defined.

```json
{
	"servers": {
		"live": {
			"user": "user",
			"host": "some.host.com",
			"webroot": "/home/user/www"
		}
	}
}
```

## Usage examples

Given a valid [configuration file](CONFIG.md) here are some common ways to use Beam:

```bash
$ beam up live                   # regular sync from git
$ beam up staging --dry-run      # don't offer to sync the files, just display changes
$ beam up live --no-prompt       # skips the summary of files to be changed and doesn't prompt for confirmation
$ beam up staging-2 -v           # verbose (see the output of commands)
$ beam up live --no-delete       # don't delete files on target that are not present on local
$ beam up myserver -p somepath   # only sync the specified path
$ beam up live --command-prompt  # prompt on non-required commands
$ beam up vm -t sometag          # include commands tagged with "sometag"
$ beam up live --working-copy    # sync from the working-copy not a vcs archive
$ beam up live -r HEAD~2         # sync 2 back from HEAD
$ beam up live -r def3c6d57      # sync a specific commit
$ beam down live                 # dowload to working copy
$ beam down staging -p assets    # dowload a specific folder to working copy
```

# Help

## FAQs

### When I run `beam` I see something like "?? ???"

This means that you have `detect_unicode=On` in your `php.ini`. To fix this, open your `php.ini` (ensure it is your
cli one) and make sure `detect_unicode=Off` is present.

## IRC

Help is available at `#beam` on freenode.

## Unit testing

    $ composer install --dev
    $ phpunit