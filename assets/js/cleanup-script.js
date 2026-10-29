/**
 * QR Code Generator Cleanup JavaScript
 */

jQuery(document).ready(function($) {
    
    // Alle auswählen
    $('#select-all').click(function() { 
        $('input[name="selected_files[]"]').prop('checked', true); 
        $('#cb-select-all').prop('checked', true); 
    });
    
    // Alle abwählen
    $('#select-none').click(function() { 
        $('input[name="selected_files[]"]').prop('checked', false); 
        $('#cb-select-all').prop('checked', false); 
    });
    
    // Master Checkbox
    $('#cb-select-all').change(function() { 
        $('input[name="selected_files[]"]').prop('checked', this.checked); 
    });
    
    // Individual Checkboxes
    $('input[name="selected_files[]"]').change(function() { 
        var total = $('input[name="selected_files[]"]').length; 
        var checked = $('input[name="selected_files[]"]:checked').length; 
        $('#cb-select-all').prop('checked', total === checked); 
    });
    
});