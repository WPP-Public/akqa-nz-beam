# Beam

[![Build Status](https://travis-ci.org/heyday/beam.png?branch=master)](https://travis-ci.org/heyday/beam)

Beam is a command line utility for deploying websites to servers. It allows you to sync files between a version control
system and a remote host, and run shell commands at fixed stages to further automate the deployment process. Beam works
best (and by default) using `rsync` over `ssh`, though it also has support for intelligent deployment through SFTP
and FTP.

## Installation

```bash
$ curl -s https://getbeam.io/installer -O; php installer
```

Note: this will create a file called `installer` and then delete it after the installation has completed.

## Updating

    $ beam self-update

## Configuration

Beam requires a config file named `beam.json` to know where to sync your files. Typically, each project you use Beam
with will have its own `beam.json` file, as each project will have its own deployment location(s). When a config file
is not found in the current directory, Beam will backtrack through parent directories and use the first config it finds.

To generate a blank config with a valid schema run:

```bash
$ beam init
```

For further configuration, see the [documentation for the `beam.json` file](CONFIG.md).

### Basic `beam.json`

At a minimum, to use Beam at least one server needs to be defined.

```json
{
	"servers": {
		"live": {
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

## Help

### FAQs

#### When I run `beam` I see something like "?? ???"

This means that you have `detect_unicode=On` in your `php.ini`. To fix this, open your `php.ini` (ensure it is your
cli one) and make sure `detect_unicode=Off` is present.

#### When I run `beam` I see no output

If you are using `suhosin`, you will need to add `phar` to the [whitelist of allowed executor url schemes](http://www.hardened-php.net/suhosin/configuration.html#suhosin.executor.include.whitelist). To fix this, open your
`php.ini` (ensure it is your cli one) and make sure that `suhosin.executor.include.whitelist=phar` is present.

### IRC

Help is available at `#beam` on freenode.

## Contributing

This project follows the standards defined in:

* [PSR-0](http://www.php-fig.org/psr/0/)
* [PSR-1](http://www.php-fig.org/psr/1/)
* [PSR-2](http://www.php-fig.org/psr/2/)


## Unit testing

    $ composer install
    $ phpunit

# License

MIT, see LICENSE.
