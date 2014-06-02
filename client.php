#!/usr/bin/php
<?php

require_once('entry.php');

// Read configuration
$config = @json_decode( @file_get_contents(dirname(__FILE__) . '/config.json') );
if ($config === null or !isset($config->archive_dir) or !isset($config->log) or !isset($config->streaming_command) or !isset($config->test_command) )
	die( build_http_response('Sorry, this server is not properly configured.', '500 Internal server error') );

$archive_dir = realpath(dirname(__FILE__) . '/' . $config->archive_dir);
$log = fopen(dirname(__FILE__) . '/' . $config->log, 'a');
//$log = fopen('php://stderr', 'w');

// Read initial HTTP request line
$initial_request_line = fgets(STDIN);
if (trim($initial_request_line) == '') {
	// Ignore connections without an initial request line. Chrome opens these
	// after proper HTTP requests. Maybe for pipelining, how knows.
	exit();
}
list($action, $url, ) = preg_split('/\s+/', $initial_request_line, 3);

// Read HTTP headers until terminating empty line
$headers = array();
while (true) {
	$header_line = fgets(STDIN);
	if ($header_line === false or trim($header_line) === '' )
		break;
	list($name, $value) = explode(':', $header_line, 2);
	$headers[strtolower(trim($name))] = strtolower(trim($value));
}

fprintf($log, "%s HTTP Request: %s %s\n", strftime('%F %T'), $action, $url);

// Intercept chunked transfer encoding POSTs before they screw us up
if ( isset($headers['transfer-encoding']) and $headers['transfer-encoding'] == 'chunked' )
	die( build_http_response("Sorry, the server doesn't support chunked transfer encoding yet. If you use ffmpeg please add the \"-chunked_post 0\" option before the target URL.", '500 Internal server error') );

$path = parse_url($url, PHP_URL_PATH);
parse_str(parse_url($url, PHP_URL_QUERY), $params);

// Dispatch request
if ($action == 'GET' and $path == '/events/new' and isset($params['name'])) {
	// Create a new event with the specified name and return its ID
	$new_event_id = create_new_event($archive_dir, $params['name']);
	if ($new_event_id !== false)
		echo(build_http_response($new_event_id, '201 Created'));
	else
		echo(build_http_response('Sorry, could not create new event. Something went wrong here. Please look at the server log.', '500 Internal Server Error'));
} else if ($action == 'GET' and $url == '/announcements.json') {
	// The user wants to know all currently announced events.
	$json = list_streamable_events($archive_dir);
	echo(build_http_response($json, '200 OK', 'application/json; charset=utf-8'));
} else if ($action == 'POST' and preg_match('#^/(?<event>[^/]+)/(?<talk>[^/?]+)$#', $path, $matches)) {
	// The user sends us the stream for an event
	$command = $config->streaming_command;
	if ( isset($params['testOnly']) and $params['testOnly'] === 'true' )
		$command = $config->test_command;
	$result = stream_event_video_data($archive_dir, $matches['event'], $matches['talk'], $command);
	if ($result)
		echo(build_http_response('', '204 No Content'));
	else
		echo(build_http_response('Sorry, something went wrong. Could not find the event you want to stream to or the streaming is broken. Please create it first or look at the server log.', '404 File Not Found'));
} else {
	// No idea what the client wants...
	echo(build_http_response('Sorry, no idea what to do with the URL you requested.', '404 File Not Found'));
}


//
// Utility functions
//

function build_http_response($content, $status = '200 OK', $content_type = 'text/plain; charset=utf-8') {
	$response = "HTTP/1.0 $status\r\n";
	$response .= "Content-Type: $content_type\r\n";
	$response .= "Connection: close\r\n";
	$response .= "\r\n";
	$response .= $content;
	
	return $response;
}


//
// Functions to handle more complex requests
//

function create_new_event($archive_dir, $name) {
	$id = strftime('%F') . '-' . Entry::parameterize(basename($name));
	if ( ! mkdir($archive_dir . '/' . $id) )
		return false;
	return $id;
}

function list_streamable_events($archive_dir) {
	global $log;
	
	$events_data = array();
	foreach( glob("$archive_dir/*", GLOB_ONLYDIR) as $event_path ) {
		$event_id = basename($event_path);
		
		// Reconstruct essential data from the event id (this is what we can really count on)
		if ( ! preg_match('/^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})-(?<name>.*)$/', $event_id, $matches) )
			continue;
		
		$event_start = mktime(0, 0, 0, $matches['month'], $matches['day'], $matches['year']);
		$event_end = mktime(0, 0, 0, $matches['month'], $matches['day'] + 1, $matches['year']);
		$event_data = array(
			'title' => ucwords(trim(preg_replace('/[\s-]+/', ' ', $matches['name']))),
			'start' => strftime('%F', $event_start),
			'end' => strftime('%F', $event_end)
		);
		
		// Load additional data from the announcement if there is one
		$announcement = Entry::load($event_path . '/announcement.txt');
		if ($announcement) {
			foreach($announcement->headers as $name => $value) {
				if (strtolower($name) == 'talk')
					continue;
				
				$event_data[strtolower($name)] = $value;
			}
			
			if ($announcement->content)
				$event_data['description'] = $announcement->content;
			
			if ($announcement->start)
				$event_start = $announcement->start_as_time;
			if ($announcement->end)
				$event_end = $announcement->end_as_time;
			
			if ($announcement->talk) {
				$event_data['talks'] = array();
				foreach($announcement->talk_as_array as $talk) {
					list($title, $speakers, ) = preg_split('/\t+/', $talk, 3);
					$event_data['talks'][$title] = array(
						'speakers' => $speakers
					);
				}
			}
		}
		
		// Now check if we can actually stream to this event or if it's already passed
		$today = mktime(0, 0, 0);
		if ($event_start < $today and $event_end < $today)
			continue;
		
		$events_data[$event_id] = $event_data ;
	}
	
	// JSON_FORCE_OBJECT makes sure an empty PHP array is encoded as an empty JSON object
	return json_encode($events_data, JSON_FORCE_OBJECT);
}

function stream_event_video_data($archive_dir, $event_id, $talk, $stream_command) {
	global $log;
	
	$event_id = basename($event_id);
	$talk = basename($talk);
	
	fprintf($log, "%s Streaming $event_id/$talk with command %s\n", strftime('%F %T'), escapeshellarg($stream_command));
	
	$event_dir = realpath($archive_dir . '/' . $event_id);
	if (!file_exists($event_dir))
		return false;
	
	$talk_name = pathinfo($talk, PATHINFO_FILENAME);
	$talk_extension = pathinfo($talk, PATHINFO_EXTENSION);
	$existing_talk_files = glob("$event_dir/$talk_name*.$talk_extension");
	$talk_filename = $talk_name . '.' . count($existing_talk_files) . '.' . $talk_extension;
	
	$streaming_log_fd = fopen("$event_dir/streaming.log", 'a');
	
	$stream_command = '/bin/sh -c ' . escapeshellarg($stream_command);
	$streaming_process = proc_open('/bin/sh -c ' . escapeshellarg($stream_command), array(
		0 => array('pipe', 'r'),
		1 => $streaming_log_fd,
		2 => $streaming_log_fd
	), $pipes, $event_dir, compact('event_id', 'event_dir', 'talk_name', 'talk_filename'));
	
	$bytes_copied = stream_copy_to_stream(STDIN, $pipes[0]);
	if ($bytes_copied === false) {
		fprintf($log, "%s Couldn't write data into streaming pipeline. Please check the streaming log.\n", strftime('%F %T'));
		proc_close($streaming_process);
		return false;
	}
	
	fprintf($log, "%s Streaming done, handled %.2f MiByte.\n", strftime('%F %T'), $bytes_copied / (1024.0 * 1024.0));
	proc_close($streaming_process);
	return true;
}

?>
