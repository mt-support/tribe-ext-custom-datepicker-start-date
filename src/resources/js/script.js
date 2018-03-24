jQuery( function ( $ ) {
	var min_date = new Date( php_vars.min_date * 1000 );
	$( '#EventStartDate' ).datepicker( 'option', 'minDate', min_date );
} );