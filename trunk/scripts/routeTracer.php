<?php
/*
 * MIMAQ RouteTracer
 * Copyright 2010 MIMAQ
 * Released under a permissive license (see LICENSE)
 *
 * @package MIMAQ
 * @author  joe
 */
require_once('../MimaqConfig.php');
 
class RouteTracer {
	const GRID_DIST   = 50;   // 50 m grid distance
	const MAX_TIMEOUT = 1800; // 30 minutes for trip restart at same location
	const MAX_DIST    = 500;  // 500 m distance to restart new trip
	
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
		
		$this->sample_sth = $this->db->prepare(
				'SELECT `id`, Y(`location`) as `lat`, X(`location`) as `lon` FROM `sample` ' . 
 				'WHERE `id` > (SELECT MAX(`sample_id`) FROM `grid50_sample`) ORDER BY `id` ASC LIMIT 1000'
			);
		$this->grid_sth = $this->db->prepare(
				'SELECT `id`, `location`, ' .
				' (6371000 * acos(cos(radians(:lat)) * cos(radians(Y(location))) * cos(radians(X(location)) - radians(:lon)) + sin(radians(:lat)) * sin(radians(Y(location))))) AS distance ' .
				' FROM `grid50` WHERE MBRIntersects(location, GeomFromText(:bbox)) ' .
				' HAVING `distance` < ' . self::GRID_DIST .
				' ORDER BY distance DESC LIMIT 1'
			);
		// statement for saving sample data
		$this->save_sample_sth = $this->db->prepare("INSERT INTO `grid50_sample` (`grid_id`, `sample_id`) VALUES (:grid_id, :sample_id)");
		$this->save_grid_sth = $this->db->prepare("INSERT INTO `grid50` (`location`) VALUES (PointFromText(:location))");
		// statement for keeping track of last frame ID
//		$this->state_sth = $this->db->prepare("UPDATE device set `last_frame_id` = :frame_id WHERE `bt_address`=:bt_address");
	}

	public function map_to_grid() {
		$res = $this->sample_sth->execute();
		if (!$this->sample_sth->rowCount()) {
			return FALSE;
		}
		while ($sampleRow = $this->sample_sth->fetch(PDO::FETCH_ASSOC)) {
			$bbox = $this->getBboxWKT($sampleRow['lon'], $sampleRow['lat'], self::GRID_DIST);
			$this->grid_sth->execute(array(
					'lat' => $sampleRow['lat'],
					'lon' => $sampleRow['lon'],
					'bbox' => $bbox,
				));
			if ($this->grid_sth->rowCount()) {
				$gridRow = $this->grid_sth->fetch(PDO::FETCH_ASSOC);
				$this->save_sample_sth->execute(array(
						'grid_id' => $gridRow['id'],
						'sample_id' => $sampleRow['id'],
					));
			} else {
				$this->save_grid_sth->execute(array(
						'location' => sprintf('POINT(%f %f)', $sampleRow['lon'], $sampleRow['lat']),
					));
	
				$last_grid_id = $this->db->lastInsertId() . "\n";
	
				$this->save_sample_sth->execute(array(
						'grid_id' => $last_grid_id,
						'sample_id' => $sampleRow['id'],
					));
			}
		}
		return TRUE;
	}
	
	private function getBboxWKT($lon, $lat, $dist = self::GRID_DIST) {
		$offsetLat = rad2deg(asin($dist/6371000));
		$offsetLon = $offsetLat / cos(deg2rad($lat));

		$latMin = $lat - $offsetLat;
		$latMax = $lat + $offsetLat;
		$lonMin = $lon - $offsetLon;
		$lonMax = $lon + $offsetLon;
		return(sprintf('POLYGON((%s %s,%s %s,%s %s,%s %s,%s %s))',
				$lonMin, $latMin, $lonMax, $latMin,
				$lonMax, $latMax, $lonMin, $latMax,
				$lonMin, $latMin
 			));
	}

} // END

$rt = new RouteTracer();
for (;;) {
	if (! $rt->map_to_grid()) {
		break;
	}
	print ".";
}
print "\nAll done.\n";

