/*
 * MIMAQ
 * Copyright 2010 MIMAQ
 * Released under a permissive license (see LICENSE)
 */

/**
 * Date.parse with progressive enhancement for ISO-8601
 * © 2010 Colin Snover <http://zetafleet.com>
 * Released under MIT license.
 */
(function () {
    'use strict';
    var origParse = Date.parse;
    Date.parse = function (date) {
        var timestamp = origParse(date), minutesOffset = 0, struct;
        if (isNaN(timestamp) && (struct = /(\d{4})-?(\d{2})-?(\d{2})(?:[T ](\d{2}):?(\d{2}):?(\d{2})(?:\.(\d{3,}))?(?:(Z)|([+\-])(\d{2})(?::?(\d{2}))?))/.exec(date))) {
            if (struct[8] !== 'Z') {
                minutesOffset = +struct[10] * 60 + (+struct[11]);
                
                if (struct[9] === '+') {
                    minutesOffset = 0 - minutesOffset;
                }
            }
            var s7 = (struct[7]) ? +struct[7].substr(0, 3) : 0;
            timestamp = Date.UTC(+struct[1], +struct[2] - 1, +struct[3], +struct[4], +struct[5] + minutesOffset, +struct[6], s7);
        }
        
        return timestamp;
    };
}());

var Mapper = {
	polys: [],
	markers: [],
	oldMarkers:[],
	map: null,
	lastZoom: 0,
	timer: null,
	intvTimer: null,
	marker: null,
	lastInfoWindow: null,
	baseUrl: (window.location.host.indexOf('localhost') === 0) ? 'map.php' : 'http://api.worldservr.com/mimaq/map.php',
	dragListener: null,
	mapType: 'area',

	lvlInfo: [
		{'limit': 0.55, 'type': 3, 'title': 'NOx nivo zeer hoog',     'text': 'Zeer veel vervuiling, andere route aanbevolen.', 'color': '#cc0000'},
		{'limit': 1.10, 'type': 3, 'title': 'NOx nivo is hoog',       'text': 'Veel vervuiling, probeer een andere route.', 'color': '#ff3300'},
		{'limit': 1.65, 'type': 2, 'title': 'NOx meer dan gemiddeld', 'text': 'Niet goed maar ook niet gevaarlijk.', 'color': '#ffcc00'},
		{'limit': 2.20, 'type': 2, 'title': 'NOx minder dan gemiddeld', 'text': 'Redelijke luchtkwaliteit.', 'color': '#ffff00'},
		{'limit': 2.75, 'type': 1, 'title': 'NOx nivo is laag',       'text': 'Mooi, hier is de luchtkwaliteit goed.', 'color': '#ccff00'},
		{'limit': 3.30, 'type': 1, 'title': 'NOx nivo is zeer laag',  'text': 'Beter kan niet, hier is schone lucht.', 'color': '#00ff00'}
	],

	init: function(mapElt) {
		var myOptions = {
			zoom: 17,
			center: new google.maps.LatLng(52.15845, 4.48967),
			maptypeControlOptions: {
				mapTypeIds: [ google.maps.MapTypeId.ROADMAP, 'mimaq' ]
			},			
			scaleControl: true,
		    scaleControlOptions: {
		        position: google.maps.ControlPosition.BOTTOM_RIGHT
		    }    
		}
		var myStyles = [ {
				featureType: "all", 
				elementType: "all", 
				stylers: [ { visibility: "on" }, { saturation: -100 }, { lightness: -25 } ]
		} ];
		this.map = new google.maps.Map(mapElt, myOptions);
		
		var styledMap = new google.maps.StyledMapType(myStyles, { name: 'faded' });
		this.map.mapTypes.set('mimaq', styledMap);
		this.map.setMapTypeId('mimaq');
		
//		Mapper.dragListener = google.maps.event.addListener(this.map, 'bounds_changed', Mapper.update);
//		google.maps.event.addListener(this.map, 'dragstart', Mapper.onDragstart);
//		google.maps.event.addListener(this.map, 'dragend', Mapper.onDragend);
		
		this.getDeviceList();
		$('#device').bind('change', function(e) {
			Mapper.getDateList();
			e.stopImmediatePropagation();
		});
		$('#date').bind('change', function(e) {
			Mapper.update('track');
			e.stopImmediatePropagation();
		});
	},
	
	getDeviceList: function() {
		$('#device').attr("disabled","disabled");
		jQuery.getJSON(Mapper.baseUrl + '?type=devicelist&callback=?', function(data) {
			for (var i=0; i<data.length; i++) {
				$('#device').append('<option name="' + data[i].id + '">' + data[i].name + '</option>');
			}
			$('#device').removeAttr('disabled');
		});
	},
	
	getDateList: function() {
		var deviceId = $('#device').find('option:selected').attr('name');
		$('#date').empty();
		$('#date').attr("disabled","disabled");
		$('#date').append('<option name="none">Kies een datum</option>');
		jQuery.getJSON(Mapper.baseUrl + '?type=datelist&id=' + deviceId + "&callback=?",
			function(data) {
				for (var i=0; i<data.length; i++) {
					var d = data[i].date.split(/-/);
					$('#date').append('<option name="' + data[i].date + '">' + new Date(d[0], d[1]-1, d[2], 12, 0, 0, 0).toDateString() + '</option>');
				}
				$('#date').removeAttr('disabled');	
			});
	},
	
	getLevel: function(NOx) {
		// for (var i = this.lvlInfo.length - 1; i >= 0; i--) {
		for (var i = 0; i<this.lvlInfo.length; i++) {
			if (NOx < this.lvlInfo[i].limit) {
				return this.lvlInfo[i];
			}
		}
		return this.lvlInfo[0];
	},

	update: function(mapType) {
		if (Mapper.dragListener) {
			google.maps.event.removeListener(Mapper.dragListener);
			Mapper.dragListener = null;
		}
		Mapper.clearPolys();
		Mapper.clearMarkers();
		if (!mapType) {
			mapType = 'area';
		}
		Mapper.mapType = mapType;
		var ne = Mapper.map.getBounds().getNorthEast();
		var sw = Mapper.map.getBounds().getSouthWest();
		var deviceId = $('#device').find('option:selected').attr('name');
		var date = $('#date').find('option:selected').attr('name');
		var url = Mapper.baseUrl + "?type="+mapType+"&id=" + deviceId + "&date=" + date + "&neLat=" + ne.lat() + "&neLon=" + ne.lng() + "&swLat=" + sw.lat() + "&swLon=" + sw.lng();

		var opts = {
		    strokeColor: "#cc0000",
		    strokeOpacity: 0.5,
		    strokeWeight: 15,
			time: 0,
			path: []
		};
		
		var lastTime = null;
		var avgNOx = [];
		var lastLevel = null;
		
		jQuery.getJSON(url + "&callback=?", function(data) {
			for (var i=0; i<data.pois.length; i++) {
				var d = data.pois[i];
				// restart polyline after a long distance moved
				// POIs are ordered reverse chronoligically
				var t = Date.parse(d.timestamp);
				var level = Mapper.getLevel(d.NOx).limit;
				newLoc = new google.maps.LatLng(d.lat, d.lon);
				// omit point if timestamp offset is more than 30 seconds
				if (lastTime && (lastTime - t) > 30000) {
					lastTime = t;
					opts.path = [];
				} else {
					opts.path.push(newLoc);
					avgNOx.push(d.NOx);
				}
				if (i > 0 && level !== lastLevel) {
					var NOx = 0;
					for (var n=0; n<avgNOx.length; n++) {
						NOx += avgNOx[n];
					}
					NOx = Math.round(100*NOx/avgNOx.length)/100;
					opts.time = (lastTime + t) / 2;
					opts.NOx = NOx;
					opts.strokeColor = Mapper.getLevel(NOx).color;
					Mapper.setPoly(opts);
					opts.path = [
						newLoc
					];
					avgNOx = [d.NOx];
					lastTime = t;
				}
				lastTime = t;
				lastLevel = level;
			}
			//Mapper.setPoly(opts);
			// Load full poi list in map, only if mapType = track
			if (Mapper.mapType === 'track') {
				var bounds = new google.maps.LatLngBounds( 
						new google.maps.LatLng(data.bbox[0], data.bbox[1]),
						new google.maps.LatLng(data.bbox[2], data.bbox[3])
					);
				Mapper.map.fitBounds(bounds);
			}
			
			$('#chart').attr("src", data.chartUrl);
//			Mapper.dragListener = google.maps.event.addListener(Mapper.map, 'bounds_changed', Mapper.update);
		});

	},
	
	setPoly: function(opts) {
		var p = new google.maps.Polyline(opts);
		google.maps.event.addListener(p, 'click', function(e) {
			if (Mapper.lastInfoWindow) {
			    Mapper.lastInfoWindow.close(); 
			}
			var lvlInfo = Mapper.getLevel(this.NOx);
			var icon = "http://api.worldservr.com/mimaq/gfx/" + lvlInfo.type + ".png";
			var d = new Date(this.time);
			var min = d.getMinutes();
			var timeStr = '' + d.getHours() + ':' + ((min > 9) ? min : '0' + min);
			Mapper.lastInfoWindow = new google.maps.InfoWindow({
			    maxWidth: 350,
				// context = polyline 'p'
			    content: "<div style='font-size:smaller;line-height:1.1em;font-family:sans-serif;width:240px;height:120px'>" + 
					"<img src='" + icon + "' style='float:right;width:80px'><strong>" + 
			    	lvlInfo.title + "</strong><br>" +
			    	lvlInfo.text + "<br>" + 
					'NOx: ' + (100 - Math.round(100 * this.NOx / 3.3)) + "% van sensor max.<br><em>meting ca. " +
			    	timeStr + " uur</em></div>"
			}); 
			Mapper.lastInfoWindow.setPosition(e.latLng); 
			Mapper.lastInfoWindow.open(Mapper.map); 
		});
		p.setMap(Mapper.map);
		Mapper.polys.push(p);
	},
	
	setMarker: function(opts) {
		var marker = new google.maps.Marker(opts); 
		google.maps.event.addListener(marker, 'click', function(e) {
			if (Mapper.lastInfoWindow) {
			    Mapper.lastInfoWindow.close(); 
			} 
			Mapper.lastInfoWindow = new google.maps.InfoWindow({
			    maxWidth: 250,
				// context = marker
			    content: 'this.content.text' //new Date(1000*this.timestamp).toString()
			}); 
			Mapper.lastInfoWindow.setPosition(e.latLng); 
			Mapper.lastInfoWindow.open(Mapper.map); 
			//alert(this.content.text);
		});
		marker.setMap(Mapper.map);
		Mapper.markers.push(marker);
	},
	
	clearMarkers: function() {
		for (var i = 0; i < this.markers.length; i++) {
			this.markers[i].setMap(null);
		}
		this.markers = [];
	},
	
	clearPolys: function() {
		for (var i = 0; i < this.polys.length; i++) {
			this.polys[i].setMap(null);
		}
		this.polys = [];
	},
	
	// Drag Event Utilities
	// see http://blog.finalevil.com/2009/07/google-maps-api-v3.html 
	onDragstart: function() {
		google.maps.event.removeListener(Mapper.dragListener);
 	},
 
 	onDragend: function() {
		Mapper.update();
 		//Mapper.dragListener = google.maps.event.addListener(Mapper.map, 'bounds_changed', Mapper.update);
 	},
		
} 
