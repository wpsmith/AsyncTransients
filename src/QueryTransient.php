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
 * @package    WPS\WP\AsyncTransients
 * @author     Chris Marslender
 * @author     Travis Smith <t@wpsmith.net>
 * @copyright  2018-2019 Travis Smith, Chris Marslender
 * @license    http://opensource.org/licenses/gpl-2.0.php GNU Public License v2
 * @link       https://github.com/wpsmith/WPS
 * @since      File available since Release 1.0.0
 */

namespace WPS\WP\AsyncTransients;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\QueryTransient' ) ) {
	class QueryTransient {

		/**
		 * Current transient being registered.
		 *
		 * @since 1.0.0
		 *
		 * @var string
		 */
		public $name = '';

		/**
		 * Current transient type being registered.
		 *
		 * Supported versions: query, taxonomy, & other
		 *
		 * @since 1.0.0
		 *
		 * @var string
		 */
		private $type = 'query';

		/**
		 * Original class args.
		 *
		 * @since 1.0.0
		 *
		 * @var array
		 */
		private $args = array();

		/**
		 * Query args for query transient.
		 *
		 * @since 1.0.0
		 *
		 * @var mixed
		 */
		public $query_args = array();

		/**
		 * Current transient value.
		 *
		 * @since 1.0.0
		 *
		 * @var mixed
		 */
		private $value = null;

		/**
		 * Current transient value without checking to see if
		 * the transient has expired.
		 *
		 * @since 1.0.0
		 *
		 * @var mixed
		 */
		private $pre_transient_value;

		/**
		 * Whether to always return the transient value
		 * without checking for expiration.
		 *
		 * @since 1.0.0
		 *
		 * @var bool
		 */
		private $return_pre = true;

		/**
		 * Default transient timeout, one day.
		 *
		 * @since 1.0.0
		 *
		 * @var int
		 */
		private $timeout = 86400;

		/**
		 * Constructor. Hooks all interactions to initialize the class.
		 *
		 * @since 1.0.0
		 */
		public function __construct( $args = array() ) {

			$defaults = array(
				'name'          => '',
				'type'          => 'query',
				'timeout'       => 86400, // 1 day
				'value'         => null,
				'query_args'    => array(),
				'pre_transient' => true,
				'return_pre'    => true,
			);

			// Set args
			$this->args = wp_parse_args( $args, $defaults );

			// Maybe set name.
			if ( '' !== $this->args['name'] ) {
				$this->name = self::truncate_length( $this->args['name'], 40 );
			}

			// Set type
			$this->type = $this->args['type'];

			// Set timeout
			$this->set_timeout( $this->args['timeout'] );

			// Set pre
			if ( true !== $this->args['return_pre'] ) {
				$this->return_pre = $this->args['return_pre'];
			}

			// Set value
			if ( ! is_null( $this->args['value'] ) ) {
				$this->set_value( $this->args['value'] );
			}

			// Set query_args & set value
			if ( 'query' === $this->type && ! empty( $args['query_args'] ) ) {
				$this->set_query_args( $args['query_args'] );
			}

			// Set pre-transient value
			if ( $this->args['pre_transient'] ) {
				$this->set_pre_transient();
			}

			// Set the value, if not already set
			if ( '' !== $this->name && is_null( $this->value ) ) {
				$this->value = $this->get_transient();
			}

			// Initiate hooks
			$this->create();

		}

		/**
		 * Sets transients hooks into pretransient.
		 *
		 * @since 1.0.0
		 */
		public function create() {

			// Make sure $name is set
			if ( '' === $this->name ) {
				return new \WP_Error( 'name-not-set', __( 'Set transient name', 'wps' ), $this
				);
			}

			// Pre transient
			if ( $this->args['pre_transient'] ) {
				add_filter( 'pre_transient_' . $this->name, array( $this, 'set_pre_transient' ) );
			}

			// Clear transient
			if ( 'query' === $this->type || 'taxonomy' === $this->type ) {
				add_action( 'delete_post', array( $this, 'clear_query_transient' ), 10 );
				add_action( 'save_post', array( $this, 'clear_query_transient' ), 10, 3 );
			}
			// $this->_create( 'set_transient' );

		}

		/** SET PROPERTY FUNCTIONS **/

		public function set_query_args( $args ) {

			$this->query_args = wp_parse_args( $args, $this->args );

		}

		/**
		 * Change Timeout from the default 86400.
		 *
		 * @since 1.0.0
		 *
		 * @param string $timeout New Default Timeout.
		 */
		public function set_timeout( $timeout ) {

			$this->timeout = absint( $timeout );

		}

		/**
		 * Changes Transient Value & optionally resets transient.
		 *
		 * @since 1.0.0
		 *
		 * @param mixed $value New value.
		 */
		public function set_value( $value, $reset_transient = true ) {

			$this->value = $value;

			if ( $reset_transient && '' !== $this->name ) {

				$this->set_transient();

			}

		}

		/**
		 * Sets transient's value before checking to see if it expired.
		 *
		 * Hooks into pre_transient_TRANSIENTNAME hook, which is called when
		 * get_transient() is called.
		 *
		 * @since  1.0.0
		 * @date   2014-07-13
		 * @author Travis Smith <t(at)wpsmith.net>}
		 *
		 * @see    _get_pre_transient()
		 *
		 * @return  bool Returns false.
		 *
		 */
		public function set_pre_transient() {

			$this->pre_transient_value = $this->_get_pre_transient();

			if ( $this->return_pre ) {
				$this->init_cron();

				return $this->pre_transient_value;
			}

			return false;

		}

		/** GET PROPERTY FUNCTIONS **/

		public function get_value( $fresh = false ) {

			if ( $fresh && 'query' === $this->type ) {
				$this->value = new \WP_Query( $this->query_args );
			}

			return $this->value;

		}

		public function get_pre_transient() {

			return $this->pre_transient_value;

		}

		/** CRON FUNCTIONS **/

		private function init_cron() {

			// Add cron, to update the transient
			add_action( 'wps_get_transient', array( $this, 'cron_set_transient' ) );
			wp_schedule_single_event( time(), 'wps_get_transient' );
			// wp_schedule_single_event( time(), 'wps_get_transient', array( $this ) );

		}

		public function cron_set_transient() {

			return $this->get_transient( 'fresh' );

		}

		/** TRANSIENT FUNCTIONS **/

		/**
		 * Gets transient value before checking if it is expired.
		 *
		 * @since  1.0.0
		 * @date   2014-07-13
		 * @author Travis Smith <t(at)wpsmith.net>
		 *
		 * @return mixed         Transient value.
		 * @access private
		 */
		private function _get_pre_transient() {

			return get_option( '_transient_' . $this->name );

		}

		public function get_transient( $fresh = false ) {

			if ( $fresh ) {
				$this->set_transient( $fresh );

				return $this->get_value();
			}

			// Check transient, will return false if expired
			// If expired, get_transient() will delete the transient
			if ( false === ( $value = get_transient( $this->name ) ) ) {

				// Set new transient
				$this->set_transient( $fresh );

			}

			// Return value
			return $value;

		}

		public function set_transient( $fresh = false ) {

			set_transient( $this->name, $this->get_value( $fresh ), $this->timeout );

		}

		/** DELETE TRANSIENTS **/

		/**
		 * Deletes a transient.
		 *
		 */
		public function delete() {

			delete_transient( $this->name );

		}

		/**
		 * Clears transient.
		 *
		 * @param int      $post_id Post ID.
		 * @param \WP_Post $post    Post object.
		 * @param bool     $update  Whether this is an existing post being updated or not.
		 */
		public function clear_transient( $post_id, $post, $update ) {

			// Set Post Type
			if ( 'query' === $this->type && ! isset( $this->args['post_type'] ) ) {
				$this->args['post_type'] = 'post';
			}

			// Don't do anything if it is a revision
			if ( wp_is_post_revision( $post_id ) || self::bail( $this->args['post_type'] ) ) {
				return;
			}

			// Check type, if taxonomy type, bail if object does not have a term from the taxonomy
			if (
				'taxonomy' === $this->type &&
				( isset( $this->args['taxonomy'] ) && ! in_array( $this->args['taxonomy'], get_object_taxonomies( $post ) ) )
			) {
				return;
			}

			// Ok, now delete transient
			$this->delete();

		}

		/**
		 * Clears all transient with a specific prefix.
		 *
		 * @since 1.1.0
		 *
		 * @param string $prefix Prefix to remove.
		 */
		public static function clear_transients( $prefix ) {

			global $wpdb;
			$sql = "DELETE FROM $wpdb->options WHERE `option_name` LIKE '%transient_%1$s%' OR `option_name` LIKE '%transient_timeout_%1$s%'";

			return $wpdb->get_results( $wpdb->prepare( $sql, $prefix ) );

		}

		/**
		 * Clears all transients.
		 *
		 * @since 1.1.0
		 *
		 */
		public static function clear_all_transients( $prefix ) {

			global $wpdb;
			$sql = "DELETE FROM $wpdb->options WHERE `option_name` LIKE '%transient_%' OR `option_name` LIKE '%transient_timeout_%'";

			return $wpdb->get_results( $wpdb->prepare( $sql, $prefix ) );

		}

		/**
		 * Truncates string based on length of characters.
		 *
		 * This function will truncate a string at a specific length if string is longer.
		 *
		 * @since  2.0.0
		 * @date   2014-07-12
		 * @author Travis Smith <t(at)wpsmith.net>}
		 *
		 * @param  string $string String being modified.
		 * @param  int    $length Number of characters to limit string.
		 *
		 * @return string                                 Modified string if string longer than $length.
		 */
		protected static function truncate_length( $string, $length = 40 ) {
			return ( strlen( $string ) > $length ) ? substr( $string, 0, $length ) : $string;
		}

		/**
		 * Whether to bail from current function.
		 * Checks whether doing autosave, ajax, or cron.
		 * Also checks for correct post type.
		 *
		 * @param string $post_type Registered post type name.
		 * @param string $cap       Capability.
		 * @param int    $post_id   Post ID.
		 *
		 * @return bool Whether to bail from current function.
		 */
		protected static function bail( $post_type = '', $cap = 'edit_post', $post_id = null ) {
			/** Bail out if running an autosave */
			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return true;
			}

			/** Bail out if running an ajax */
			if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
				return true;
			}

			/** Bail out if running a cron */
			if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
				return true;
			}

			/** Bail out if not correct post type */
			if ( ! empty( $post_type ) && isset( $_POST['post_type'] ) && $pt !== $_POST['post_type'] ) {
				return true;
			}

			/** Bail out if user does not have permissions */
			if ( null !== $post_id && '' != $post_id && ! current_user_can( $cap, $post_id ) ) {
				return $post_id;
			}

			return false;
		}

	}
}
