jQuery(document).ready(function ($) {
    var start_date_input = $('#EventStartDate');
    var min_date = new Date(php_vars.min_date * 1000); // JS uses milliseconds; PHP does not

    /**
     * Set the minDate and watch it for changes in case user manually enters a
     * value, in which case we set the value to blank and add the error class.
     */
    start_date_input.datepicker('option', 'minDate', min_date).on('change', function () {
        var start_value_date = $(this).datepicker('getDate');
        if (min_date > start_value_date) {
            $(this).datepicker('setDate', '');
            $(this).addClass(php_vars.error_class);
        } else {
            $(this).removeClass(php_vars.error_class);
        }
    });
});