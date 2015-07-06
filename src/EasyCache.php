<?php

namespace iProDev\Util;

/*
 * EasyCache v1.0.0
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
		// Compress
		if ($this->compress_level > 0) {
			$data = compress($data);
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
				$data = uncompress($data);
			}

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
		if(function_exists("curl_init")){
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
			$content = curl_exec($ch);
			curl_close($ch);
			return $content;
		} else {
			return file_get_contents($url);
		}
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
			$contents = gzinflate($contents, $this->within($this->compress_level, 1, 9));
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
}
