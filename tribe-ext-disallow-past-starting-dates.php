<?php
/**
 * Plugin Name:     The Events Calendar Extension: Disallow Past Starting Dates
 * Description:     Disallow selecting a past starting date for an event's start date datepicker in the wp-admin event edit screen and the Community Events form (if installed).
 * Version:         1.0.0
 * Extension Class: Tribe__Extension__Disallow_Past_Start_Dates
 * Author:          Modern Tribe, Inc.
 * Author URI:      http://m.tri.be/1971
 * License:         GPL version 3 or any later version
 * License URI:     https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:     tribe-ext-disallow-past-starting-dates
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

// Do not load unless Tribe Common is fully loaded and our class does not yet exist.
if (
	class_exists( 'Tribe__Extension' )
	&& ! class_exists( 'Tribe__Extension__Disallow_Past_Start_Dates' )
) {
	/**
	 * Extension main class, class begins loading on init() function.
	 */
	class Tribe__Extension__Disallow_Past_Start_Dates extends Tribe__Extension {
		private $handle = 'tribe-ext-disallow-past-starting-dates';

		private $script_vars = array();

		private function get_handle_underscores() {
			return str_replace( '-', '_', $this->handle );
		}

		private function get_cap_allowed_any_date() {
			/**
			 * The capability required to have this script not load.
			 *
			 * @param string $capability The minimum capability to be allowed to
			 *                           choose a start date in the past.
			 *
			 * @return string
			 */
			return (string) apply_filters( $this->get_handle_underscores() . '_cap_allowed_past_dates', 'xmanage_options' );

		}

		/**
		 * Set the minimum required version of The Events Calendar
		 * and this extension's URL.
		 */
		public function construct() {
			$this->add_required_plugin( 'Tribe__Events__Main' );
			//$this->set_url( 'https://theeventscalendar.com/extensions/TBD/' );
		}

		/**
		 * Extension initialization and hooks.
		 */
		public function init() {
			// Load plugin textdomain
			load_plugin_textdomain( $this->handle, false, basename( dirname( __FILE__ ) ) . '/languages/' );

			add_action( 'init', array( $this, 'register_assets' ) );

			add_action( 'admin_enqueue_scripts', array( $this, 'load_assets_for_event_admin_edit_screen' ) );

			// This action hook exists as of Community Events version 4.4
			add_action( 'tribe_community_events_enqueue_resources', array( $this, 'load_assets_for_ce_form' ) );

			// Tribe__Events__Main::addEventMeta() is hooked on 'save_post' at priority 15
			add_action( 'save_post_' . Tribe__Events__Main::POSTTYPE, array( $this, 'protect_against_manually_entered_past_dates' ), 50, 2 );

		}


		/**
		 * Register this view's assets.
		 */
		public function register_assets() {
			$resources_url = trailingslashit( plugin_dir_url( __FILE__ ) ) . 'src/resources/';

			$js = $resources_url . 'js/script.js';

			// `tribe-events-admin` dependency so the `tribe_datepicker_opts` JS variable is set by the time we need to extend it
			// which comes from /wp-content/plugins/the-events-calendar/src/resources/js/events-admin.js
			wp_register_script(
				$this->handle,
				$js,
				array(
					'jquery',
					'tribe-events-admin'
				),
				$this->get_version(),
				true
			);
		}

		private function min_allowed_start_date_to_script_var( $post_id = 0 ) {
			$start_date = $this->get_min_allowed_start_date( $post_id );
			$this->script_vars['min_date'] = $start_date;
		}

		private function get_min_allowed_start_date( $post_id = 0 ) {
			$existing_start_date = get_post_meta( $post_id, '_EventStartDate', true );

			$start_date = (int) current_time( 'timestamp' );

			if ( ! empty( $existing_start_date ) ) {
				$event_start_date = (int) Tribe__Events__Timezones::event_start_timestamp( $post_id );
				$start_date       = min( $event_start_date, $start_date );
			}

			/**
			 * Override the minimum start date.
			 *
			 * Example use case: you want to allow choosing start dates up to 1
			 * week in the past.
			 *
			 * @param string $start_date          The minimum allowable date
			 *                                    in timestamp format.
			 * @param int    $post_id             The Post ID.
			 * @param string $existing_start_date The event's existing start date.
			 *
			 * @return bool
			 */
			$start_date = (string) apply_filters( $this->get_handle_underscores() . '_min_start_date', $start_date, $post_id, $existing_start_date );

			return $start_date;
		}

		public function load_assets_for_event_admin_edit_screen() {
			// bail if not on the wp-admin Event edit screen
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
			 * example: allow selecting a past start date for a specific Post ID.
			 *
			 * @param bool      $load_script    Whether or not to load the script.
			 * @param WP_Screen $current_screen The wp-admin global $current_screen.
			 * @param WP_Post   $post           The WP_Post object.
			 *
			 * @return bool
			 */
			$load_script = (bool) apply_filters( $this->get_handle_underscores() . '_load_script_wp_admin', $load_script, $current_screen, $post );

			if ( $load_script ) {
				wp_enqueue_script( $this->handle );

				// Pass the start date to the JS script.
				$this->min_allowed_start_date_to_script_var( $post->ID );
				wp_localize_script( $this->handle, 'php_vars', $this->script_vars );
			}
		}

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
			 * event edit form.
			 *
			 * Useful to override for specific users or other scenarios. For
			 * example: allow selecting a past start date for a specific Post ID.
			 *
			 * @param bool $load_script Whether or not to load the script.
			 * @param int  $post_id     The Post ID.
			 *
			 * @return bool
			 */
			$load_script = (bool) apply_filters( $this->get_handle_underscores() . '_load_script_ce_form', $load_script, $post_id );

			if ( $load_script ) {
				wp_enqueue_script( $this->handle );

				// Pass the start date to the JS script.
				$this->min_allowed_start_date_to_script_var( $post_id );
				wp_localize_script( $this->handle, 'php_vars', $this->script_vars );
			}
		}

		public function protect_against_manually_entered_past_dates( $post_id, $post ) {
			$chosen_start_date = $_POST['EventStartDate'];
			if (
				empty( $chosen_start_date )
				|| ! is_string( $chosen_start_date )
			) {
				return;
			}

			$datepicker_format = Tribe__Date_Utils::datepicker_formats( tribe_get_option( 'datepickerFormat' ) );
			$timezone = Tribe__Events__Timezones::get_event_timezone_string( $post_id );

			// PHP does not support as many timezones as TEC/WP
			if ( ! in_array( $timezone, timezone_identifiers_list() ) ) {
				$timezone = '';
			}

			$chosen_datetime = DateTime::createFromFormat($datepicker_format, $chosen_start_date);

			if ( $timezone ) {
				$datetime_timezone = new DateTimeZone($timezone );
				$chosen_datetime->setTimezone( $datetime_timezone );
			}

			$chosen_datetime->add(new DateInterval('PT23H59S'));

			$chosen_timestamp = $chosen_datetime->getTimestamp();

			if ( $this->get_min_allowed_start_date( $post_id ) > $chosen_timestamp ) {
				$fixed_start_date = date( $datepicker_format );

				// Unhook to avoid infinite loop
				remove_action( 'save_post_' . Tribe__Events__Main::POSTTYPE, array( $this, 'protect_against_manually_entered_past_dates' ), 50, 2 );

				// Do what we need to do
				// Tribe__Events__API::things...

				// The re-add the hook
				add_action( 'save_post_' . Tribe__Events__Main::POSTTYPE, array( $this, 'protect_against_manually_entered_past_dates' ), 50, 2 );


			}

			// watch out for end time then being BEFORE start time

			$x = 1;
		}

	} // end class
} // end if class_exists check
