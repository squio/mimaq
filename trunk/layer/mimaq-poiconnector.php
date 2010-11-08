<?php
/*
 * MIMAQ
 * Copyright 2010 MIMAQ
 * Released under a permissive license (see LICENSE)
 */

require_once('../MimaqConfig.php');

class MimaqPOIConnector extends POIConnector {
	const IMAGE_BASE = 'api.worldservr.com/mimaq/gfx/';
	const IMAGE_BASE_URL = 'http://api.worldservr.com/mimaq/gfx/';
	const MAX_RESULTS = 50;
	const MIN_DIST = 50; // all POIs closer than MIN_DIST meters are grouped together
	
	private $dbconf = array();
	/**
	 * @var $levels sensor values translated
	 * limit: upper voltage limit
	 * title: title
	 * text: explanation
	 * type: Layar POI type
	 */
	private $levels = array(
		1 => array('limit' => 0.55, 'title' => 'NOx nivo zeer hoog',     'text' => 'Zeer veel vervuiling, andere route aanbevolen.', 'type' => 3),
		2 => array('limit' => 1.10, 'title' => 'NOx nivo is hoog',       'text' => 'Veel vervuiling, probeer een andere route.', 'type' => 3),
		3 => array('limit' => 1.65, 'title' => 'NOx meer dan gemiddeld', 'text' => 'Niet goed maar ook niet gevaarlijk.', 'type' => 2),
		4 => array('limit' => 2.20, 'title' => 'NOx minder dan gemiddeld', 'text' => 'Redelijke luchtkwaliteit.', 'type' => 2),
		5 => array('limit' => 2.75, 'title' => 'NOx nivo is laag',       'text' => 'Mooi, hier is de luchtkwaliteit goed.', 'type' => 1),
		6 => array('limit' => 3.30, 'title' => 'NOx nivo is zeer laag',  'text' => 'Beter kan niet, hier is schone lucht.', 'type' => 1),
		'level' => '00'
	);
		
	
	public function __construct($source) {
 		if (@$_SERVER['HTTP_HOST'] === 'localhost:8888') {
			ini_set('display_errors', 1);
			ini_set('error_reporting', E_ALL);
		}
		$this->dbconf = MimaqConfig::getDbConf();
		date_default_timezone_set('UTC');
		// parent::__construct($this->dbconf['DSN'], $this->dbconf['user'], $this->dbconf['pass']);
	}

	public function getPois(Filter $filter = null) {
		if (!empty($filter)) {
			// set radius to pre-determined radius
			$radius = $filter->radius;
			$lat = $filter->lat;
			$lon = $filter->lon;
			$accuracy = $filter->accuracy;
		}
		
		$result = array();
		try {
			$pdo = $this->getPDO();
			$sql = "SELECT 
				g.`id`, 
				X(g.`location`) as `lon`,
				Y(g.`location`) as `lat`,
			 	(6371000 * acos(cos(radians(:lat)) * cos(radians(Y(g.`location`))) * cos(radians(X(g.`location`)) - radians(:lon)) + sin(radians(:lat)) * sin(radians(Y(g.`location`))))) AS `distance`
			FROM `grid50` g
			WHERE MBRContains(GeomFromText(:bbox), g.`location`)
			LIMIT 50"; // hard limit 50
			
			$stmt = $pdo->prepare($sql);
			$param = array(
				'lat' => $filter->lat,
				'lon' => $filter->lon,
				'bbox' => $this->getBboxAsWKT($filter),
				'radius' => $filter->radius
			);
			$stmt->execute($param);

			$sql = "SELECT 
				s.`datetime`,
				s.`NOx`
			FROM `grid50_sample` gs
			LEFT JOIN `sample` s ON gs.`sample_id` = s.`id`
				WHERE gs.`grid_id` = :grid_id
				ORDER BY  s.`datetime` DESC
			LIMIT 1"; // hard limit 50
			$poi_stmt = $pdo->prepare($sql);


		} catch (Exception $e) {
			// hmm
			// print $e->getMessage();
		}
		
		// need POI Clusterer here:
		// http://www.appelsiini.net/2008/11/introduction-to-marker-clustering-with-google-maps
		
		$markers = array();
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			
			$poi_stmt->execute(array('grid_id' => $row['id']));
			$row = array_merge($row, $poi_stmt->fetch(PDO::FETCH_ASSOC));		
			
			$level = $this->getLevel($row);
			$type = $this->levels[$level]['type'];
			
			$poi = array(
				'id' => $row['id'],
				'lat' => $row['lat'],
				'lon' => $row['lon'],
				'imageURL' => self::IMAGE_BASE_URL . 'icn' . $type . '.png',
				'title' => $this->levels[$level]['title'],
				'line2' => $this->levels[$level]['text'],
				'line3' => sprintf('NOx niveau %d%% van sensor max.', $this->levels['level']),
				'line4' => 'meting ' . $this->getRelativeTime($row['datetime']),
				'distance' => $row['distance'],
				'type' => $type,
				'attribution' => '(c) MIMAQ CC by-nc-sa',
				'dimension' => 2,
				'doNotIndex' => false,
				'relativeAlt' => 3,
//				'actions' => array(
//					array(
//						'uri' => 'layar://' . $filter->layerName. '/?action=refresh&SEARCHBOX=', 
//						'label' => 'Terug naar start'
//					),
//				)
			);
			$poi['object'] = array(
				'baseURL' => self::IMAGE_BASE_URL,
				'full' => $type . '.png',
				'reduced' => 'mid' . $type . '.png',
				'icon' => 'icn' . $type . '.png',
				'size' => 1.5 // full image is 300px = 1.5 meters
			);

			$poi['transform'] = array(
				'rel' => 1,
				'angle' => 0,
				'scale' => 5,
			);
			
			$result []= new POI2D($poi);
		
		}
		return $result;
	}
		
	
	private function getBboxAsWKT(Filter $filter) {
		$offsetLat = asin($filter->radius/GeoUtil::EARTH_RADIUS)*180/M_PI;
		$offsetLon = $offsetLong = $offsetLat / cos($filter->lat*M_PI/180);
		$latMin = $filter->lat - $offsetLat;
		$latMax = $filter->lat + $offsetLat;
		$lonMin = $filter->lon - $offsetLon;
		$lonMax = $filter->lon + $offsetLon;
		return(sprintf('POLYGON((%s %s,%s %s,%s %s,%s %s,%s %s))',
				$lonMin, $latMin, $lonMax, $latMin,
				$lonMax, $latMax, $lonMin, $latMax,
				$lonMin, $latMin
 			));
	}
		
		

	/**
	 * Determine type of POI based on environmental values
	 * 
	 * @return int $level
	 * 1 = clean
	 * 6 = polluted
	 * @param array $row measurement data from database
	 * @see $levels 
	 */
	private function getLevel($row) {
		// air quality scale 0..100%
		// based on inverted sensor output voltage
		// between 0..3.3 Volt, where 0V = polluted and 3.3V = clean
		$NOx = $row['NOx'];
		$this->levels['level'] = 100 - round(100 * $row['NOx'] / 3.3);
		foreach ($this->levels as $level => $val) {
			if ($NOx < $val['limit']) {
				return $level;
			}
		}
		// overflow, use max level
		return $level;
	}


	private function getRelativeTime($date) {
		$diff = time() - strtotime($date);
		if ($diff<60)
			return $diff . " seconde" . (($diff != 1) ? 'n' : '') . " geleden";
		$diff = round($diff/60);
		if ($diff<60)
			return $diff . " minu" . (($diff != 1) ? 'ten' : 'ut') . " geleden";
		$diff = round($diff/60);
		if ($diff<24)
			return $diff . " uur geleden";
		$diff = round($diff/24);
		if ($diff<7)
			return $diff . " da"  . (($diff != 1) ? 'gen' : 'g') . " geleden";
		$diff = round($diff/7);
		if ($diff<4)
			return $diff . " we" . (($diff != 1) ? 'ken' : 'ek') . " geleden";
		$loc = setlocale(LC_TIME, array('NL', 'dut', 'nl'));
		$str = "op " . date("F j, Y", strtotime($date));
		setlocale(LC_TIME, $loc);
		return $str;
	}
	

	/**
	 * Cluster all close POIs together 
	 *
	 */
	private function cluster($markers, $min_dist = self::MIN_DIST) {
	    $clustered = array();
	    /* Loop until all markers have been compared. */
	    while (count($markers)) {
	        $reference  = array_pop($markers);
	        $cluster = array();
	        /* Compare against all markers which are left. */
	        foreach ($markers as $key => $target) {
	        	$distance = GeoUtil::getGreatCircleDistance($reference['lat'], $reference['lon'],
	                                    $target['lat'], $target['lon'], true);
	            /* If two markers are closer than given distance remove */
	            /* target marker from array and add it to cluster.      */
	            if ($min_dist > $distance) {
//	                printf("Distance between %s,%s and %s,%s is %d m.\n", 
//	                    $reference['lat'], $reference['lon'],
//	                    $target['lat'], $target['lon'],
//	                    $distance);
	                unset($markers[$key]);
	                $cluster[] = $target;
	            }
	        }
	
	        /* If a marker has been added to cluster, add also the one  */
	        /* we were comparing to and remove the original from array. */
	        if (count($cluster) > 0) {
	            $cluster[] = $reference;
	            $clustered[] = $this->maximum($cluster);
	        } else {
	        	$clustered[] = $reference;
	        }
	        // limit to max. 50 POIs
	        if (count($clustered) > self::MAX_RESULTS) return $clustered;
	    }
	    return $clustered;
	}


	/**
	 * Average all relevant numerical fields of an array of clustered POIs
	 *
	 */
	private function average($cluster) {
		$numericValues = array('lon', 'lat', 'NOx', 'COx', 'noise', 'humidity', 'temperature', 'battery', 'distance');
		$count = count($cluster);
		$avg = $cluster[0]; // initialize non numeric values from first POI
		foreach($numericValues as $k) {
			$avg[$k] = 0;
		}
		foreach ($cluster as $c) {
			foreach($numericValues as $k) {
				$avg[$k] += $c[$k]/$count;
			}
		}
		return $avg;
	}

	/**
	 * Return max. of all relevant numerical fields of an array of clustered POIs
	 *
	 */
	private function maximum($cluster) {
		$numericValues = array('lon', 'lat', 'NOx', 'COx', 'noise', 'humidity', 'temperature', 'battery', 'distance');
		$max = $cluster[0]; // initialize non numeric values from first POI
		foreach($numericValues as $k) {
			$max[$k] = 0;
		}
		foreach ($cluster as $c) {
			foreach($numericValues as $k) {
				$max[$k] = max($max[$k], $c[$k]);
			}
		}
		return $max;
	}
	
	// public function deletePOI($poiID) {
	// 	// dummy
	// }
	// 
	// public function storePOIs(array $pois, $mode = "update") {
	// 	// dummy
	// }
	// 
	// public function setOption($optionName, $optionValue) { 
	// 	// dummy
	// }

	/**
	 * Get PDO instance
	 *
	 * @return PDO
	 */
	protected function getPDO() {
		if (empty($this->pdo)) {
			$this->pdo = new PDO ($this->dbconf['DSN'], $this->dbconf['user'], $this->dbconf['pass']);

			$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			// force UTF-8 (Layar talks UTF-8 and nothing else)
			$sql = "SET NAMES 'utf8'";
			$stmt = $this->pdo->prepare($sql);
			$stmt->execute();
		}
		return $this->pdo;
	}
	
}

?>