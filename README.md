# Beam

Beam is a command line utility for deploying websites to servers. It allows you
to sync files between a version control system and a remote host, and run shell
commands at fixed stages to further automate the deployment process. Beam works
best (and by default) using `rsync` over `ssh`, though it also has support for
intelligent deployment through SFTP and FTP.

## Installation

```bash
git clone git@github.com:WPP-Public/akqa-nz-beam.git beam
cd beam
composer install
php ./bin/installer
```

## Configuration

Beam requires a config file named `beam.json` to know where to sync your files.
Typically, each project you use Beam with will have its own `beam.json` file, as
each project will have its own deployment location(s). When a config file is not
found in the current directory, Beam will backtrack through parent directories
and use the first config it finds.

To generate a blank config with a valid schema run:

```bash
$ beam init
```

For further configuration, see the [documentation for the `beam.json`
file](CONFIG.md).
la
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

### AWS SSM SSH tunneling

Beam can tunnel rsync and remote commands over [AWS Systems Manager Session
Manager](https://docs.aws.amazon.com/systems-manager/latest/userguide/session-manager.html)
instead of opening a direct SSH port. Set `host` to the EC2 instance ID and
enable `ssm` on an `rsync` server:

```json
{
	"servers": {
		"live": {
			"user": "ec2-user",
			"host": "i-0abc123def456",
			"identityFile": "~/.ssh/id_ed25519",
			"webroot": "/var/www/html",
			"ssm": true
		}
	}
}
```

Because the `host` is an instance ID rather than a hostname, `Host` blocks in
`~/.ssh/config` no longer match, so SSH cannot pick up the login user or key
from there. Set them in `beam.json` instead:

-   `user` is **required** with `ssm`. Without it SSH falls back to your local
    username and the connection fails with `Permission denied (publickey)`.
-   `identityFile` should point at the key the instance accepts. Beam passes it
    as `ssh -i` along with `IdentitiesOnly=yes`, so agent keys aren't offered
    ahead of it.

Optional `region` and `profile` can be set when Beam should call the AWS CLI
with explicit credentials context:

```json
{
	"servers": {
		"live": {
			"user": "ec2-user",
			"host": "i-0abc123def456",
			"identityFile": "~/.ssh/id_ed25519",
			"webroot": "/var/www/html",
			"ssm": {
				"region": "ap-southeast-2",
				"profile": "deploy"
			}
		}
	}
}
```

The first connection to a new instance ID prompts to accept its host key. To
accept new host keys without prompting (while still rejecting changed ones),
add `sshOptions`:

```json
"sshOptions": ["StrictHostKeyChecking=accept-new"]
```

Prerequisites on the machine running Beam:

- AWS CLI v2 with the [Session Manager
  plugin](https://docs.aws.amazon.com/systems-manager/latest/userguide/session-manager-working-with-install-plugin.html)
- IAM permission for `ssm:StartSession` on both:
  - the target instance (`arn:aws:ec2:REGION:ACCOUNT:instance/i-…`)
  - the SSH session document (`arn:aws:ssm:REGION::document/AWS-StartSSHSession`)
- An SSH key that the instance accepts (SSM only replaces the network path)

Beam uses `--document-name AWS-StartSSHSession` so Session Manager can act as an
SSH `ProxyCommand` for rsync. A plain interactive session
(`aws ssm start-session --target i-…` without that document) can succeed while
SSH tunneling still fails if the document is missing from the IAM policy.

Then deploy as usual:

```bash
$ beam up live
$ beam ssm login live    # write portal credentials to ~/.aws/credentials
$ beam ssm tunnel live   # interactive SSH via SSM
```

Run `beam list` for all commands, or `beam ssm --help` for login/tunnel usage.

## Usage examples

Given a valid [configuration file](CONFIG.md) here are some common ways to use
Beam:

```bash
$ beam up                        # prompt for a target, then regular sync from git
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
$ beam status live               # show deployed commit and local branches containing it
```

## Help

### FAQs

#### When I run `beam` I see something like "?? ???"

This means that you have `detect_unicode=On` in your `php.ini`. To fix this,
open your `php.ini` (ensure it is your cli one) and make sure
`detect_unicode=Off` is present.

#### When I run `beam` I see no output

If you are using `suhosin`, you will need to add `phar` to the [whitelist of
allowed executor url
schemes](http://www.hardened-php.net/suhosin/configuration.html#suhosin.executor.include.whitelist).
To fix this, open your `php.ini` (ensure it is your cli one) and make sure that
`suhosin.executor.include.whitelist=phar` is present.

## Unit testing

```sh
composer run test
```

# License

MIT, see LICENSE.
