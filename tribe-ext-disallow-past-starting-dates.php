<?php
/**
 * Plugin Name:       The Events Calendar Extension: Custom Datepicker Start Date
 * Plugin URI:        https://theeventscalendar.com/extensions/custom-datepicker-start-date/
 * GitHub Plugin URI: https://github.com/mt-support/tribe-ext-custom-datepicker-start-date
 * Description:       Restrict the event start date for non-Administrator users. Disallows setting a start date in the past by default. Filters exist for customizing to something else and for setting a maximum future start date. Works for new and existing events on the wp-admin event add/edit screen and, if applicable, the Community Events add/edit event form.
 * Version:           1.1.0
 * Extension Class:   Tribe\Extensions\Custom_Datepicker_Start_Date\Main
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

namespace Tribe\Extensions\Custom_Datepicker_Start_Date;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Tribe__Admin__Helpers;
use Tribe__Date_Utils;
use Tribe__Events__Main;
use Tribe__Events__Timezones;
use Tribe__Extension;
use WP_Post;
use WP_Screen;

// Do not load unless Tribe Common is fully loaded and our class does not yet exist.
if (
	class_exists( 'Tribe__Extension' )
	&& ! class_exists( Main::class )
) {
	/**
	 * Extension main class, class begins loading on init() function.
	 */
	class Main extends Tribe__Extension {
		/**
		 * The script's handle (also the text domain).
		 */
		public $handle = 'tribe-ext-custom-datepicker-start-date';

		/**
		 * The array of keys/values to send to the script.
		 *
		 * @see wp_localize_script()
		 *
		 * @var array
		 */
		private $script_vars = [];

		/**
		 * The existing start date's midnight timestamp.
		 *
		 * This will remain NULL if this is a new (non-existing) event or if its
		 * start date is TODAY.
		 *
		 * @see get_min_date()
		 *
		 * @var null|int
		 */
		private $timestamp_existing_start_date = null;

		/**
		 * The timestamp of whichever is greater between Today and an existing
		 * event's start time, if applicable.
		 *
		 * This will remain NULL if this is a new (non-existing) event or if its
		 * start date is TODAY.
		 *
		 * @see get_min_date()
		 *
		 * @var null|int
		 */
		private $timestamp_max_today_or_existing = null;

		/**
		 * The input's CSS error class.
		 *
		 * @return string
		 */
		private function get_error_css_class() {
			return $this->handle . '-error';
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
			return (string) apply_filters( 'tribe_ext_start_datepicker_cap_allowed_any_start_date', 'manage_options' );

		}

		/**
		 * Set the minimum required version of The Events Calendar.
		 *
		 * @since 1.1.0 TEC 5.1.1 is when the block editor Day Picker added disabled days (which is filterable).
		 */
		public function construct() {
			$this->add_required_plugin( 'Tribe__Events__Main' );
			//$this->add_required_plugin( 'Tribe__Events__Main', '5.1.1' );
		}

		/**
		 * Extension initialization and hooks.
		 */
		public function init() {
			// Load plugin textdomain
			load_plugin_textdomain( $this->handle, false, basename( dirname( __FILE__ ) ) . '/languages/' );

			if ( ! $this->php_version_check() ) {
				return;
			}

			add_action( 'init', [ $this, 'register_assets' ] );

			add_action( 'admin_enqueue_scripts', [ $this, 'load_for_classic_editor_admin' ] );

			// Since WP v5.0.0.
			add_action( 'enqueue_block_assets', [ $this, 'load_for_block_editor' ] );

			// This action hook exists as of Community Events version 4.4
			add_action( 'tribe_community_events_enqueue_resources', [ $this, 'load_assets_for_ce_form' ] );

			// Add the error class' <style> to the <head>.
			add_action( 'wp_head', [ $this, 'validation_style' ] );
			add_action( 'admin_head', [ $this, 'validation_style' ] );
		}

		/**
		 * Check if we have a sufficient version of PHP. Admin notice if we don't and user should see it.
		 *
		 * @link https://theeventscalendar.com/knowledgebase/php-version-requirement-changes/ All extensions require PHP 5.6+.
		 *
		 * @return bool
		 */
		private function php_version_check() {
			$php_required_version = '5.6';

			if ( version_compare( PHP_VERSION, $php_required_version, '<' ) ) {
				if (
					is_admin()
					&& current_user_can( 'activate_plugins' )
				) {
					$message = '<p>';

					$message .= sprintf(
						__(
							'%s requires PHP version %s or newer to work. Please contact your website host and inquire about updating PHP.',
							$this->handle
						),
						$this->get_name(),
						$php_required_version
					);

					$message .= sprintf( ' <a href="%1$s">%1$s</a>', 'https://wordpress.org/about/requirements/' );

					$message .= '</p>';

					tribe_notice( $this->handle . '-php-version', $message, [ 'type' => 'error' ] );
				}

				return false;
			}

			return true;
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
			<style id="<?php echo $this->handle; ?>">
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

			// For the wp-admin Classic editor and Community Events form.
			// `tribe-events-admin` dependency so the `tribe_datepicker_opts` JS variable is set by the time we need to extend it
			// which comes from /wp-content/plugins/the-events-calendar/src/resources/js/events-admin.js
			wp_register_script(
				$this->handle . '-classic',
				$resources_url . 'js/classic.js',
				[
					'jquery',
					'tribe-events-admin',
				],
				$this->get_version(),
				true
			);

			// For the wp-admin block editor.
			wp_register_script(
				$this->handle . '-block',
				$resources_url . 'js/block.js',
				[
					'tribe-the-events-calendar-blocks',
					'wp-dom-ready',
					'wp-hooks',
				],
				$this->get_version(),
				true
			);
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
			$this->script_vars['error_class'] = $this->get_error_css_class();

			// Must run $this->get_min_date() before $this->get_max_date()
			$this->script_vars['min_date'] = $this->get_min_date( $post_id );
			$this->script_vars['max_date'] = $this->get_max_date( $post_id );
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
			 * @param int    $post_id   The Post ID.
			 *
			 * @return string
			 */
			$time_zone = (string) apply_filters( 'tribe_ext_start_datepicker_time_zone', $time_zone, $post_id );

			if ( ! in_array( $time_zone, timezone_identifiers_list() ) ) {
				// This will fallback to UTC but may also return a TZ environment variable (e.g. EST), which could cause an error for DateTimeZone().
				$time_zone = date_default_timezone_get();
			}

			return $time_zone;
		}

		/**
		 * Get the PHP DateTimeImmutable object of "today at midnight" (the first second
		 * of today) or midnight of a given timestamp.
		 *
		 * Because the datepicker is separate from the timepicker, we need to
		 * make sure we are using midnight whenever setting the script's minDate
		 * or maxDate.
		 *
		 * @param int      $post_id
		 * @param bool|int $timestamp
		 *
		 * @return DateTimeImmutable
		 */
		private function get_midnight_datetime( $post_id = 0, $timestamp = false ) {
			$tz_string = $this->get_time_zone_string( $post_id );

			try {
				$time_zone = new DateTimeZone( $tz_string );

				$datetime = new DateTimeImmutable( '', $time_zone );

				if ( ! empty( $timestamp ) ) {
					$timestamp = (int) $timestamp;
					if ( 0 !== $timestamp ) {
						$datetime->setTimestamp( $timestamp );
					}
				}

				$datetime->setTime( 0, 0, 0 );
			}
			catch ( Exception $e ) {
				$datetime = null;

				tribe( 'logger' )->log_debug( 'PHP DateTimeZone or DateTimeImmutable did not operate successfully.', $this->handle );
			}

			return $datetime;
		}

		/**
		 * Get the minimum allowed start date timestamp.
		 *
		 * @param int $post_id
		 *
		 * @return int
		 */
		private function get_min_date( $post_id = 0 ) {
			$tz_string = $this->get_time_zone_string( $post_id );

			$existing_start_date = get_post_meta( $post_id, '_EventStartDate', true );

			if ( ! empty( $existing_start_date ) ) {
				$existing_start_date .= ' ' . $tz_string;
				$existing_start_date = strtotime( $existing_start_date );
			}

			$existing_start_date_datetime = $this->get_midnight_datetime( $post_id, $existing_start_date );

			if ( null !== $existing_start_date_datetime ) {
				$existing_start_date = $existing_start_date_datetime->getTimestamp();
			}

			$today_datetime = $this->get_midnight_datetime( $post_id );

			if ( null !== $today_datetime ) {
				// what we want and expect to happen
				$today = $today_datetime->getTimestamp();
			} else {
				// not what we want but let's at least avoid causing errors
				$today = current_time( 'timestamp' );
			}

			if (
				is_int( $existing_start_date )
				&& is_int( $today )
				&& $today !== $existing_start_date
			) {
				$this->timestamp_existing_start_date = $existing_start_date;

				$this->timestamp_max_today_or_existing = max( $existing_start_date, $today );

				$start_date = min( $existing_start_date, $today );
			} else {
				$start_date = $today;
			}

			/**
			 * The time in the past from today to allow as the minimum start
			 * date. Must be in the PHP DateInterval class format.
			 *
			 * Left unused, the earliest allowable start date for new events
			 * will be Today. If you wanted to allow setting a start date up to
			 * 7 days in the past but not further in the past than that, you
			 * would filter this to be 'P7D'. Note this is "days before today",
			 * not "days including today". If an existing event's start date is
			 * already set to further in the past (e.g. 14 days), the minimum
			 * start date will be that instead. This is the same logic behind
			 * setting the maximum start date.
			 *
			 * @link https://secure.php.net/manual/class.dateinterval.php
			 *
			 * @param string   $date_interval This             must be a string acceptable to
			 *                                                 PHP's DateInterval class, including
			 *                                                 the leading 'P'.
			 * @param null|int $existing_event_start_timestamp Timestamp of
			 *                                                 midnight of the start date of an
			 *                                                 existing event. Null if not an existing
			 *                                                 event or if its start date is today.
			 * @param int      $post_id                        The Post ID.
			 *
			 * @return string
			 */
			$interval = (string) apply_filters( 'tribe_ext_start_datepicker_min_date_interval', '', $this->timestamp_existing_start_date, $post_id );

			$interval = strtoupper( $interval ); // e.g. 'P7d' would be a fatal error

			$min_date = $start_date; // minDate must be set but maxDate does not have to be

			if ( ! empty( $interval ) ) {
				$today_datetime->sub( new DateInterval( $interval ) );
				$today_datetime->setTime( 0, 0, 0 );
				$min_date = $today_datetime->getTimestamp();

				if (
					$this->timestamp_max_today_or_existing
					&& $min_date > $this->timestamp_max_today_or_existing
				) {
					$min_date = $this->timestamp_max_today_or_existing;
				}
			}

			return $min_date;
		}

		/**
		 * Get the value to send to the JS maxDate option.
		 *
		 * This will either be an empty string, which our JS can account for, or
		 * a timestamp.
		 * Run through $this->get_min_date() before running this to ensure
		 * $this->timestamp_existing_start_date is set, if applicable.
		 *
		 * @param int $post_id
		 *
		 * @return string|int
		 */
		private function get_max_date( $post_id = 0 ) {
			/**
			 * The time forward from today to allow as the maximum start date.
			 * Must be in the PHP DateInterval class format.
			 *
			 * For example: Useful if you want to restrict the start date to be
			 * no more than 21 days in the future, in which case you would pass
			 * 'P21D'. Note this is "days after today", not "days including
			 * today". If an existing event's start date is already set to
			 * further in the future (e.g. 30 days), the maximum start date will
			 * be that instead. This is the same logic behind setting the
			 * minimum start date.
			 *
			 * @link https://secure.php.net/manual/class.dateinterval.php
			 *
			 * @param string   $date_interval This             must be a string acceptable to
			 *                                                 PHP's DateInterval class, including
			 *                                                 the leading 'P'.
			 * @param null|int $existing_event_start_timestamp Timestamp of
			 *                                                 midnight of the start date of an
			 *                                                 existing event. Null if not an existing
			 *                                                 event or if its start date is today.
			 * @param int      $post_id                        The Post ID.
			 *
			 * @return string
			 */
			$interval = (string) apply_filters(
				'tribe_ext_start_datepicker_max_date_interval',
				'',
				$this->timestamp_existing_start_date,
				$post_id
			);

			$interval = strtoupper( $interval ); // e.g. 'P1m' would be a fatal error

			$max_date = '';

			if ( ! empty( $interval ) ) {
				$today = $this->get_midnight_datetime();

				if ( null !== $today ) {
					$today->add( new DateInterval( $interval ) );
					$today->setTime( 0, 0, 0 );
					$max_date = $today->getTimestamp();

					if (
						$this->timestamp_max_today_or_existing
						&& $max_date < $this->timestamp_max_today_or_existing
					) {
						$max_date = $this->timestamp_max_today_or_existing;
					}
				}
			}

			return $max_date;
		}

		/**
		 * Load this extension's script on the wp-admin event add/edit screen.
		 */
		public function load_for_classic_editor_admin() {
			// bail if not on the wp-admin event add/edit screen
			global $current_screen;
			global $post;

			$load_script = true;

			if (
				current_user_can( $this->get_cap_allowed_any_date() )
				|| ! class_exists( 'Tribe__Admin__Helpers' )
				|| ! Tribe__Admin__Helpers::instance()->is_post_type_screen( Tribe__Events__Main::POSTTYPE )
				|| ! $current_screen instanceof WP_Screen
				|| 'post' !== $current_screen->base // the wp-admin add/edit screen
			) {
				$load_script = false;
			}

			/**
			 * Whether the script should load in wp-admin's Classic Editor.
			 *
			 * Useful to override for specific users or other scenarios. For
			 * example: allow selecting any start date for a specific Post ID.
			 *
			 * @param bool      $load_script    Whether to load the script.
			 * @param WP_Screen $current_screen The wp-admin global $current_screen.
			 * @param WP_Post   $post           The WP_Post object.
			 *
			 * @return bool
			 */
			$load_script = (bool) apply_filters( $this->handle . '-classic-admin-load', $load_script, $current_screen, $post );

			if ( $load_script ) {
				wp_enqueue_script( $this->handle . '-classic' );

				// Pass the PHP to the JS.
				$this->build_script_vars( $post->ID );
				wp_localize_script( $this->handle . '-classic', 'tribe_ext_start_datepicker__vars', $this->script_vars );
			}
		}

		/**
		 * Load this extension's script on the TEC's Block Editor.
		 *
		 * Don't need all the screen-checking logic because of the action we enqueue on plus the script's dependency.
		 */
		public function load_for_block_editor() {
			// bail if not on the wp-admin event add/edit screen
			global $current_screen;
			global $post;

			/**
			 * Whether the script should load in the Block Editor.
			 *
			 * Useful to override for specific users or other scenarios. For
			 * example: allow selecting any start date for a specific Post ID.
			 *
			 * @param bool      $load_script    Whether to load the script.
			 * @param WP_Screen $current_screen The wp-admin global $current_screen.
			 * @param WP_Post   $post           The WP_Post object.
			 *
			 * @return bool
			 */
			$load_script = (bool) apply_filters( $this->handle . '-block-load', true, $current_screen, $post );

			if ( $load_script ) {
				wp_enqueue_script( $this->handle . '-block' );

				// Pass the PHP to the JS.
				$this->build_script_vars( $post->ID );
				wp_localize_script( $this->handle . '-block', 'tribe_ext_start_datepicker__vars', $this->script_vars );
			}
		}

		/**
		 * Load this extension's script on Community Events' event add/edit form.
		 *
		 * Only uses the Classic Editor script, as CE doesn't support the Block Editor.
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
			 * @param int  $post_id     The Post ID.
			 *
			 * @return bool
			 */
			$load_script = (bool) apply_filters( $this->handle . '-community-load', $load_script, $post_id );

			if ( $load_script ) {
				wp_enqueue_script( $this->handle . '-classic' );

				// Pass the PHP to the JS.
				$this->build_script_vars( $post_id );
				wp_localize_script( $this->handle . '-classic', 'tribe_ext_start_datepicker__vars', $this->script_vars );
			}
		}

	} // end class
} // end if class_exists check