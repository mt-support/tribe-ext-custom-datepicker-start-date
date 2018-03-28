jQuery( document ).ready( function ( $ ) {
	// JS uses milliseconds; PHP does not

	// min_date will never be empty string (unless filtered to be an error)
	var min_date = new Date( php_vars.min_date * 1000 );

	// max_date will always be set but may be an empty string
	// empty string * 1000 is zero
	// non-empty string * 1000 is NaN
	// else we assume it is a PHP timestamp, which needs to convert to milliseconds
	var max_date = php_vars.max_date * 1000;
	if (
		0 === max_date ||
		isNaN( max_date ) ||
		min_date > max_date
	) {
		max_date = '';
	} else {
		max_date = new Date( max_date );
	}

	/**
	 * Set the minDate and maxDate and watch it for changes in case the user
	 * manually enters a value, in which case we set the value to blank and add
	 * the error class.
	 */
	$( '#EventStartDate' )
		.datepicker( 'option', 'minDate', min_date )
		.datepicker( 'option', 'maxDate', max_date )
		.on( 'change', function () {
			var start_value_date = $( this ).datepicker( 'getDate' );
			if (
				min_date > start_value_date ||
				(
					max_date &&
					max_date < start_value_date
				)
			) {
				$( this ).datepicker( 'setDate', '' );
				$( this ).addClass( php_vars.error_class );
			} else {
				$( this ).removeClass( php_vars.error_class );
			}
		} );
} );