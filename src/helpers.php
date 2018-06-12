<?php
/**
 * Helper Functions File
 *
 * You may copy, distribute and modify the software as long as you track changes/dates in source files.
 * Any modifications to or software including (via compiler) GPL-licensed code must also be made
 * available under the GPL along with build & install instructions.
 *
 * @package    WPS\Functions
 * @author     Travis Smith <t@wpsmith.net>
 * @copyright  2015-2018 Travis Smith
 * @license    http://opensource.org/licenses/gpl-2.0.php GNU Public License v2
 * @link       https://github.com/wpsmith/WPS
 * @version    1.0.0
 * @since      0.1.0
 */

namespace WPS;
use WPS\AsyncTransients\Transient;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes a given transient
 *
 * @param string $transient The key for the transient to delete
 *
 * @return bool Result of delete_option
 */
function delete_async_transient( $transient ) {
	return Transient::get_instance()->delete( $transient );
}

/**
 * Returns the value of an async transient.
 *
 * @param string $transient The key of the transient to return
 * @param Callable $regenerate_function The function to call to regenerate the transient when it is expired
 * @param array $regenerate_params Array of parameters to pass to the callback when regenerating the transient.
 *
 * @return mixed
 */
function get_async_transient( $transient, $regenerate_function, $regenerate_params = array() ) {
	return Transient::get_instance()->get( $transient, $regenerate_function, $regenerate_params );
}

/**
 * Returns the value of an async transient.
 *
 * @param string $transient The key of the transient to return
 * @param Callable $regenerate_function The function to call to regenerate the transient when it is expired
 * @param array $regenerate_params Array of parameters to pass to the callback when regenerating the transient.
 *
 * @return mixed
 */
function get_stale_transient( $transient ) {
	return Transient::get_instance()->get_stale( $transient );
}

/**
 * Set the value of an async transient.
 *
 * @param string $transient Unique key for the transient
 * @param mixed $value The value to store for the transient
 * @param int $expiration Number of seconds until the transient should be considered expired.
 *
 * @return bool
 */
function set_async_transient( $transient, $value, $expiration ) {
	return Transient::get_instance()->set( $transient, $value, $expiration );
}