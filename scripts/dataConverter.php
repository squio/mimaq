<?php
/*
 * MIMAQ
 * Copyright 2010 MIMAQ
 * Released under a permissive license (see LICENSE)
 */

ini_set('display_errors', 1);
ini_set('error_reporting', E_ALL);

/**
 * Based on Cop15 / ConversionTool
 * 
 * Retrieves and converts raw data from Sensaris database and stores 
 * full measurement events in local MIMAQ database
 * 
 * @author joe
 * 
 * RAW data example:
 * $GPRMC,112706.000,V,5209.7957,N,00428.1346,E,0.00,0.00,280510,,,N*77
 * $PSEN,Hum,H,40.07,T,21.70
 * $PSEN,Batt,V,3.84
 * $PSEN,Noise,dB,042
 * $PSEN,NOx,V,2.877
 * $PSEN,COx,V,1.448
 *
 */
require_once('../MimaqConfig.php');
 
 class DataConverter {

	private $dbconf = array();
	
	public function __construct() {
 		if (@$_SERVER['HTTP_HOST'] === 'localhost:8888') {
			ini_set('display_errors', 1);
			ini_set('error_reporting', E_ALL);
		}
		$this->dbconf = MimaqConfig::getDbConf();
		$this->db = new PDO(
				$this->dbconf['DSN'], 
				$this->dbconf['user'], 
				$this->dbconf['pass']
			);
		$this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		
		// statement for saving sample data
		$this->save_sth = $this->db->prepare("INSERT INTO sample (location, datetime, NOx, COx, humidity, temperature, noise, battery, device_id) " .
					"VALUES (PointFromText(:location), :datetime, :NOx, :COx, :humidity, :temperature, :noise, :battery, " .
					"(SELECT d.id FROM device d WHERE d.bt_address=:bt_address ))");
		// statement for keeping track of last frame ID
		$this->state_sth = $this->db->prepare("UPDATE device set `last_frame_id` = :frame_id WHERE `bt_address`=:bt_address");
	}

	/**
	 * Main processing loop
	 */
	public function main() {
		$dbconf = MimaqConfig::getDataDbConfig();
		$src_db = new PDO(
				$dbconf['DSN'], 
				$dbconf['user'], 
				$dbconf['pass']
			);
		$src_sth = $src_db->prepare("SELECT `id`, `frame` FROM `frame` WHERE `id` > :last_frame_id AND `device_bt_address`=:bt_addr");

		foreach($this->getActiveDevices() as $device) {

			$collector = new DataCollector($this, $device['bt_address']);

			//printf("Start processing events for device %s, last frame ID=%s\n", $device['name'], $device['last_frame_id']);
			$res = $src_sth->execute(array('last_frame_id' => $device['last_frame_id'], 'bt_addr' => $device['bt_address']));
			$numEvents = $src_sth->rowCount(); 
			if ($numEvents) {
				while ($row = $src_sth->fetch(PDO::FETCH_ASSOC)) {
					$collector->parseFrame($row['frame'], $row['id']);
				}
				printf("Device %s: updated %s, processed %d events since Frame ID %d\n", 
						$device['name'], 
						$this->getLastTimestamp($device['id']),
						$numEvents,
						$device['last_frame_id']
					);
			} else {
				printf("Device %s: updated %s, no new events since Frame ID %d\n", 
						$device['name'], 
						$this->getLastTimestamp($device['id']),
						$device['last_frame_id']
					);
			}
		}
	}
	
	/**
	 * @return list of {id, name,bt_address,last_frame_id} for each active device
	 */
	public function getActiveDevices() {
		$res = $this->db->query("SELECT `id`, `name`, `bt_address`, `last_frame_id` FROM `device` WHERE `active`=1");
		$list = $res->fetchAll(PDO::FETCH_ASSOC);
		return $list;
	}
	
	
	/**
	 * Persist complete sample data
	 * 
	 * @param Array
	 */
	public function save(array $d) {
		// printf("Saving sample at %s\n", @$d['datetime']);
		// these fields are required
		foreach(array('location', 'datetime', 'NOx') as $field) {
			if (!isset($d[$field])) {
				// $d[$field] = 0;
				printf(">>> Warning: field %s is missing, skipping POI.\n", $field);
				return;
			}
		}
		// add missing fields
		foreach(array('COx', 'humidity', 'temperature', 'noise', 'battery') as $field) {
			if (!isset($d[$field])) {
				$d[$field] = 0;
			}
		}

		$loc = $d['location'];
		$long = $loc['long'];
		$lat = $loc['lat'];
		
		// Store location as OpenGIS point type: POINT(X Y)
		$d['location'] = sprintf('POINT(%f %f)', $long, $lat);	

		$state = array(
			'frame_id' => $d['frame_id'],
			'bt_address' => $d['bt_address'],
		);
		unset($d['frame_id']);
		
		// go ahead and save point
		try {
			$this->save_sth->execute($d);
			$this->state_sth->execute($state);
			// print_r($d);
/*			print ".";*/
		} catch (Exception $e) {
			print $e;
		}
		//print "Added timestamp " . $d['datetime'];
	}

	/**
	 * @return string timestamp of most recent data point
	 * Example: '2009-12-03 15:15:42'
	 */
	public function getLastTimestamp($device_id = 0) {
		$id = intval($device_id);
		$sql = 'SELECT MAX(`datetime`) as `t` FROM `sample`';
		if ($id) {
			$sql .= ' WHERE `device_id`=' . $id;
		}
		$sth = $this->db->query($sql);
		if ($sth) {
			$r = $sth->fetch();
			return $r['t'];
		} else {
			return '1970-01-01 00:00:00';
		}
	}

	
	/**
	 * @return int frame ID of last processed full event
	 * Example: 12345
	 */
	public function getLastFrameId() {
		$sth = $this->db->query('SELECT MAX(`last_frame_id`) as id FROM `device`');
		if ($sth) {
			$r = $sth->fetch();
			return $r['id'];
		} else {
			return 0;
		}
	}
	
}

/**
 * 
 * @author joe
 *
 * DataCollector parses and aggregates all 'frame' events for a single measurement device
 */
class DataCollector {
	private $data = array(); // buffer
	private $battery = 0;    // battery voltage can be measured less often, keep in separate buffer
	private $bt_address = NULL;
	private $state = 0;
	
	const STATE_BATT = 1;
	const STATE_GPS  = 2;
	const STATE_RTC  = 4;
	const STATE_COX  = 8;
	const STATE_NOX  = 16;
	const STATE_DBA  = 32;
	const STATE_TEMP = 64;
	const STATE_HUM  = 128;
	
	const STATE_ALL  = 255;
	
	public function __construct($save_obj, $bt_address) {
		$this->bt_address = $bt_address;
		$this->save_obj = $save_obj;
		$this->resetBuffer();
	}

	/**
	 * Parses the 'frame' strings from the data acquisition stream
	 * @return void
	 * @param string $str
	 * @param int $id the original frame ID
	 */
	public function parseFrame($str, $id) {
		if (!strlen($str)) return;
		$raw = explode(',', $str);
		if (count($raw) < 2) return;
		
		// keep track of last seen frame ID
		$this->data['frame_id'] = $id;
		
		if ('$PSEN' === $raw[0]) {
			// position 1 is type, call method "parseType(string)"
			$method = 'parse' . $raw[1];
			if (method_exists($this, $method)) {
				call_user_func(array($this, $method), $str);			
			}
		} elseif ('$GPRMC' === $raw[0]) {
			$this->parseGps($str);
		} elseif ('$PINF' === $raw[0]) {
			// $PINF,Shutdown,T,1
			// add hook for shutdown event here
/*			printf("Info: %s\n", $str);*/
		} else {
/*			printf("Skipping line: %s\n", $str);*/
		}
		// save all data when all events are complete
		if ($this->state == self::STATE_ALL) {
/*			print ".";*/
			$this->save();
		}
	}
	
	// callback method is passed ad construction time
	private function save() {
		call_user_func(array($this->save_obj, 'save'), $this->getData());
		$this->resetBuffer();
	}
	
	/**
	 * @return Array sample data
	 *
	 */
	public function getData() {
		return $this->data;
	}
		
	private function parseAcc($str) {
		// $PSEN,Acc,X,-0134,Y,-0112,Z, 1068
		throw new Exception('Not implemented: ' . __METHOD__);
	}
	
	
	private function parseGyro($str) {
		// $PSEN,Gyro,X,-1350,Y,-1347,Z,-1347
		throw new Exception('Not implemented: ' . __METHOD__);
	}
	

	private function parseGps($str) {
		// 0      1          2      3         4     5          6     7        8         9     10+
		// $GPRMC,022517.753,V     ,5541.3951,N    ,01233.2076,E    ,0.00    ,0.00     ,031209,,,N*76
		// type  ,time      ,status,lat      ,{N/S},long      ,{E/W},velocity,direction,date  ,,,
		// latitude: DDMM.MMMM
		// longitude: DDDMM.MMMM
		// status is A if a GPS fix was acquired, V otherwise
		$raw = explode(',', $str, 10);

		if (count($raw) < 10) {
/*			printf("incomplete event id=%s\n", $this->data['frame_id'] );*/
			return;
		}
		if ($raw[2] !== 'A') {
/*			printf("Invalid GPS data (no fix?) for id=%s\n", $this->data['frame_id'] );*/
			return;
		}
		// Location
		$lat = (float)substr($raw[3], 0, 2) + ((float)substr($raw[3], 2) / 60);
		if ('S' === $raw[4]) {
			$lat *= -1.0;
		}
		$this->state |= self::STATE_GPS;
		
		$long = (float)substr($raw[5], 0, 3) + ((float)substr($raw[5], 3) / 60);
		if ('W' === $raw[6]) {
			$long *= -1.0;
		}
		$this->data['location'] = array('long' => $long, 'lat' => $lat);

		// Date / Time
		$this->data['datetime'] = sprintf('20%s-%s-%s %s:%s:%s',
				substr($raw[9], 4, 2), substr($raw[9], 2, 2), substr($raw[9], 0, 2),
				substr($raw[1], 0, 2), substr($raw[1], 2, 2), substr($raw[1], 4, 2) 
			);
		$this->state |= self::STATE_RTC;
	}
	

	private function parseRTC($str) {
		// 0     1   2    3      4    5
		// $PSEN,RTC,Date,091203,Time,032518
		$raw = explode(',', $str);

		// Format date string in date plus relative time, 
		// to convert strange values like '2009-12-16 24:01:49' ==> '2009-12-17 00:01:49'
		$timestamp = sprintf('20%s-%s-%s 00:00:00 +%s Hour +%s Minute +%s Second',
				substr($raw[3], 0, 2),
				substr($raw[3], 2, 2),
				substr($raw[3], 4, 2),
				substr($raw[5], 0, 2),
				substr($raw[5], 2, 2),
				substr($raw[5], 4, 2)
		 	);
		// parse relative timestamp
		$timestamp = date('Y-m-d H:i:s', strtotime($timestamp));
		$this->data['datetime'] = $timestamp; 
		$this->state |= self::STATE_RTC;
	}
	
	private function parseHum($str) {
		// 0     1   2 3     4 5
		// $PSEN,Hum,H,48.91,T,21.28
		$raw = explode(',', $str);
		$this->data['humidity'] = $raw[3];		
		$this->data['temperature'] = $raw[5];
		$this->state |= self::STATE_HUM | self::STATE_TEMP;
	}
	
	private function parseNoise($str) {
		// 0     1     2  3   4 5
		// $PSEN,Noise,dB,010,G,0400
		$raw = explode(',', $str);
		$this->data['noise'] = (float)$raw[3];		
		$this->state |= self::STATE_DBA;
	}

	private function parseNOx($str) {
		// 0    1    2 3
		// $PSEN,NOx,V,03.07
		$raw = explode(',', $str);
		$this->data['NOx'] = (float)$raw[3];		
		$this->state |= self::STATE_NOX;
	}

	private function parseCOx($str) {
		// 0    1    2 3
		// $PSEN,COx,V,02.72
		$raw = explode(',', $str);
		$this->data['COx'] = (float)$raw[3];		
		$this->state |= self::STATE_COX;
	}

	private function parseBatt($str) {
		// 0    1     2 3
		// $PSEN,Batt,V,4.11
		$raw = explode(',', $str);
		$this->data['battery'] = $this->battery = (float)$raw[3];
		$this->state |= self::STATE_BATT;
	}
	
	/**
	 * clean data buffer
	 * @return
	 */
	protected function resetBuffer() {
		$this->data = array('battery' => $this->battery, 'bt_address' => $this->bt_address);
		$this->state = ($this->battery) ? self::STATE_BATT : 0;
	}
	
	
} // DataCollector

/******************* MAIN ****/


$converter = new DataConverter();

header('Content-type: text/plain');

$res = $converter->main();

if ($res) {
	print "Events processed: " . $res;
}

// (cd /var/www/vhosts/worldservr.com/subdomains/api/httpdocs/layar/cop15/lib && /bin/date > conversionlog.txt && /usr/bin/php -f conversionTool.php >>conversionlog.txt)


//$raw = file_get_contents('../doc/1/091202.log');
//foreach(preg_split("[\n|\r]", $raw) as $str) {
//	$converter->parseEntry($str);	
//}


?>