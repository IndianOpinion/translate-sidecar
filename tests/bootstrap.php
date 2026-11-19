<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/fake-wordpress/');

require __DIR__ . '/stubs/classes.php';
require __DIR__ . '/stubs/WpStub.php';
require __DIR__ . '/stubs/functions.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/functions.php';

WpStub::reset();
