<?php

// Do not modify this file, but override the configuration
// in a config_local.php file based on config_local_template.php

global $config;
$config = (object) array();


$config->db = (object) array();
$config->db->host = 'localhost';
$config->db->database = 'castor';
$config->db->password = 'castor';
$config->db->user = 'castor';
$config->db->logged = false;

$config->timezone = ini_get('date.timezone');

if (is_readable(__DIR__.'/config_local.php')) {
   include_once __DIR__.'/config_local.php';
}

date_default_timezone_set($config->timezone);
