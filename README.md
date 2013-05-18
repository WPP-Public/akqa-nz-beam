# Beam

A deployment tool

## Installation

### Locally

Download the [`beam.phar`](http://beam.heyday.net.nz/beam.phar) file and store it somewhere on your computer.

### Globally

#### With `wget`:

    $ sudo wget http://beam.heyday.net.nz/beam.phar -O /usr/local/bin/beam

#### With `curl`:

    $ sudo curl http://beam.heyday.net.nz/beam.phar -o /usr/local/bin/beam

then:

    $ sudo chmod +x /usr/local/bin/beam


## Updating

    $ beam self-update

## Usage

### Configuration

Each project you want to use `beam` with requires a `beam.json` file which configures the following things:


**Servers**

An object with arbitrarily named object properties

* `user` [the rsync, ftp or sftp user]
* `host` [the rsync, ftp or sftp host]
* `webroot`
* `branch` [optional]
* `password` [optional]

**Commands**

An array of objects

* `command`
* `location`
* `phase`
* `required` [optional]
* `tag` [optional]
* `tty` [optional]


**Excludes**

An object with two array properties `applications` and `patterns`

* `applications`
	* silverstripe, symfony, wordpress, zend
* `patterns`
	* rsync exclude format	 


#### Full example

```
{
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
  },
  "exclude": {
  	"applications": ["silverstripe"] 
    "patterns": ["*.md"]
  },
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
}
```

## Unit testing

    $ composer install --dev
    $ phpunit