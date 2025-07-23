#!/usr/bin/env php
<?php
Phar::mapPhar('beam.phar');
require 'phar://beam.phar/bin/beam';
__HALT_COMPILER();
