<?php
/**
 * Plugin Name:       The Events Calendar Extension: Custom Datepicker Start Date
 * Description:       Restrict the event start date for non-Administrator users. Disallows setting a start date in the past by default. Filters exist for customizing to something else and for setting a maxDate. Works for new and existing events on the wp-admin event add/edit screen and, if applicable, the Community Events add/edit event form.
 * Version:           1.0.0
 * Extension Class:   Tribe__Extension__Custom_Datepicker_Start_Date
 * GitHub Plugin URI: https://github.com/mt-support/tribe-ext-custom-datepicker-start-date
 * Author:            Modern Tribe, Inc.
 * Author URI:        http://m.tri.be/1971
 * License:           GPL version 3 or any later version
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       tribe-ext-custom-datepicker-start-date
 *
 *     This plugin is free software: you can redistribute it and/or modify
 *     it under the terms of the GNU General Public License as published by
 *     the Free Software Foundation, either version 3 of the License, or
 *     any later version.
 *
 *     This plugin is distributed in the hope that it will be useful,
 *     but WITHOUT ANY WARRANTY; without even the implied warranty of
 *     MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *     GNU General Public License for more details.
 */

/**
 * Known issues:
 * 1)
 * If the user's local time zone and the site's time zone are on different
 * calendar days (such as UTC+0 and UTC+1 and UTC is at 23:00:00), the
 * datepicker will display the user's today as selectable but it won't be
 * selectable. This is correct because the site owner does not want the user to
 * select their own today because it's the site's yesterday.
 *
 * This odd UX is due to the jQuery UI Datepicker library not supporting
 * time zones and the timepicker being a separate input.
 *
 * 2)
 * All "protection" against setting an invalid start date is in JavaScript--none
 * in PHP.
 */

// Do not load unless Tribe Common is fully loaded and our class does not yet exist.
if ( class_exists( 'Tribe__Extension' ) && ! class_exists( 'Tribe__Extension__Custom_Datepicker_Start_Date' ) ) {
	/**
	 * Extension main class, class begins loading on init() function.
	 */
	class Tribe__Extension__Custom_Datepicker_Start_Date extends Tribe__Extension {
		/**
		 * The script's handle.
		 */
		private $handle = 'tribe-ext-custom-datepicker-start-date';

		/**
		 * The array of keys/values to get sent to the script
		 *
		 * @see wp_localize_script()
		 *
		 * @var array
		 */
		private $script_vars = array();

		/**
		 * The script's handle with underscores instead of hyphens.
		 *
		 * Used for building filter and action hook names and CSS class name.
		 *
		 * @return string
		 */
		private function get_handle_underscores() {
			return str_replace( '-', '_', $this->handle );
		}

		/**
		 * The input's CSS error class.
		 *
		 * @return string
		 */
		private function get_error_css_class() {
			return $this->get_handle_underscores() . '_error';
		}

		/**
		 * The WordPress capability required to be able to pick any date.
		 *
		 * @return string
		 */
		private function get_cap_allowed_any_date() {
			/**
			 * The capability required to have this script not load.
			 *
			 * @param string $capability The minimum capability to be allowed to
			 *                           choose any start date.
			 *
			 * @return string
			 */
			return (string) apply_filters( $this->get_handle_underscores() . '_cap_allowed_any_start_date', 'manage_options' );

		}

		/**
		 * Set the minimum required version of The Events Calendar
		 * and this extension's URL.
		 */
		public function construct() {
			$this->add_required_plugin( 'Tribe__Events__Main' );
			$this->set_url( 'https://theeventscalendar.com/extensions/custom-datepicker-start-date/' );
		}

		/**
		 * Extension initialization and hooks.
		 */
		public function init() {
			// Load plugin textdomain
			load_plugin_textdomain( $this->handle, false, basename( dirname( __FILE__ ) ) . '/languages/' );

			// Requires PHP 5.3+ to use DateTime::setTimestamp()
			if ( version_compare( PHP_VERSION, '5.3', '<' ) ) {
				$message = '<p>' . $this->get_name();

				$message .= __( ' requires PHP 5.3 or newer to work. Please contact your website host and inquire about updating PHP.', 'tribe-ext-custom-datepicker-start-date' );

				$message .= sprintf( ' <a href="%1$s">%1$s</a>', 'https://wordpress.org/about/requirements/' );

				$message .= '</p>';

				tribe_notice( $this->get_name(), $message, 'type=error' );

				return;
			}

			add_action( 'init', array( $this, 'register_assets' ) );

			add_action( 'admin_enqueue_scripts', array( $this, 'load_assets_for_event_admin_edit_screen' ) );

			// This action hook exists as of Community Events version 4.4
			add_action( 'tribe_community_events_enqueue_resources', array( $this, 'load_assets_for_ce_form' ) );

			// Add the error class' <style> to the <head>.
			add_action( 'wp_head', array( $this, 'validation_style' ) );
			add_action( 'admin_head', array( $this, 'validation_style' ) );
		}

		/**
		 * Output the error class' <style>.
		 */
		public function validation_style() {
			/**
			 * This styling is almost exactly copied from wp-admin's forms.css.
			 * Bonus: the red color is the same as from
			 * .tribe-community-events .tribe-community-notice.tribe-community-notice-error
			 */
			?>
			<style id="<?php echo $this->get_handle_underscores(); ?>">
				.<?php echo $this->get_error_css_class(); ?> {
					border-color: #dc3232 !important;
					box-shadow: 0 0 2px rgba(204, 0, 0, 0.8) !important;
				}
			</style>
			<?php
		}

		/**
		 * Register this extension's asset(s).
		 */
		public function register_assets() {
			$resources_url = trailingslashit( plugin_dir_url( __FILE__ ) ) . 'src/resources/';

			$js = $resources_url . 'js/script.js';

			// `tribe-events-admin` dependency so the `tribe_datepicker_opts` JS variable is set by the time we need to extend it
			// which comes from /wp-content/plugins/the-events-calendar/src/resources/js/events-admin.js
			wp_register_script( $this->handle, $js, array(
				'jquery',
				'tribe-events-admin'
			), $this->get_version(), true );
		}

		/**
		 * Get the datepicker format from TEC settings. Default to 'Y-m-d'.
		 *
		 * @return string
		 */
		private function get_datepicker_format() {
			$datepicker_format = Tribe__Date_Utils::datepicker_formats( tribe_get_option( 'datepickerFormat', 'Y-m-d' ) );

			return $datepicker_format;
		}

		/**
		 * Build the $this->script_vars array.
		 *
		 * @see wp_localize_script()
		 *
		 * @param int $post_id
		 */
		private function build_script_vars( $post_id = 0 ) {
			/**
			 * The value to send to jQuery UI Datepicker's initial maxDate value
			 * for the start date.
			 *
			 * For example: Useful if you want to restrict the start date to be
			 * no more than 3 weeks in the future, in which case you would
			 * filter it to be "3w".
			 *
			 * @link https://jqueryui.com/datepicker/#min-max
			 *
			 * @param string $max_date The start datepicker's maxDate.
			 * @param int $post_id The Post ID.
			 *
			 * @return bool
			 */
			$max_date = apply_filters( $this->get_handle_underscores() . '_max_date', '', $post_id );

			$this->script_vars['min_date']    = $this->get_min_allowed_start_date( $post_id );
			$this->script_vars['max_date']    = $max_date;
			$this->script_vars['error_class'] = $this->get_error_css_class();
		}

		/**
		 * Get the time zone to use for date calculations.
		 *
		 * @param int $post_id
		 *
		 * @return string
		 */
		private function get_time_zone_string( $post_id = 0 ) {
			$time_zone = Tribe__Events__Timezones::get_event_timezone_string( $post_id );

			/**
			 * Override the time zone used for date calculations.
			 *
			 * @param string $time_zone A named time zone (not manual UTC offset).
			 * @param int $post_id The Post ID.
			 *
			 * @return string
			 */
			return (string) apply_filters( $this->get_handle_underscores() . '_time_zone', $time_zone, $post_id );
		}

		/**
		 * Get the timestamp of "today at midnight" (the first second of today)
		 * or midnight of a given timestamp.
		 *
		 * Because the datepicker is separate from the timepicker, we need to
		 * make sure we are using midnight whenever setting the script's minDate.
		 *
		 * @see current_time()
		 *
		 * @param int $post_id
		 * @param bool|int $timestamp
		 *
		 * @return int
		 */
		private function get_midnight_timestamp( $post_id = 0, $timestamp = false ) {
			$datetime = new DateTime();

			if ( empty( $timestamp ) ) {
				$timestamp = (int) current_time( 'timestamp' );
			} else {
				$timestamp = (int) $timestamp;
			}

			$datetime->setTimestamp( $timestamp );

			$tz_string = $this->get_time_zone_string( $post_id );

			if ( ! in_array( $tz_string, timezone_identifiers_list() ) ) {
				// This will fallback to UTC but may also return a TZ environment variable (e.g. EST), which could cause an error for DateTimeZone().
				$tz_string = date_default_timezone_get();
			}

			$time_zone = new DateTimeZone( $tz_string );

			$datetime->setTimezone( $time_zone );

			// $datetime->setTime(0,0,0) will actually fast forward to tomorrow if in the 23rd hour so we do it this way instead...
			$day = $datetime->format( 'Y-m-d' );

			$day .= ' 00:00:00 ' . $tz_string;

			$result = strtotime( $day ); // may return FALSE

			return $result;
		}

		/**
		 * Get the minimum allowed start date timestamp.
		 *
		 * @param int $post_id
		 *
		 * @return int
		 */
		private function get_min_allowed_start_date( $post_id = 0 ) {
			// Will be FALSE for Add New Event
			$existing_start_date = Tribe__Events__Timezones::event_start_timestamp( $post_id );

			$existing_start_date = $this->get_midnight_timestamp( $post_id, $existing_start_date );

			$today = $this->get_midnight_timestamp( $post_id );

			if ( is_int( $existing_start_date ) && is_int( $today ) && $today !== $existing_start_date ) {
				$start_date = min( $existing_start_date, $today );
			} else {
				$start_date = $today;
			}

			/**
			 * Override the minimum start date timestamp.
			 *
			 * Make sure it is *midnight in the site's/event's time zone* of the
			 * allowed date. Example use case: you want to allow choosing start
			 * dates up to 1 week in the past. Make sure to account for existing
			 * events' start date or else existing events' start dates will be
			 * set to the minDate without any UI notice it happened.
			 *
			 * @param int $start_date The minimum allowable timestamp (midnight!).
			 * @param int $post_id The Post ID.
			 * @param string $existing_start_date The event's existing start date.
			 *
			 * @return int
			 */
			$start_date_filtered = (int) apply_filters( $this->get_handle_underscores() . '_min_start_timestamp', $start_date, $post_id, $existing_start_date );

			// Protect against accidental value turned into zero due to (int).
			if ( empty( $start_date_filtered ) ) {
				$start_date_filtered = $start_date;
			}

			return $start_date_filtered;
		}

		/**
		 * Load this extension's script on the wp-admin event add/edit screen.
		 */
		public function load_assets_for_event_admin_edit_screen() {
			// bail if not on the wp-admin event add/edit screen
			global $current_screen;
			global $post;

			$load_script = true;

			if (
				current_user_can( $this->get_cap_allowed_any_date() )
				|| ! class_exists( 'Tribe__Admin__Helpers' )
				|| ! Tribe__Admin__Helpers::instance()->is_post_type_screen( Tribe__Events__Main::POSTTYPE )
				|| empty( $current_screen->base )
				|| 'post' !== $current_screen->base // the wp-admin add/edit screen
			) {
				$load_script = false;
			}

			/**
			 * Whether or not the script should load in wp-admin.
			 *
			 * Useful to override for specific users or other scenarios. For
			 * example: allow selecting any start date for a specific Post ID.
			 *
			 * @param bool $load_script Whether or not to load the script.
			 * @param WP_Screen $current_screen The wp-admin global $current_screen.
			 * @param WP_Post $post The WP_Post object.
			 *
			 * @return bool
			 */
			$load_script = (bool) apply_filters( $this->get_handle_underscores() . '_load_script_wp_admin', $load_script, $current_screen, $post );

			if ( $load_script ) {
				wp_enqueue_script( $this->handle );

				// Pass the PHP to the JS.
				$this->build_script_vars( $post->ID );
				wp_localize_script( $this->handle, 'php_vars', $this->script_vars );
			}
		}

		/**
		 * Load this extension's script on Community Events' event add/edit form.
		 */
		public function load_assets_for_ce_form() {
			global $tribe_community_event_id;

			$post_id = $tribe_community_event_id;

			$load_script = true;

			// allow Administrators to do anything
			if ( current_user_can( $this->get_cap_allowed_any_date() ) ) {
				$load_script = false;
			}

			/**
			 * Whether or not the script should load on the Community Events
			 * event add/edit form.
			 *
			 * Useful to override for specific users or other scenarios. For
			 * example: allow selecting any start date for a specific Post ID.
			 *
			 * @param bool $load_script Whether or not to load the script.
			 * @param int $post_id The Post ID.
			 *
			 * @return bool
			 */
			$load_script = (bool) apply_filters( $this->get_handle_underscores() . '_load_script_ce_form', $load_script, $post_id );

			if ( $load_script ) {
				wp_enqueue_script( $this->handle );

				// Pass the PHP to the JS.
				$this->build_script_vars( $post_id );
				wp_localize_script( $this->handle, 'php_vars', $this->script_vars );
			}
		}

	} // end class
} // end if class_exists check
