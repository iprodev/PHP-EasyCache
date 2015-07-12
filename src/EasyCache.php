<?php

namespace iProDev\Util;

/*
 * EasyCache v1.1.0
 *
 * By Hemn Chawroka
 * http://iprodev.com
 *
 * Free to use and abuse under the MIT license.
 * http://www.opensource.org/licenses/mit-license.php
 */
class EasyCache {

	// Path to cache folder (with trailing /)
	public $cache_path = 'cache/';
	// Length of time to cache a file (in seconds)
	public $cache_time = 3600;
	// Cache file extension
	public $cache_extension = '.cache';
	// Compress level, 1 - 9; 0 to disable
	public $compress_level = 0;

	/**
	 * This is just a functionality wrapper function
	 *
	 * @param {String} $cache_key
	 * @param {String} $url
	 *
	 * @return {Void}
	 */
	public function get_data($cache_key, $url)
	{
		if($data = $this->get_cache($cache_key)){
			return $data;
		} else {
			$data = $this->get_contents($url);
			$this->set_cache($cache_key, $data);
			return $data;
		}
	}

	/**
	 * Set the cache data for the key
	 *
	 * @param {String} $cache_key
	 * @param {Mixed}  $data
	 *
	 * @return {Void}
	 */
	public function set($cache_key, $data)
	{
		// Serialize
		$data = $this->maybe_serialize($data);

		// Compress
		if ($this->compress_level > 0) {
			$data = $this->compress($data);
		}

		// Put the cache data to the file
		file_put_contents($this->cache_path . $this->safe_filename($cache_key) . $this->cache_extension, $data);
	}

	/**
	 * Get the cache data for the key
	 *
	 * @param {String} $cache_key
	 *
	 * @return {Mixed}
	 */
	public function get($cache_key)
	{
		if($this->is_cached($cache_key)){
			// Get the cache data from the file
			$data = file_get_contents($this->cache_path . $this->safe_filename($cache_key) . $this->cache_extension);

			// Uncompress
			if ($this->compress_level > 0) {
				$data = $this->uncompress($data);
			}

			// Unserialize
			$data = $this->maybe_unserialize($data);

			return $data;
		}

		return false;
	}

	/**
	 * Make sure that cache is available for the key
	 *
	 * @param {String} $cache_key
	 *
	 * @return {Boolean}
	 */
	public function is_cached($cache_key)
	{
		$filename = $this->cache_path . $this->safe_filename($cache_key) . $this->cache_extension;

		if(file_exists($filename) && (filemtime($filename) + $this->cache_time >= time())) return true;

		return false;
	}

	/**
	 * Helper function for retrieving data from url
	 *
	 * @param {String} $url
	 *
	 * @return {String}
	 */
	public function get_contents($url)
	{
		$content = null;

		if(function_exists("curl_init")){
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
			$content = curl_exec($ch);
			curl_close($ch);
		} else {
			$content = file_get_contents($url);
		}

		// Check JSON
		if ($content && $this->is_json($content))
			$content = json_decode($content, true);

		return $content;
	}

	/**
	 * Helper function to validate filenames.
	 *
	 * @param {String} $filename
	 *
	 * @return {String}
	 */
	private function safe_filename($filename)
	{
		return preg_replace('/[^0-9a-z\.\_\-]/i','', strtolower($filename));
	}

	/**
	 * Compress function.
	 *
	 * @param {String} $contents
	 *
	 * @return {String}
	 */
	private function compress($contents) {
		if (function_exists('gzdeflate') && function_exists('gzinflate')) {
			$contents = gzdeflate($contents, $this->within($this->compress_level, 1, 9));
		}
		return $contents;
	}

	/**
	 * Uncompress function.
	 *
	 * @param {String} $contents
	 *
	 * @return {String}
	 */
	private function uncompress($contents) {
		if (function_exists('gzinflate')) {
			$contents = gzinflate($contents);
		}
		return $contents;
	}

	/**
	 * Make sure that number is within the limits.
	 *
	 * @param {Number} $number
	 * @param {Number} $min
	 * @param {Number} $max
	 *
	 * @return {Number}
	 */
	private function within($number, $min, $max) {
		return $number < $min ? $min : $number > $max ? $max : $number;
	}

	/**
	 * Unserialize value only if it was serialized.
	 *
	 * @param  {String} $original  Maybe unserialized original, if is needed.
	 * @return {Mixed}             Unserialized data can be any type.
	 */
	private function maybe_unserialize( $original ) {
		if ( $this->is_serialized( $original ) ) // don't attempt to unserialize data that wasn't serialized going in
			return @unserialize( $original );
		return $original;
	}

	/**
	 * Serialize data, if needed.
	 *
	 * @param  {Mixed} $data  Data that might be serialized.
	 * @return {Mixed}        A scalar data
	 */
	private function maybe_serialize( $data ) {
		if ( is_array( $data ) || is_object( $data ) )
			return serialize( $data );

		// Double serialization is required for backward compatibility.
		if ( $this->is_serialized( $data, false ) )
			return serialize( $data );

		return $data;
	}

	/**
	 * Check value to find if it was serialized.
	 *
	 * If $data is not an string, then returned value will always be false.
	 * Serialized data is always a string.
	 *
	 * @param  {Mixed}   $data    Value to check to see if was serialized.
	 * @param  {Boolean} $strict  Optional. Whether to be strict about the end of the string. Defaults true.
	 * @return {Boolean}          False if not serialized and true if it was.
	 */
	private function is_serialized( $data, $strict = true ) {
		// if it isn't a string, it isn't serialized
		if ( ! is_string( $data ) )
			return false;
		$data = trim( $data );
	 	if ( 'N;' == $data )
			return true;
		$length = strlen( $data );
		if ( $length < 4 )
			return false;
		if ( ':' !== $data[1] )
			return false;
		if ( $strict ) {
			$lastc = $data[ $length - 1 ];
			if ( ';' !== $lastc && '}' !== $lastc )
				return false;
		} else {
			$semicolon = strpos( $data, ';' );
			$brace     = strpos( $data, '}' );
			// Either ; or } must exist.
			if ( false === $semicolon && false === $brace )
				return false;
			// But neither must be in the first X characters.
			if ( false !== $semicolon && $semicolon < 3 )
				return false;
			if ( false !== $brace && $brace < 4 )
				return false;
		}
		$token = $data[0];
		switch ( $token ) {
			case 's' :
				if ( $strict ) {
					if ( '"' !== $data[ $length - 2 ] )
						return false;
				} elseif ( false === strpos( $data, '"' ) ) {
					return false;
				}
				// or else fall through
			case 'a' :
			case 'O' :
				return (bool) preg_match( "/^{$token}:[0-9]+:/s", $data );
			case 'b' :
			case 'i' :
			case 'd' :
				$end = $strict ? '$' : '';
				return (bool) preg_match( "/^{$token}:[0-9.E-]+;$end/", $data );
		}
		return false;
	}

	/**
	 *	Determines whether or not a string contains valid json encoding.
	 *
	 *	@param	{String}   $string  string to test
	 *	@return	{Boolean}           true if valid, false if not
	 */
	private function is_json($string) {
		return $string && @is_array(json_decode($string));
	}
}
