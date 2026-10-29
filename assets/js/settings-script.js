/**
 * QR Code Generator Settings JavaScript
 */

jQuery(document).ready(function($) {

    var $container = $('#rmd-qr-presets');
    var template = $('#tmpl-rmd-qr-preset').html();

    function updateFields($row) {
        var type = $row.find('.rmd-qr-preset-type').val();
        $row.attr('data-type', type);
        $row.find('.rmd-qr-field-link').toggle(type === 'link');
        $row.find('.rmd-qr-field-text').toggle(type === 'text');
        $row.find('.rmd-qr-field-event').toggle(type === 'event');
        $row.find('.rmd-qr-field-girocode').toggle(type === 'girocode');
    }

    $container.find('.rmd-qr-preset-row').each(function() {
        updateFields($(this));
    });

    $container.on('change', '.rmd-qr-preset-type', function() {
        updateFields($(this).closest('.rmd-qr-preset-row'));
    });

    $container.on('click', '.rmd-qr-remove-preset', function() {
        $(this).closest('.rmd-qr-preset-row').remove();
    });

    // Eindeutiger Index für neue Zeilen; die Reihenfolge wird beim Speichern neu durchnummeriert
    $('#rmd-qr-add-preset').on('click', function() {
        var index = 'new' + Date.now();
        var $row = $(template.replace(/__INDEX__/g, index));
        $container.append($row);
        updateFields($row);
        $row.find('input[type="text"]').first().trigger('focus');
    });

});
