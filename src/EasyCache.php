<?php

namespace iProDev\Util;

/*
 * EasyCache v1.2.1
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
	// Cache config file name
	private $config_file = 'EasyCache.config';

	/**
	 * This is just a functionality wrapper function
	 *
	 * @param {String} $cache_key
	 * @param {String} $url
	 *
	 * @return {Mixed}
	 */
	public function get_data($cache_key, $url) {
		if($data = $this->get_cache($cache_key)){
			return $data;
		} else {
			$data = $this->get_contents($url);
			$this->set_cache($cache_key, $data);
			return $data;
		}
	}

	/**
	 * Set the cache item data
	 *
	 * @param {String} $cache_key
	 * @param {Mixed}  $data
	 *
	 * @return {Void}
	 */
	public function set($cache_key, $data) {
		// Serialize
		$data = $this->maybe_serialize($data);

		// Compress
		if ($this->compress_level > 0) {
			$data = $this->compress($data);
		}

		// Save this cache config into configuration file
		$this->set_config($cache_key);

		// Put the cache data to the file
		file_put_contents($this->cache_path . $this->safe_filename($cache_key) . $this->cache_extension, $data);
	}

	/**
	 * Get the cache item data
	 *
	 * @param {String} $cache_key
	 *
	 * @return {Mixed}
	 */
	public function get($cache_key) {
		$config = $this->get_config($cache_key);

		if($this->is_cached($cache_key, $config)) {
			// Get the cache data from the file
			$data = file_get_contents($this->cache_path . $this->safe_filename($cache_key) . $this->cache_extension);
			$uncompress = $this->compress_level > 0;

			if ($config && isset($config['compressed']) && $config['compressed'])
				$uncompress = true;

			// Uncompress
			if ($uncompress) {
				$data = $this->uncompress($data);
			}

			// Unserialize
			$data = $this->maybe_unserialize($data);

			return $data;
		}

		return false;
	}

	/**
	 * Delete the cache item
	 *
	 * @param {String} $cache_key
	 *
	 * @return {Boolean}
	 */
	public function delete($cache_key) {
		$config_file = $this->cache_path . $this->config_file;

		if (!file_exists($config_file))
			return false;

		$items = unserialize(file_get_contents($config_file));
		$file = $this->cache_path . $this->safe_filename($cache_key) . $this->cache_extension;

		// Delete the cache and its config
		if(file_exists($file)) {
			@unlink($file);
			unset($items[$key]);
		}

		// Put the new cache config to the file
		file_put_contents($config_file, serialize($items));

		return true;
	}

	/**
	 * Removes all EasyCache expired items
	 *
	 * @param {String} $cache_key
	 *
	 * @return {Boolean}
	 */
	public function flush_expired() {
		$config_file = $this->cache_path . $this->config_file;

		if (!file_exists($config_file))
			return false;

		$items = unserialize(file_get_contents($config_file));
		$time = time();

		foreach ($items as $key => $value) {
			$file = $this->cache_path . $this->safe_filename($key) . $this->cache_extension;

			// Delete the cache and its config
			if(file_exists($file) && (filemtime($file) + $value['cache_time'] < $time)) {
				@unlink($file);
				unset($items[$key]);
			}
		}

		// Put the new cache config to the file
		file_put_contents($config_file, serialize($items));

		return true;
	}

	/**
	 * Removes all EasyCache items
	 *
	 * @param {String} $cache_key
	 *
	 * @return {Boolean}
	 */
	public function flush() {
		$config_file = $this->cache_path . $this->config_file;

		foreach (glob($this->cache_path . "*" . $this->cache_extension) as $file) {
			// Remove the cache item
			if (file_exists($file))
				@unlink($file);
		}

		// Remove the EasyCache config file
		if (file_exists($config_file))
			@unlink($config_file);

		return true;
	}

	/**
	 * Make sure that cache is available for the key
	 *
	 * @param {String} $cache_key
	 * @param {Array}  $config
	 *
	 * @return {Boolean}
	 */
	public function is_cached($cache_key, $config = null) {
		$file       = $this->cache_path . $this->safe_filename($cache_key) . $this->cache_extension;
		$cache_time = $this->cache_time;

		if ($config && isset($config['cache_time']))
			$cache_time = intval($config['cache_time']);

		if(file_exists($file) && (filemtime($file) + $cache_time >= time())) return true;

		return false;
	}

	/**
	 * Set the config for the key
	 *
	 * @param {String} $cache_key
	 *
	 * @return {Boolean}
	 */
	private function set_config($cache_key) {
		$config_file = $this->cache_path . $this->config_file;
		$configs = array();

		if (file_exists($config_file))
			$configs = unserialize(file_get_contents($config_file));

		$configs[$cache_key] = array (
			'compressed' => $this->compress_level > 0,
			'cache_time' => $this->cache_time
		);

		// Put the cache config to the file
		file_put_contents($config_file, serialize($configs));

		return true;
	}

	/**
	 * Get the config for the key
	 *
	 * @param {String} $cache_key
	 *
	 * @return {Mixed}
	 */
	private function get_config($cache_key) {
		$config_file = $this->cache_path . $this->config_file;

		if (file_exists($config_file)) {
			$config = unserialize(file_get_contents($config_file));
			if (isset($config[$cache_key]))
				return $config[$cache_key];
		}

		return false;
	}

	/**
	 * Helper function for retrieving data from url
	 *
	 * @param {String} $url
	 *
	 * @return {String}
	 */
	public function get_contents($url) {
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
	private function safe_filename($filename) {
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
