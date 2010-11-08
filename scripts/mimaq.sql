

SET SQL_MODE="NO_AUTO_VALUE_ON_ZERO";

-- 
-- Database: `mimaq`
-- 

-- --------------------------------------------------------

-- 
-- Table structure for table `device`
-- 

CREATE TABLE `device` (
  `id` int(10) unsigned NOT NULL auto_increment,
  `bt_address` varchar(12) collate utf8_unicode_ci NOT NULL COMMENT 'Senspod Bluetooth Address',
  `name` varchar(64) collate utf8_unicode_ci NOT NULL,
  `active` int(1) default '0',
  `last_frame_id`  INT( 11 ) NOT NULL DEFAULT '0',
  PRIMARY KEY  (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci AUTO_INCREMENT=3 ;

-- --------------------------------------------------------

-- 
-- Table structure for table `sample`
-- NOTE: should be MyISAM for geo support 

CREATE TABLE `sample` (
  `id` int(10) unsigned NOT NULL auto_increment,
  `device_id` int(10) unsigned NOT NULL,
  `datetime` datetime NOT NULL,
  `location` point NOT NULL COMMENT 'geolocation Point',
  `NOx` float NOT NULL,
  `COx` float NOT NULL,
  `noise` float NOT NULL,
  `humidity` float NOT NULL,
  `temperature` float NOT NULL,
  `battery` float default NULL,
  PRIMARY KEY  (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci AUTO_INCREMENT=35171 ;


-- deployment, enter hardware address and device namne here
INSERT INTO `device` VALUES (2, '00078095573C', 'SENSPOD_0045', 1, 0);
INSERT INTO `device` VALUES (3, '000780955823', 'SENSPOD_0056', 1, 0);
INSERT INTO `device` VALUES (4, '000780955816', 'SENSPOD_0053', 1, 0);
INSERT INTO `device` VALUES (5, '00078095581F', 'SENSPOD_0052', 1, 0);
INSERT INTO `device` VALUES (6, '000780955742', 'SENSPOD_0050', 1, 0);


-- ----------------------------------------------------------------------

--
-- Table structure for table `grid50`
-- Auto generated grid structure overlays the map
--

CREATE TABLE `grid50` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `location` point NOT NULL COMMENT 'geolocation Point',
  PRIMARY KEY (`id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci AUTO_INCREMENT=1035 ;


--
-- Table structure for table `grid50_sample`
-- mapping of samples to the auto-generated grid 
--

CREATE TABLE `grid50_sample` (
  `grid_id` int(10) unsigned NOT NULL,
  `sample_id` int(10) unsigned NOT NULL,
  KEY `grid_id` (`grid_id`),
  KEY `sample_id` (`sample_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;


-- trips
CREATE TABLE `trip` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `device_id` int(10) unsigned NOT NULL,
  `bbox` polygon NOT NULL COMMENT 'Bounding box',
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `distance` float NOT NULL,
  -- below are average values for the trip
  `NOx` float NOT NULL,
  `COx` float NOT NULL,
  `noise` float NOT NULL,
  `humidity` float NOT NULL,
  `temperature` float NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci AUTO_INCREMENT=1035 ;


--
-- Table structure for table `trip_sample`
-- mapping of samples to the auto-generated trips 
--

CREATE TABLE `trip_sample` (
  `trip_id` int(10) unsigned NOT NULL,
  `sample_id` int(10) unsigned NOT NULL,
  KEY `trip_id` (`trip_id`),
  KEY `sample_id` (`sample_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

