<?php
/**
 * AsyncTransients Class
 *
 * Provides an interface for an improved experience with WordPress transients.
 * Implementation of async transients for WordPress. If transients are expired,
 * stale data is served, and the transient is queued up to be regenerated on shutdown.
 *
 * You may copy, distribute and modify the software as long as you track changes/dates in source files.
 * Any modifications to or software including (via compiler) GPL-licensed code must also be made
 * available under the GPL along with build & install instructions.
 *
 * @package    WPS\AsyncTransients
 * @author     Chris Marslender
 * @author     Travis Smith <t@wpsmith.net>
 * @copyright  2018 Travis Smith, Chris Marslender
 * @license    http://opensource.org/licenses/gpl-2.0.php GNU Public License v2
 * @link       https://github.com/wpsmith/WPS
 * @since      File available since Release 1.0.0
 */

namespace WPS\AsyncTransients;

use WPS\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WPS\AsyncTransients\Transient' ) ) {
	/**
	 * Implementation of async transients for WordPress. If transients are expired, stale data is served, and the transient
	 * is queued up to be regenerated on shutdown.
	 *
	 * Class Transient
	 *
	 * @package WPS\AsyncTransients
	 */
	class Transient extends Core\Singleton {

		/**
		 * Array of callbacks to be processed on shutdown.
		 *
		 * @var array
		 */
		protected $queue;

		/**
		 * Transients prefix.
		 *
		 * @var string
		 */
		protected $prefix = '_wps_async_';

		/**
		 * Transient constructor.
		 */
		public function __construct() {
			$this->queue = array();
			$this->setup();
		}

		/**
		 * Returns the prefix.
		 *
		 * @return string
		 */
		public function get_prefix() {
			return $this->prefix;
		}

		/**
		 * Sets up required event hooks for the plugin.
		 */
		public function setup() {
			add_action( 'shutdown', array( $this, 'finish_request' ), 100 );
		}

		/**
		 * Runs all the transient regeneration callbacks after closing the connection to the browser.
		 *
		 * If `fastcgi_finish_request` is not available, the function immediately returns. There will be no queued callbacks
		 * in this instance, since the ->add_to_queue() method will process the callback immediately in those cases.
		 */
		public function finish_request() {
			// Bail if we don't have fastcgi_finish_request.
			// Nothing should be in the queue anyways, since we don't add to queue if this function is not available.
			// @see $this->add_to_queue.
			if ( ! function_exists( 'fastcgi_finish_request' ) ) {
				return;
			}

			fastcgi_finish_request();
			set_time_limit( 0 );

			foreach ( $this->queue as $item ) {
				call_user_func_array( $item['function'], $item['params'] );
			}
		}

		/**
		 * Deletes a given transient
		 *
		 * @param string $transient The key for the transient to delete.
		 *
		 * @return bool Result of delete_option.
		 */
		public function delete( $transient ) {
			$option_timeout = $this->prefix . 'timeout_' . $transient;
			$option         = $this->prefix . $transient;
			$result         = delete_option( $option );
			if ( $result ) {
				delete_option( $option_timeout );
			}

			return $result;
		}

		/**
		 * Returns the value of an async transient.
		 *
		 * @param string   $transient           The key of the transient to return.
		 * @param Callable $regenerate_function The function to call to regenerate the transient when it is expired.
		 * @param array    $regenerate_params   Array of parameters to pass to the callback when regenerating the transient.
		 *
		 * @return mixed
		 */
		public function get( $transient, $regenerate_function, $regenerate_params = array() ) {
			$regenerate       = false;
			$transient_option = $this->prefix . $transient;

			// If option is not in alloptions, it is not autoloaded and thus has a timeout
			$alloptions = wp_load_alloptions();
			if ( ! isset( $alloptions[ $transient_option ] ) ) {
				$transient_timeout = '_async_transient_timeout_' . $transient;
				$timeout           = get_option( $transient_timeout );
				if ( false !== $timeout && $timeout < time() ) {
					$regenerate = true;

				}
			}

			$value = get_option( $transient_option );
			if ( $value === false ) {
				$regenerate = true;
			}

			if ( $regenerate === true ) {
				\WPS\write_log( 'needs regeneration' );
				// Set this up to be refreshed later
				$this->add_to_queue( $regenerate_function, $regenerate_params );
			}

			return $value;
		}

		/**
		 * Gets the transient value regardless of timeout.
		 *
		 * @param string $transient The key of the transient to return
		 *
		 * @return mixed Value of the transient.
		 */
		public function get_stale( $transient ) {
			$transient_option = $this->prefix . $transient;

			return get_option( $transient_option );
		}

		/**
		 * Set the value of an async transient.
		 *
		 * @param string $transient  Unique key for the transient.
		 * @param mixed  $value      The value to store for the transient.
		 * @param int    $expiration Number of seconds until the transient should be considered expired.
		 *
		 * @return bool $result Whether transient was set.
		 */
		public function set( $transient, $value, $expiration ) {
			$expiration = (int) $expiration;

			$transient_timeout = '_async_transient_timeout_' . $transient;
			$transient_option  = $this->prefix . $transient;
			if ( false === get_option( $transient_option ) ) {
				$autoload = 'yes';
				if ( $expiration ) {
					$autoload = 'no';
					add_option( $transient_timeout, time() + $expiration, '', 'no' );
				}
				$result = add_option( $transient_option, $value, '', $autoload );
			} else {
				// If expiration is requested, but the transient has no timeout option,
				// delete, then re-create transient rather than update.
				$update = true;
				if ( $expiration ) {
					if ( false === get_option( $transient_timeout ) ) {
						delete_option( $transient_option );
						add_option( $transient_timeout, time() + $expiration, '', 'no' );
						$result = add_option( $transient_option, $value, '', 'no' );
						$update = false;
					} else {
						update_option( $transient_timeout, time() + $expiration );
					}
				}
				if ( $update ) {
					$result = update_option( $transient_option, $value );
				}
			}

			return $result;
		}

		/**
		 * Adds a callback function to the queue of callbacks to be run on shutdown.
		 *
		 * If `fastcgi_finish_request` is unavailable, the callback is called immediately.
		 *
		 * @param Callable $function     Callback function.
		 * @param array    $params_array Array to pass to function.
		 */
		public function add_to_queue( $function, $params_array = array() ) {

			if ( function_exists( 'fastcgi_finish_request' ) ) {
				/*
				 * Generates a unique hash of the function + params, to make sure we only process a callback for one
				 * combination even if it is accidentally added multiple times in a request for the same set of data
				 *
				 * spl_object_hash, in case a closure is passed, instead of a function name
				 * json_encode takes care of the cases where an array or string is provided
				 */
				$function_hash = is_object( $function ) ? spl_object_hash( $function ) : json_encode( $function );
				$hash          = md5( $function_hash . json_encode( $params_array ) );
				if ( ! isset( $this->queue[ $hash ] ) ) {
					$this->queue[ $hash ] = array(
						'function' => $function,
						'params'   => $params_array,
					);
				}
			} else {
				// We don't have fastcgi_finish_request available, so refresh the transient now instead of queuing it for later.
				call_user_func_array( $function, $params_array );
			}
		}

	}
}
