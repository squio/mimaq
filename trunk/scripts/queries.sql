--
-- * Copyright 2010 MIMAQ
-- * Released under a permissive license (see LICENSE)
--

-- Measurement days:
	SELECT date( `datetime` ) AS `d`, COUNT(*) as `count`
		FROM `sample`
		WHERE 1
		GROUP BY `d`
		ORDER BY `d` DESC;

-- export all measurements of a day
	SELECT `datetime`, `NOx`, `COx`, `humidity`, `temperature` 
		FROM `sample` 
		WHERE date(`datetime`) = '2010-05-28' 
		ORDER BY `datetime`;

-- find number of measurements per 50x50m grid block
	SELECT Y(g.location) as `lat`, X(g.location) as `lon`, g.id as `id`, count(*) as `count` 
		FROM `grid50_sample` gs, grid50 g 
		WHERE g.id = gs.grid_id 
		GROUP BY g.id 
		ORDER BY `count` DESC;