<?php

/*
 * PorPOISe
 * Copyright 2009 SURFnet BV
 * Released under a permissive license (see LICENSE)
 */

/**
 * PorPOISe entry point
 *
 * @package PorPOISe
 */
if (@$_SERVER['HTTP_HOST'] === 'localhost:8888') {
	ini_set('display_errors', 1);
	ini_set('error_reporting', E_ALL);
}

define("PORPOISE_CONFIG_PATH", "../../layer");
/* 
 * Change working directory to where the rest of PorPOISe resides.
 *
 * For security reasons it is advisable to make only this file
 * accessible from the web. Other files may contain sensitive
 * information (especially config.xml), so you'll want to keep
 * them away from spying eyes!
 */
chdir(dirname(__FILE__) . "/vendor/PorPOISe");

/**
 * Include PorPOISe
 */
require_once("porpoise.inc.php");

/* start of server*/

/* use most strict warnings, enforces neat and correct coding */
error_reporting(E_ALL | E_STRICT);

/* open config file */
try {
	$config = new PorPOISeConfig("../layer/config.xml");
} catch (Exception $e) {
	printf("Error loading configuration: %s", $e->getMessage());
}

/* create server */
$server = LayarPOIServerFactory::createLayarPOIServer($config);

/* handle the request, and that's the end of it */
$server->handleRequest();
