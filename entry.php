<?php

/** 
 * Quick summary of usage:
 * 
 * Entry::load('test.post') → $entry
 * Entry::analyze('test.post') → array($headers, $content)
 * Entry::save('test.post', array('Header' => 'value'), 'Some multiline content')
 * 
 * $entry->content
 * $entry->headers
 * $entry->header_field			// Returns the value of the header "header_field" as string, if header occurs multiple times an array of string is returned.
 * $entry->header_field_as_list		// Returns the value "foo, bar" as array("foo", "bar").
 * $entry->header_field_as_array	// Even if the header occurs only once the value is returned as an array with one string entry. Useful to process headers which are supposed to be used multiple times.
 * $entry->header_field_as_time	// Returns values like "2014-05-01 12:56" as unix timestamp.
 */
class Entry
{
	/**
	 * Saves an entry at the specified path. $headers is expected to be an array of
	 * header field name and header field value pairs. $content is the actual content
	 * of the entry.
	 * 
	 * Example:
	 * 
	 * 	Entry::save('example.post', array('Name' => 'Example post'), 'Example content');
	 */
	static function save($path, $headers, $content)
	{
		$head = join("\n", array_map(function($name, $value){
			if (is_array($value))
				return join("\n", array_map(function($line){
					strtr($name, "\n:", '  '). ': ' . str_replace("\n", ' ', $line);
				}, $value));
			else
				return strtr($name, "\n:", '  '). ': ' . str_replace("\n", ' ', $value);
		}, array_keys($headers), $headers) );
		
		return @file_put_contents($path, $head . "\n\n" . $content);
	}
	
	/**
	 * Disassembles the specified entry file and returns its headers and content.
	 * 
	 * Everything is returned without manipulation and therefore perfect to
	 * reconstruct the entry again after a slight manipulation (e.g. adding a  new
	 * header). This does not destroy any formatting (e.g. with whitespaces) the
	 * user applied.
	 * 
	 * Note that this function does not return an Entry object. It always returns an
	 * array with the first element being a list of header fields and the second
	 * element the entry content. If the specified file does not exists both elements
	 * are set to false.
	 */
	static function analyze($path)
	{
		$data = @file_get_contents($path);
		if (!$data)
			return array(false, false);
		
		@list($head, $content) = explode("\n\n", $data, 2);
		$headers = self::parse_head($head, false);
		
		return array($headers, $content);
	}
	
	/**
	 * Loads the specified file and returns an entry object.
	 */
	static function load($path)
	{
		list($headers, $content) = self::analyze($path);
		if ($headers !== false and $content !== false) {
			$lowercase_names = array_map('strtolower', array_keys($headers));
			$values = array_values($headers);
			$headers = array_combine($lowercase_names, $values);
			
			return new self($headers, $content);
		}
		
		return false;
	}
	
	function __construct($header, $content)
	{
		$this->headers = $header;
		$this->content = $content;
	}
	
	function __get($property_name)
	{
		if (preg_match('/^(.+)_as_list$/i', $property_name, $matches))
			return self::parse_list_header(@$this->headers[$matches[1]]);
		if (preg_match('/^(.+)_as_time$/i', $property_name, $matches))
			return self::parse_time_header(@$this->headers[$matches[1]]);
		if (preg_match('/^(.+)_as_array$/i', $property_name, $matches))
			if( is_array(@$this->headers[$matches[1]]) )
				return @$this->headers[$matches[1]];
			else
				return array(@$this->headers[$matches[1]]);
		
		return @$this->headers[$property_name];
	}
	
	/**
	 * Parses the specified head text of an entity and returns an array
	 * with the headers.
	 * 
	 * If the clean_up parameter is set to false the header names and
	 * values are not cleaned up (lower case and trimmed). Use this if
	 * you want to reconstruct the headers in their original state.
	 */
	static function parse_head($head, $clean_up = true)
	{
		$headers = array();
		foreach( explode("\n", $head) as $header_line )
		{
			list($name, $value) = explode(': ', $header_line, 2);
			if ($clean_up)
			{
				$name = strtolower(trim($name));
				$value = trim($value);
			}
			
			if ( !array_key_exists($name, $headers) )
				$headers[$name] = $value;
			else
				if (is_array($headers[$name]))
					array_push($headers[$name], $value);
				else
					$headers[$name] = array($headers[$name], $value);
		}
		
		return $headers;
	}
	
	/**
	 * Parses header as a list and returns the elements as an array.
	 * If the input parameter is invalid an empty array is returned.
	 */
	static function parse_list_header($header_content)
	{
		if ($header_content)
		{
			$elements = explode(',', $header_content);
			return array_map('trim', $elements);
		}
		
		return array();
	}
	
	/**
	 * Parses the specified header content as a date and returns its
	 * the timestamp. If the header isn't a valid date false is returned.
	 */
	static function parse_time_header($header_content)
	{
		$matched = preg_match('/(\d{4})(-(\d{2})(-(\d{2})(\s+(\d{2}):(\d{2})(:(\d{2}))?)?)?)?/i', $header_content, $matches);
		if (!$matched)
			return false;
		
		@list($year, $month, $day, $hour, $minute, $second) = array($matches[1], $matches[3], $matches[5], $matches[7], $matches[8], $matches[10]);
		
		// Set default values to 1 for month and day (we usually
		// mean that when we omit that part of the date).
		if ( empty($month) )
			$month = 1;
		if ( empty($day) )
			$day = 1;
		
		return mktime($hour, $minute, $second, $month, $day, $year);
	}
	
	/**
	 * Converts the specified name into a better readable form that
	 * can be used in "pretty" URLs.
	 */
	static function parameterize($name)
	{
		return trim( preg_replace('/[^\w\däüöß]+/', '-', strtolower($name)), '-' );
	}
}

?>