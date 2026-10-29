/**
 * QR Code Generator Admin JavaScript
 */

jQuery(document).ready(function($) {
    
    // Color picker initialisieren
    $('.color-picker').each(function() { 
        $(this).wpColorPicker(); 
    });
    
    // Size Slider
    $('#size').on('input', function() { 
        $('#size_display').text($(this).val()); 
    });
    
    // Kontrast-Prüfung
    function checkContrast() {
        var fgColor = $('#fg_color').val();
        var bgColor = $('#bg_color').val();
        var contrast = calculateContrast(fgColor, bgColor);
        if (contrast < 4.5) { 
            $('#contrast_warning').show(); 
        } else { 
            $('#contrast_warning').hide(); 
        }
    }
    
    function calculateContrast(color1, color2) {
        function getLuminance(color) {
            var rgb = hexToRgb(color);
            if (!rgb) return 0;
            var r = rgb.r / 255;
            var g = rgb.g / 255;
            var b = rgb.b / 255;
            r = r <= 0.03928 ? r / 12.92 : Math.pow((r + 0.055) / 1.055, 2.4);
            g = g <= 0.03928 ? g / 12.92 : Math.pow((g + 0.055) / 1.055, 2.4);
            b = b <= 0.03928 ? b / 12.92 : Math.pow((b + 0.055) / 1.055, 2.4);
            return 0.2126 * r + 0.7152 * g + 0.0722 * b;
        }
        
        function hexToRgb(hex) {
            var result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
            return result ? { 
                r: parseInt(result[1], 16), 
                g: parseInt(result[2], 16), 
                b: parseInt(result[3], 16) 
            } : null;
        }
        
        var lum1 = getLuminance(color1);
        var lum2 = getLuminance(color2);
        var brightest = Math.max(lum1, lum2);
        var darkest = Math.min(lum1, lum2);
        return (brightest + 0.05) / (darkest + 0.05);
    }
    
    // Farb-Events
    $('#fg_color, #bg_color').on('change input', function() { 
        setTimeout(checkContrast, 100); 
    });
    
    $('.color-picker').wpColorPicker({ 
        change: function() { 
            setTimeout(checkContrast, 100); 
        } 
    });
    
    setTimeout(checkContrast, 500);
    
    // Preset Buttons
    $('.preset-btn').click(function() {
        if ($(this).prop('disabled')) {
            return;
        }
        
        var type = $(this).data('type');
        var url = $(this).data('url');
        var title = $(this).data('title');
        
        $('#qr_type').val(type).trigger('change');
        
        if (type === 'link') { 
            $('#link_url').val(url || ''); 
        } else if (type === 'event') {
            $('#event_title').val($(this).data('event-title') || '');
            $('#event_location').val($(this).data('event-location') || '');
            $('#event_start').val($(this).data('event-start') || '');
            $('#event_end').val($(this).data('event-end') || '');
            $('#event_description').val($(this).data('event-description') || '');
        } else if (type === 'text') {
            $('#text_content').val($(this).data('text-content') || '');
        } else if (type === 'girocode') {
            $('#giro_name').val($(this).attr('data-giro-name') || '');
            $('#giro_iban').val($(this).attr('data-giro-iban') || '');
            $('#giro_bic').val($(this).attr('data-giro-bic') || '');
            $('#giro_amount').val(($(this).attr('data-giro-amount') || '').replace('.', ','));
            $('#giro_purpose').val($(this).attr('data-giro-purpose') || '');
        }
        
        showSuccessMessage('Preset "' + title + '" wurde geladen!');
    });
    
    // QR Type Wechsel
    $('#qr_type').change(function() {
        var type = $(this).val();
        $('.qr-type-section:not(.qr-type-section:last)').hide();
        clearErrors();
        
        $('#link_url, #text_content, #event_title, #event_start, #giro_name, #giro_iban').removeAttr('required');

        if (type === 'link') {
            $('#link_fields').show();
            $('#link_url').attr('required', true);
        } else if (type === 'text') {
            $('#text_fields').show();
            $('#text_content').attr('required', true);
        } else if (type === 'event') {
            $('#event_fields').show();
            $('#event_title, #event_start').attr('required', true);
        } else if (type === 'girocode') {
            $('#girocode_fields').show();
            $('#giro_name, #giro_iban').attr('required', true);
        }
    });
    
    $('#qr_type').trigger('change');
    
    // Generate Buttons
    $('#generate_qr_png').click(function() { 
        generateQRCode('png'); 
    });
    
    $('#generate_qr_svg').click(function() { 
        generateQRCode('svg'); 
    });
    
    // QR Code Generation
    function generateQRCode(format) {
        if (!validateForm()) return;
        
        $('#loading').show();
        $('#generate_qr_png, #generate_qr_svg').prop('disabled', true);
        clearMessages();
        
        var formData = $('#qr-form').serialize();
        formData += '&format=' + format;
        formData += '&action=generate_qr_code&nonce=' + rmdQrData.nonce;
        
        $.ajax({
            url: rmdQrData.ajaxurl,
            type: 'POST',
            data: formData,
            timeout: 30000,
            success: function(response) {
                $('#loading').hide();
                $('#generate_qr_png, #generate_qr_svg').prop('disabled', false);
                
                if (response.success) {
                    $('#qr_preview').html(response.data.html);
                    $('#download_qr').data('filename', response.data.filename);
                    $('#qr_download').show();
                    showSuccessMessage('QR-Code erfolgreich generiert!');
                } else {
                    showError('Fehler: ' + response.data);
                }
            },
            error: function(xhr, status, error) {
                $('#loading').hide();
                $('#generate_qr_png, #generate_qr_svg').prop('disabled', false);
                showError('Verbindungsfehler: ' + error);
            }
        });
    }
    
    // Download Button
    $('#download_qr').click(function() {
        var filename = $(this).data('filename');
        if (filename) {
            window.location.href = rmdQrData.downloadUrl + '?page=rmd-qr-code-generator&download=' + filename + '&nonce=' + rmdQrData.downloadNonce;
        }
    });
    
    // Form Validation
    function validateForm() {
        clearErrors();
        var isValid = true;
        var type = $('#qr_type').val();
        
        if (!type) { 
            showError('Bitte wählen Sie einen QR-Code Typ aus.'); 
            isValid = false; 
        }
        
        if (type === 'link') {
            var url = $('#link_url').val();
            if (!url) { 
                showError('Bitte geben Sie eine URL ein.'); 
                isValid = false; 
            } else if (!validateUrl(url)) { 
                isValid = false; 
            }
        }
        
        if (type === 'text') {
            var text = $('#text_content').val();
            if (!text.trim()) { 
                showError('Bitte geben Sie einen Text ein.'); 
                isValid = false; 
            }
        }
        
        if (type === 'event') {
            var title = $('#event_title').val();
            var start = $('#event_start').val();
            if (!title.trim()) { 
                showError('Bitte geben Sie einen Event-Titel ein.'); 
                isValid = false; 
            }
            if (!start) {
                showError('Bitte geben Sie ein Startdatum ein.');
                isValid = false;
            }
        }

        if (type === 'girocode') {
            var name = $('#giro_name').val();
            var iban = $('#giro_iban').val().replace(/[^A-Za-z0-9]/g, '').toUpperCase();
            var bic = $('#giro_bic').val().replace(/[^A-Za-z0-9]/g, '').toUpperCase();
            var amount = $('#giro_amount').val().trim();
            if (!name.trim()) {
                showError('Bitte geben Sie einen Empfänger ein.');
                isValid = false;
            }
            if (!validateIban(iban)) {
                showError('Bitte geben Sie eine gültige IBAN ein.');
                isValid = false;
            }
            if (bic && !/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/.test(bic)) {
                showError('Bitte geben Sie eine gültige BIC ein oder lassen Sie das Feld leer.');
                isValid = false;
            }
            if (amount && !/^\d{1,9}([.,]\d{1,2})?$/.test(amount.replace(/\.(?=\d{3})/g, ''))) {
                showError('Bitte geben Sie einen gültigen Betrag ein, z. B. 25,00.');
                isValid = false;
            }
        }

        return isValid;
    }

    function validateIban(iban) {
        if (!/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/.test(iban)) {
            return false;
        }
        var rearranged = iban.slice(4) + iban.slice(0, 4);
        var remainder = 0;
        for (var i = 0; i < rearranged.length; i++) {
            var code = rearranged.charCodeAt(i);
            var digits = code >= 65 ? String(code - 55) : rearranged[i];
            for (var j = 0; j < digits.length; j++) {
                remainder = (remainder * 10 + parseInt(digits[j], 10)) % 97;
            }
        }
        return remainder === 1;
    }
    
    function validateUrl(url) {
        var pattern = /^https?:\/\/.+/i;
        if (!pattern.test(url)) { 
            showError('Bitte geben Sie eine gültige URL ein.'); 
            return false; 
        }
        return true;
    }
    
    // Message Functions
    function showError(message) { 
        $('#error_messages').append('<div class="error-message">' + message + '</div>'); 
    }
    
    function showSuccessMessage(message) { 
        $('#error_messages').append('<div class="success-message">' + message + '</div>'); 
        setTimeout(function() { 
            $('.success-message').fadeOut(); 
        }, 3000); 
    }
    
    function clearErrors() { 
        $('#error_messages').empty(); 
    }
    
    function clearMessages() { 
        $('#error_messages').empty(); 
    }
    
});