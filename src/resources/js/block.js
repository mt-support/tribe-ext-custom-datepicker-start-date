"use strict";

wp.domReady( function() {
	wp.hooks.addFilter(
		'blocks.tribeEventDayPicker.disabledDays',
		'tribe/ext-custom-datepicker/disabledDays',
		tecExtCustomDatepickerDisabledDays,
	);
} );

// http://react-day-picker.js.org/docs/matching-days

/**
 * Set the disabled days, if not already set.
 *
 * @see .../the-events-calendar/src/modules/elements/month/element.js for the component.
 *
 * @param {{}|null|*} disabledDays
 *
 * @returns {{}|null|*}
 */
function tecExtCustomDatepickerDisabledDays( disabledDays ) {
	// Something else must have already filtered (unexpectedly) so let's bail.
	if ( null !== disabledDays ) {
		return disabledDays;
	}

	const minDate = new Date( tribe_ext_start_datepicker__vars.min_date * 1000 );

	// max_date will always be set but may be zero
	// empty string * 1000 is zero
	// non-empty string * 1000 is NaN
	// else we assume it is a PHP timestamp, which needs to convert to milliseconds
	let maxDate = tribe_ext_start_datepicker__vars.max_date * 1000;

	if (
		0 === maxDate ||
		isNaN( maxDate ) ||
		minDate > maxDate
	) {
		maxDate = null;
	} else {
		maxDate = new Date( maxDate );
	}

	if (
		! minDate
		&& ! maxDate
	) {
		return null;
	}

	let newDisabledDays = {};

	if ( minDate ) {
		newDisabledDays.before = minDate;
	}

	if ( maxDate ) {
		newDisabledDays.after = maxDate;
	}

	return newDisabledDays;
}