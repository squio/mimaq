<?php

/**
 * @author joe
 *
 */
 require_once('MimaqConfig.php');
 
class Mapper {
	
	private $dbconf = array();
	
	private $levels = array(
		1 => array('limit' => 0.55, 'type' => 3, 'title' => 'NOx nivo zeer hoog',     'text' => 'Zeer veel vervuiling, andere route aanbevolen.', 'color' => '#cc0000'),
		2 => array('limit' => 1.10, 'type' => 3, 'title' => 'NOx nivo is hoog',       'text' => 'Veel vervuiling, probeer een andere route.', 'color' => '#ff3300'),
		3 => array('limit' => 1.65, 'type' => 2, 'title' => 'NOx meer dan gemiddeld', 'text' => 'Niet goed maar ook niet gevaarlijk.', 'color' => '#ffcc00'),
		4 => array('limit' => 2.20, 'type' => 2, 'title' => 'NOx minder dan gemiddeld', 'text' => 'Redelijke luchtkwaliteit.', 'color' => '#ffff00'),
		5 => array('limit' => 2.75, 'type' => 1, 'title' => 'NOx nivo is laag',       'text' => 'Mooi, hier is de luchtkwaliteit goed.', 'color' => '#ccff00'),
		6 => array('limit' => 3.30, 'type' => 1, 'title' => 'NOx nivo is zeer laag',  'text' => 'Beter kan niet, hier is schone lucht.', 'color' => '#00ff00'),
	);
	
	
	/**
	 * @var coords Array {lat1, lon1, lat2, lon2}
	 */
	private $coords = array();
	
	
	public function __construct() {
  		if (@$_SERVER['HTTP_HOST'] === 'localhost:8888') {
			ini_set('display_errors', 1);
			ini_set('error_reporting', E_ALL);
		}
		$this->dbconf = MimaqConfig::getDbConf();
		date_default_timezone_set('UTC');
	}

	public function getDevices() {
		$dbh = $this->initDb();
		
		$res = $dbh->query("SELECT `id`, `name` FROM `device` WHERE `active` = 1 ORDER BY `name`");
		$list = $res->fetchAll(PDO::FETCH_ASSOC);
		return $list;
	}
	
	public function getDates($device_id = null) {
		$dbh = $this->initDb();
		if (! $device_id) 
			return array();
		// start date July 20th, earlier results are unreliable
		$res = $dbh->query("SELECT DISTINCT(date(`datetime`)) as `date` FROM `sample` WHERE `device_id`=" . intval($device_id) . " AND `datetime` > '2010-07-20' ORDER BY `datetime` DESC");
		$list = $res->fetchAll(PDO::FETCH_ASSOC);
		return $list;
	}
	
	/**
	 * @return all pois acording to selection within bbox
	 * NOTE: setBBox should be called first!
	 */
	public function getArea() {
		$dbh = $this->initDb();
		$sql = "SELECT 
			g.`id`, 
			X(g.`location`) as `lon`,
			Y(g.`location`) as `lat`,
			count(*) as `count`,
			AVG(s.`NOx`) as NOx,
			AVG(s.`COx`) as COx,
			AVG(s.`noise`) as noise,
			AVG(s.`humidity`) as humidity,
			AVG(s.`temperature`) as temperature
		FROM `grid50` g, `grid50_sample` gs, `sample` s
		WHERE MBRContains(GeomFromText(:bbox), g.`location`)
		AND gs.`grid_id` = g.`id`
		AND gs.`sample_id` = s.`id`
			GROUP BY g.`id`
		LIMIT 500";
		
		$sth = $dbh->prepare($sql);
		$sth->execute(array(
				'bbox' => $this->bboxAsWKT()
			));
		return $this->getAggregatedPois($sth);
	}
	
	/**
	 * @param int $deviceId
	 * @param String $date
	 * @return all pois according to selection
	 */
	public function getTrack($deviceId, $date) {
		$dbh = $this->initDb();
		$sql = "SELECT
			`id`, 
			X(`location`) as `lon`, 
			Y(`location`) as `lat`, 
			CONCAT(DATE(`datetime`),'T',TIME(`datetime`),'+00:00') as `timestamp`,
			`NOx`,
			`COx`,
			`noise`,
			`humidity`,
			`temperature`
		FROM `sample`
		WHERE `device_id`=:id
		AND DATE(`datetime`)=:date
		ORDER BY `datetime` DESC
		LIMIT 10000"; // hard limit

		$sth = $dbh->prepare($sql);
		$sth->execute(array(
				'id' => $deviceId, 
				'date' => $date,
			));
		return $this->getPois($sth);
	}

	private function getAggregatedPois($sth) {
		$pois = array();
		
		$latMin = 90;
		$latMax = -90;
		$lonMin = 180;
		$lonMax = -180;
		$count = 0;
		while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
			$pois []= (Object) array(
				'lat' => (float)$row['lat'],
				'lon' => (float)$row['lon'],
				'count' => (int)$row['count'],
				'NOx' => (float)$row['NOx'], // raw sensor value
				'COx' => (float)$row['COx'], // raw sensor value
				'noise' => (float)$row['noise'],             // value in dBA
				'humidity' => (float)$row['humidity'],       // value in %
				'temperature' => (float)$row['temperature'], // value in ¡C
			);
			
			$latMin = min($latMin, $row['lat']);
			$lonMin = min($lonMin, $row['lon']);
			$latMax = max($latMax, $row['lat']);
			$lonMax = max($lonMax, $row['lon']);
			$count++;
		}
		return array(
			'results' => $count,
			'pois' => $pois,
			'bbox' => array((float)$latMin, (float)$lonMin, (float)$latMax, (float)$lonMax),
			'center' => array(($latMin + $latMax)/2, ($lonMin+$lonMax)/2),
		);
	}
	
	private function getPois($sth) {
		$pois = array();
		
		$latMin = 90;
		$latMax = -90;
		$lonMin = 180;
		$lonMax = -180;
		while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
			$pois []= (Object) array(
				'lat' => (float)$row['lat'],
				'lon' => (float)$row['lon'],
				'timestamp' => $row['timestamp'],
				// 'NOx' => 465.0 - 128.0 * $row['NOx'], // value in µg.m-3 based on NO2
				// 'COx' => 0.115 + 3.435 * $row['COx'], // value in mg.m-3
				'NOx' => (float)$row['NOx'], // raw sensor value
				'COx' => (float)$row['COx'], // raw sensor value
				'noise' => (float)$row['noise'],             // value in dBA
				'humidity' => (float)$row['humidity'],       // value in %
				'temperature' => (float)$row['temperature'], // value in ¡C
			);
			//$COx []=  30 * $row['COx'];
			
			$latMin = min($latMin, $row['lat']);
			$lonMin = min($lonMin, $row['lon']);
			$latMax = max($latMax, $row['lat']);
			$lonMax = max($lonMax, $row['lon']);
		}
		require_once('vendor/gchart/gChart.php');

		$count = count($pois);
		$NOx = array();
		$COx = array();
		$noise = array();
		$temperature = array();
		$humidity = array();
		if ($count > 100) {
			$factor = $count/100;
			$last = 0;
			for ($i = 0; $i < $count; $i++) {
				if (round($i/$factor) > $last) {
					$last = round($i/$factor);
					$NOx []= $pois[$i]->NOx;
					$COx []= $pois[$i]->COx;
					$noise []= $pois[$i]->noise;
					$humidity []= $pois[$i]->humidity;
					$temperature []= $pois[$i]->temperature;
				}
			}
		} else {
			foreach($pois as $p) {
				$NOx []= $p->NOx;
				$COx []= $p->COx;
				$noise []= $p->noise;
				$humidity []= $p->humidity;
				$temperature []= $p->temperature;
			}
		}
		
		$legend = array(
			$this->getTime($pois[$count - 1]->timestamp),
			$this->getTime($pois[round(3*$count/4)]->timestamp),
			$this->getTime($pois[round($count/2)]->timestamp),
			$this->getTime($pois[round($count/4)]->timestamp),
			$this->getTime($pois[0]->timestamp),
		);
		
		
		$chartUrl = $this->getLineChart(
				array_reverse($NOx), 
				$legend,
				array('','NOx (V out)'),
				"ff3344"
			);
			
		$chart = (Object) array(
			'NOx'=> $this->getLineChart(
					array_reverse($NOx), 
					$legend,
					'NOx (V out)',
					"ff3344"
				),
			'COx'=> $this->getLineChart(
					array_reverse($COx), 
					$legend,
					'COx (V out)',
					"ff3344"
				),
			'temperature'=> $this->getLineChart(
					array_reverse($temperature), 
					$legend,
					'Temp C',
					"ff3344"
				),
			'humidity'=> $this->getLineChart(
					array_reverse($humidity), 
					$legend,
					'Hum %',
					"ff3344"
				),
			'noise'=> $this->getLineChart(
					array_reverse($noise), 
					$legend,
					'dBA',
					"ff3344"
				),
			);
		
		return array(
			'results' => $count,
			'pois' => $pois,
			'bbox' => array((float)$latMin, (float)$lonMin, (float)$latMax, (float)$lonMax),
			'center' => array(($latMin + $latMax)/2, ($lonMin+$lonMax)/2),
			'chartUrl' => $chartUrl, // DEPRECATED
			'chart' => $chart
		);
	}
	
	private function getTime($timestamp) {
		$arr = date_parse($timestamp);
		return sprintf('%02d:%02d', $arr['hour'], $arr['minute']);
	}
	
	private function getLineChart($data, $xLabels, $legend, $color="ff3344") {
		$yRange = round($this->array_max($data));
		$lineChart = new gLineChart(790, 200);
		
		$lineChart->addDataSet($data);
		$lineChart->setLegend(array($legend));
		$lineChart->setColors(array($color));
		$lineChart->setVisibleAxes(array('x','y'));
		$lineChart->setDataRange(0, $yRange);
		$lineChart->addAxisRange(0, 0, count($data));
		$lineChart->addAxisLabel(0, $xLabels);
		$lineChart->addAxisRange(1, 0, $yRange);
//		$lineChart->addAxisLabel(1, $yLabels);
		$lineChart->setGridLines(round(100/(count($xLabels)-1)), 0);
		$lineChart->setEncodingType('s');
		return preg_replace('/&amp;/', '&', $lineChart->getUrl());
	}
	
	// return max. value of an array
	private function array_max($arr) {
		$max = 0;
		while ($val = array_pop($arr)) {
			$max = max($max, $val);
		}
		return $max;
	}
	
	private function getColor($row) {
		// air quality scale 0..100%
		// based on inverted sensor output voltage
		// between 0..3.3 Volt, where 0V = polluted and 3.3V = clean
		$NOx = $row['NOx'];
		$this->levels['level'] = 100 - round(100 * $row['NOx'] / 3.3);
		foreach ($this->levels as $level => $val) {
			if ($NOx < $val['limit']) {
				return $val['color'];
			}
		}
		// overflow, use max level
		return $val['color'];
	}

	
	public function trackAsGpx($id, $date) {
		$res = $this->getTrack($id, $date);
		$gpx = new SimpleXMLElement(
			'<gpx
	 version="1.0"
	 creator="MIMAQ http://mimaq.org"
	 xmlns:m="http://mimaq.org/ns/measurements/1/0"
	 xmlns="http://www.topografix.com/GPX/1/0"
	 	/>');
		$trk = $gpx->addChild('trk');
		$trk->addChild('name', 'MIMAQ track ' . $date);
		$trk->addChild('copyright', 'CC0 http://creativecommons.org/publicdomain/zero/1.0/'); // Almost the same as public domain
		$trk->addChild('link', 'http://mimaq.org');
		$t = $trk->addChild('trkseg');
		foreach($res['pois'] as $poi) {
			$p = $t->addChild('trkpt');
			$p->addAttribute('lat', $poi->lat);
			$p->addAttribute('lon', $poi->lon);
			$p->addChild('time', $poi->timestamp);
			$e = $p->addChild('extensions');
			$e->addChild('m:NOx', $poi->NOx, 'http://mimaq.org/ns/measurements/1/0');
			$e->addChild('m:COx', $poi->COx, 'http://mimaq.org/ns/measurements/1/0');
			$e->addChild('m:noise', $poi->noise, 'http://mimaq.org/ns/measurements/1/0');
			$e->addChild('m:humidity', $poi->humidity, 'http://mimaq.org/ns/measurements/1/0');
			$e->addChild('m:temperature', $poi->temperature, 'http://mimaq.org/ns/measurements/1/0');
		}
		header("Content-Type: text/xml");

		print($gpx->asXML());
		exit;
		
	}
	
	public function setBBox($lat1, $lon1, $lat2, $lon2) {
		$this->coords = Array($lat1, $lon1, $lat2, $lon2);
	}
	
	private function bboxAsWKT() {
		$coords = $this->coords;
 		return(sprintf('POLYGON((%s %s,%s %s,%s %s,%s %s,%s %s))',
 				floatval($coords[1]), floatval($coords[0]), 
				floatval($coords[3]), floatval($coords[0]),
 				floatval($coords[3]), floatval($coords[2]), 
				floatval($coords[1]), floatval($coords[2]),
 				floatval($coords[1]), floatval($coords[0])
 			));
	}
	
	/**
	 * @return PDO $dbh
	 * Throws Exception
	 */
	private function initDb() {
		$dbh = new PDO($this->dbconf['DSN'], $this->dbconf['user'], $this->dbconf['pass']);
		$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$dbh->query('set names UTF8');
		return $dbh;
	}
	
}

header('Content-type: application/json');

$m = new Mapper();

$json = null;
switch (@$_REQUEST['type']) {

	case 'area':
		$m->setBBox(floatval($_REQUEST['neLat']), floatval($_REQUEST['neLon']), floatval($_REQUEST['swLat']), floatval($_REQUEST['swLon']));
		$json = json_encode($m->getArea());
		break;

	case 'track':
		$json = json_encode($m->getTrack(
			intval(@$_REQUEST['id']), 
			preg_replace('/^.*?(\d{4}-\d{2}-\d{2}).*?$/', "$1", @$_REQUEST['date'])		
		));
		break;
		
	case 'track-gpx':
		$m->trackAsGpx(
			intval(@$_REQUEST['id']), 
			preg_replace('/^.*?(\d{4}-\d{2}-\d{2}).*?$/', "$1", @$_REQUEST['date'])		
		);
		break;
		
	case 'devicelist':
		$json = json_encode($m->getDevices());
		break;

	case 'datelist':
		$json =  json_encode($m->getDates(intval($_REQUEST['id'])));
		break;
		
	default:
		$json = json_encode(array('status' => 'error', 'message' => 'Unknown Type'));
}

if (isset($_REQUEST['callback'])) {
	// JSONP: wrap everything in a callback function
	$callback = preg_replace('/[^\w_\.]/', '', $_REQUEST['callback']);
	printf('%s(%s);', $callback, $json);
} else {
	// Regular JSON result
	print $json;
}


?>