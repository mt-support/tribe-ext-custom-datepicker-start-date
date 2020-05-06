wp.domReady( function() {
	wp.hooks.addFilter(
		'blocks.eventDatetime.monthModifiersHook',
		'tribe/tec/monthModifiers',
		tecExtCustomDatepickerModifiers,
	);
} );

// http://react-day-picker.js.org/docs/matching-days
function tecExtCustomDatepickerModifiers( _, props ) {
	console.log( 'tecExtCustomDatepickerModifiers' );
	console.log( _ );
	console.log( props );

	const min_date = new Date( tribe_ext_start_datepicker__vars.min_date * 1000 );

	// max_date will always be set but may be zero
	// empty string * 1000 is zero
	// non-empty string * 1000 is NaN
	// else we assume it is a PHP timestamp, which needs to convert to milliseconds
	let max_date = tribe_ext_start_datepicker__vars.max_date * 1000;

	if (
		0 === max_date ||
		isNaN( max_date ) ||
		min_date > max_date
	) {
		max_date = '';
	} else {
		max_date = new Date( max_date );
	}

	const newProps = {
		before: min_date,
		after: max_date,
	};

	return { ...props, ...newProps };
};