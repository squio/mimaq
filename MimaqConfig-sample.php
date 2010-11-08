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
}
