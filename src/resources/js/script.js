jQuery( document ).ready( function ( $ ) {
	var start_date_input = $( '#EventStartDate' );
	var min_date = new Date( php_vars.min_date * 1000 ); // JS uses milliseconds; PHP does not

	/**
	 * Set the maxDate. Note that we do not do anything "fancy" with it, like
	 * convert to milliseconds or to a JS Date() object because it's straight
	 * from the PHP filter hook and typically should be a dynamic date.
	 */
	start_date_input.datepicker( 'option', 'maxDate', php_vars.max_date );

	/**
	 * Set the minDate and watch it for changes in case user manually enters a
	 * value, in which case we set the value to blank and add the error class.
	 */
	start_date_input.datepicker( 'option', 'minDate', min_date ).on( 'change', function () {
		var start_value_date = $( this ).datepicker( 'getDate' );
		if ( min_date > start_value_date ) {
			$( this ).datepicker( 'setDate', '' );
			$( this ).addClass( php_vars.error_class );
		} else {
			$( this ).removeClass( php_vars.error_class );
		}
	} );
} );