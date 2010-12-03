<?php
/*
 * MIMAQ
 * Copyright 2010 MIMAQ
 * Released under a permissive license (see LICENSE)
 */

/**
 * holds MIMAQ configuration parameters
 */
class MimaqConfig {
	/**
	 * Local database configuration
	 *
	 */
	public static function getDbConf() {
		if (@$_SERVER['HTTP_HOST'] === 'localhost:8888') {
			// parameters for running under MAMP
			return array(
				'DSN' => 'mysql:host=localhost;dbname=mimaq;port=8889',
				'user' => 'root',
				'pass' => 'root'
			);
		} else {
			return array(
				'DSN' => 'mysql:host=localhost;dbname=mimaq',
				'user' => 'USERNAME',  // change this
				'pass' => 'PASSWORD'   // and this
			);
		}
	}
	
	/**
	 * Raw data source DB configuration
	 *
	 */
	public static function getDataDbConfig() {
			return array(
				'DSN' => 'mysql:host=vendor.com;dbname=XXXX;port=3306',
				'user' => 'USERNAME',
				'pass' => 'PASSWORD'
			);
	}
}
