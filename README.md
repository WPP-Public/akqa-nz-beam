# Beam

Beam is a command line utility for deploying websites to servers. Its basic function is the synchronization of files between a version control system and a server. It can also be configured to run shell commands to further automate the deployment process. Beam works best using `rsync` over `ssh`, though it also has support for intelligent deployment through SFTP and FTP.

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

## Usage

Beam uses a config file to know where to sync your files. Which `beam.json` config file it uses depends on what directory beam is run from. Beam will look for a config file in the current directory and all directories above preferring the first that it finds.  

### [Configuration](CONFIG.md)

Each project you intend to use `beam` with requires a `beam.json` configuration file.

#### Basic `beam.json`

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

#### Config generation

To generate a blank config with a valid schema run:

```bash
$ beam init
```

### Examples

```bash
$ beam up live                   # regular sync from git
$ beam up live --dry-run         # don't offer to sync the files, just display changes
$ beam up live --no-prompt       # skips the summary of files to be changed and doesn't prompt for confirmation
$ beam up live -v                # verbose (see the output of commands)
$ beam up live --no-delete       # don't delete files on target that are not present on local
$ beam up live -p somepath       # only sync the specified path
$ beam up live --command-prompt  # prompt on non-required commands
$ beam up live -t sometag        # include commands tagged with "sometag"
$ beam up live --working-copy    # sync from the working-copy not a vcs archive
$ beam up live -r HEAD~2         # sync 2 back from HEAD
$ beam up live -r def3c6d57      # sync a specific commit
$ beam down live                 # dowload from live to working copy
```

# Help

## FAQs

### When I run `beam` is see something like "?? ???"

This means what you have the `detect_unicode=On`in your `php.ini`. To fix, open your `php.ini` (ensure it is your cli one) and make sure `detect_unicode=Off` is present.

## IRC

Help is available at `#beam` on freenode.

## Unit testing

    $ composer install --dev
    $ phpunit