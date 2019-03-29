<?php

if (!function_exists('json_decode')) {
    throw new Exception('Swell API library requires the JSON PHP extension');
}

$basedir = dirname(__FILE__);
require_once($basedir.'/Cache.php');
require_once($basedir.'/Client.php');
require_once($basedir.'/Connection.php');
require_once($basedir.'/Resource.php');
require_once($basedir.'/Collection.php');
require_once($basedir.'/Record.php');
