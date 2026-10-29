<?php
/**
 * Plugin Name: QR Code Generator
 * Description: WordPress-Plugin zur Generierung von QR-Codes (Link, Text, Termin, Girocode) mit Presets und Rollensteuerung
 * Version: 1.5.0
 * Author: Philipp von reichelt media.design
 * Author URI: https://reicheltmedia.design
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin-Konstanten definieren
define('RMD_QR_VERSION', '1.5.0');
define('RMD_QR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('RMD_QR_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('RMD_QR_OPTION', 'rmd_qr_settings');

class RMDQRCodeGenerator {

    private $presets = null;

    private $preset_types = array('link', 'text', 'event', 'girocode');

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        add_action('wp_ajax_generate_qr_code', array($this, 'generate_qr_code_ajax'));
        add_action('wp_ajax_delete_qr_files', array($this, 'delete_qr_files_ajax'));
        add_action('init', array($this, 'download_qr_code'));
        add_action('admin_init', array($this, 'create_upload_directory'));
        add_action('admin_notices', array($this, 'check_requirements'));
        add_action('init', array($this, 'handle_cleanup_actions'));
    }
    
    /**
     * Überprüft, ob der aktuelle Benutzer berechtigt ist, das Plugin zu verwenden
     * @return bool
     */
    private function user_can_use_qr_generator() {
        // Administratoren haben immer Zugriff
        if (current_user_can('manage_options')) {
            return true;
        }

        $user = wp_get_current_user();
        if (!$user->exists()) {
            return false;
        }

        $settings = $this->get_settings();
        $allowed_roles = apply_filters('rmd_qr_allowed_roles', $settings['roles']);

        return count(array_intersect((array) $allowed_roles, (array) $user->roles)) > 0;
    }

    private function get_settings() {
        $settings = get_option(RMD_QR_OPTION, array());
        if (!is_array($settings)) {
            $settings = array();
        }

        return array(
            'roles' => isset($settings['roles']) && is_array($settings['roles']) ? $settings['roles'] : array(),
            'presets' => isset($settings['presets']) && is_array($settings['presets']) ? $settings['presets'] : array()
        );
    }

    /**
     * Wandelt gespeicherte Presets in das Format für die Preset-Buttons um und löst Shortcodes auf.
     * Erst bei Bedarf geladen, damit Theme-Filter und Shortcodes anderer Plugins registriert sind.
     * Über den Filter "rmd_qr_presets" lassen sich Presets per Code ergänzen.
     */
    private function get_presets() {
        if ($this->presets !== null) {
            return $this->presets;
        }

        $settings = $this->get_settings();
        $presets = array();

        foreach ($settings['presets'] as $preset) {
            $data = array(
                'type' => $preset['type'],
                'title' => $preset['label']
            );

            if ($preset['type'] === 'link') {
                $data['url'] = esc_url_raw($this->expand_shortcodes($preset['url']));
            } elseif ($preset['type'] === 'text') {
                $data['text-content'] = $this->expand_shortcodes($preset['text']);
            } elseif ($preset['type'] === 'girocode') {
                $data['giro-name'] = $this->expand_shortcodes($preset['giro_name']);
                $data['giro-iban'] = $this->normalize_iban($this->expand_shortcodes($preset['giro_iban']));
                $data['giro-bic'] = $this->normalize_bic($this->expand_shortcodes($preset['giro_bic']));
                $data['giro-amount'] = $this->normalize_amount($this->expand_shortcodes($preset['giro_amount']));
                $data['giro-purpose'] = $this->expand_shortcodes($preset['giro_purpose']);
            } else {
                $data['event-title'] = $this->expand_shortcodes($preset['event_title']);
                $data['event-location'] = $this->expand_shortcodes($preset['event_location']);
                $data['event-start'] = $preset['event_start'];
                $data['event-end'] = $preset['event_end'];
                $data['event-description'] = $this->expand_shortcodes($preset['event_description']);
            }

            $presets[] = array(
                'type' => $preset['type'],
                'label' => $preset['label'],
                'disabled' => empty($preset['active']),
                'data' => $data
            );
        }

        $this->presets = apply_filters('rmd_qr_presets', $presets);
        return $this->presets;
    }

    private function contains_shortcode($value) {
        return (bool) preg_match('/\[[A-Za-z0-9_-]+[^\]]*\]/', (string) $value);
    }

    /**
     * Löst Shortcodes (z. B. [cgv iban]) auf und liefert reinen Text.
     */
    private function expand_shortcodes($value) {
        if (!$this->contains_shortcode($value)) {
            return $value;
        }
        $expanded = wp_strip_all_tags(do_shortcode($value));
        return trim(html_entity_decode($expanded, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * Werte mit Shortcode werden unverändert gespeichert und erst bei der Anzeige aufgelöst und geprüft.
     */
    private function sanitize_preset_field($preset, $key, $callback, $multiline = false) {
        if (!isset($preset[$key])) {
            return '';
        }
        $value = $multiline ? sanitize_textarea_field($preset[$key]) : sanitize_text_field($preset[$key]);
        if ($this->contains_shortcode($value)) {
            return $value;
        }
        return call_user_func($callback, $value);
    }

    public function register_settings() {
        register_setting('rmd_qr_settings_group', RMD_QR_OPTION, array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_settings'),
            'default' => array('roles' => array(), 'presets' => array())
        ));
    }

    public function sanitize_settings($input) {
        $output = array('roles' => array(), 'presets' => array());
        if (!is_array($input)) {
            return $output;
        }

        $valid_roles = array_keys(wp_roles()->get_names());
        if (!empty($input['roles']) && is_array($input['roles'])) {
            foreach ($input['roles'] as $role) {
                $role = sanitize_key($role);
                if (in_array($role, $valid_roles, true)) {
                    $output['roles'][] = $role;
                }
            }
        }

        if (!empty($input['presets']) && is_array($input['presets'])) {
            foreach ($input['presets'] as $preset) {
                if (!is_array($preset)) {
                    continue;
                }

                $type = isset($preset['type']) ? sanitize_key($preset['type']) : '';
                $label = isset($preset['label']) ? sanitize_text_field($preset['label']) : '';
                if (!in_array($type, $this->preset_types, true) || $label === '') {
                    continue;
                }

                $clean = array(
                    'type' => $type,
                    'label' => $label,
                    'active' => !empty($preset['active']),
                    'url' => $this->sanitize_preset_field($preset, 'url', 'esc_url_raw'),
                    'text' => $this->sanitize_preset_field($preset, 'text', function ($v) { return substr($v, 0, 500); }, true),
                    'event_title' => $this->sanitize_preset_field($preset, 'event_title', 'strval'),
                    'event_location' => $this->sanitize_preset_field($preset, 'event_location', 'strval'),
                    'event_start' => isset($preset['event_start']) ? $this->sanitize_datetime_local($preset['event_start']) : '',
                    'event_end' => isset($preset['event_end']) ? $this->sanitize_datetime_local($preset['event_end']) : '',
                    'event_description' => $this->sanitize_preset_field($preset, 'event_description', 'strval', true),
                    'giro_name' => $this->sanitize_preset_field($preset, 'giro_name', function ($v) { return substr($v, 0, 70); }),
                    'giro_iban' => $this->sanitize_preset_field($preset, 'giro_iban', array($this, 'normalize_iban')),
                    'giro_bic' => $this->sanitize_preset_field($preset, 'giro_bic', array($this, 'normalize_bic')),
                    'giro_amount' => $this->sanitize_preset_field($preset, 'giro_amount', array($this, 'normalize_amount')),
                    'giro_purpose' => $this->sanitize_preset_field($preset, 'giro_purpose', function ($v) { return substr($v, 0, 140); })
                );

                if ($type === 'girocode' && $clean['giro_iban'] !== '' && !$this->contains_shortcode($clean['giro_iban']) && !$this->is_valid_iban($clean['giro_iban'])) {
                    add_settings_error(RMD_QR_OPTION, 'invalid_iban', sprintf('Preset „%s“: Die IBAN ist ungültig.', $label));
                }

                $output['presets'][] = $clean;
            }
        }

        return $output;
    }

    private function sanitize_datetime_local($value) {
        $value = sanitize_text_field($value);
        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) ? $value : '';
    }

    private function normalize_iban($value) {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value));
    }

    private function normalize_bic($value) {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value));
    }

    /**
     * Akzeptiert "12,50", "12.50" oder "1.234,50" und liefert "12.50" bzw. "" bei leer/ungültig.
     */
    private function normalize_amount($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if (strpos($value, ',') !== false || preg_match('/^\d{1,3}(\.\d{3})+$/', $value)) {
            $value = str_replace(array('.', ','), array('', '.'), $value);
        }
        if (!is_numeric($value)) {
            return '';
        }
        return number_format((float) $value, 2, '.', '');
    }

    private function is_valid_iban($iban) {
        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban)) {
            return false;
        }

        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        // Mod 97 stückweise, da die Zahl für Integer zu groß ist
        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) ($remainder . $chunk) % 97;
        }

        return $remainder === 1;
    }

    private function is_valid_bic($bic) {
        return (bool) preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic);
    }
    
    private function get_upload_dir() {
        return wp_upload_dir();
    }
    
    public function create_upload_directory() {
        $upload_dir = wp_upload_dir();
        if (!file_exists($upload_dir['path'])) {
            wp_mkdir_p($upload_dir['path']);
        }
    }
    
    public function check_requirements() {
        // Nur für berechtigte Benutzer anzeigen
        if (!$this->user_can_use_qr_generator()) {
            return;
        }
        
        $test_response = wp_remote_get('https://api.qrserver.com/v1/create-qr-code/?data=test&size=50x50', array('timeout' => 5));
        if (is_wp_error($test_response)) {
            echo '<div class="notice notice-warning"><p><strong>QR-Code-Generator:</strong> Warnung - Keine Verbindung zur QR-Code API.</p></div>';
        }
    }
    
    /**
     * Mini-QR-Code als Menü-Icon; WordPress färbt das SVG passend zum Admin-Farbschema ein.
     */
    private function get_menu_icon() {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" fill-rule="evenodd" d="'
            . 'M1 1h7v7H1zM2 2v5h5V2zM3 3h3v3H3z'
            . 'M12 1h7v7h-7zM13 2v5h5V2zM14 3h3v3h-3z'
            . 'M1 12h7v7H1zM2 13v5h5v-5zM3 14h3v3H3z'
            . 'M9 1h2v2H9zM9 5h2v3H9zM1 9h3v2H1zM6 9h2v2H6zM9 9h2v2H9zM13 9h2v2h-2zM17 9h2v2h-2z'
            . 'M9 13h2v2H9zM9 17h2v2H9zM12 12h2v2h-2zM17 12h2v2h-2zM14 14h3v2h-3zM12 16h2v3h-2zM16 17h3v2h-3z'
            . '"/></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    public function add_admin_menu() {
        // Nur für berechtigte Benutzer das Menü hinzufügen
        if (!$this->user_can_use_qr_generator()) {
            return;
        }
        
        add_menu_page(
            'QR-Code-Generator',
            'QR Generator', 
            'read', // Minimale Berechtigung - weitere Prüfung erfolgt in user_can_use_qr_generator()
            'rmd-qr-code-generator',
            array($this, 'admin_page'),
            $this->get_menu_icon(),
            30
        );
        
        add_submenu_page(
            'rmd-qr-code-generator',
            'QR-Code erstellen',
            'Erstellen',
            'read',
            'rmd-qr-code-generator',
            array($this, 'admin_page')
        );
        
        add_submenu_page(
            'rmd-qr-code-generator',
            'QR-Codes aufräumen',
            'Aufräumen',
            'read',
            'rmd-qr-cleanup',
            array($this, 'cleanup_page')
        );

        add_submenu_page(
            'rmd-qr-code-generator',
            'QR-Code-Generator Einstellungen',
            'Einstellungen',
            'manage_options',
            'rmd-qr-settings',
            array($this, 'settings_page')
        );
    }

    public function admin_scripts($hook) {
        if ($hook === 'qr-generator_page_rmd-qr-settings') {
            wp_enqueue_style('rmd-qr-admin-styles', RMD_QR_PLUGIN_URL . 'assets/css/admin-styles.css', array(), RMD_QR_VERSION);
            wp_enqueue_script('rmd-qr-settings-script', RMD_QR_PLUGIN_URL . 'assets/js/settings-script.js', array('jquery'), RMD_QR_VERSION, true);
            return;
        }

        if ($hook !== 'toplevel_page_rmd-qr-code-generator' && $hook !== 'qr-generator_page_rmd-qr-cleanup') {
            return;
        }

        // Zusätzliche Berechtigung prüfen
        if (!$this->user_can_use_qr_generator()) {
            return;
        }

        // CSS einbinden
        wp_enqueue_style('rmd-qr-admin-styles', RMD_QR_PLUGIN_URL . 'assets/css/admin-styles.css', array(), RMD_QR_VERSION);
        wp_enqueue_style('rmd-qr-cleanup-styles', RMD_QR_PLUGIN_URL . 'assets/css/cleanup-styles.css', array(), RMD_QR_VERSION);

        // WordPress Standard-Skripte
        wp_enqueue_script('jquery');
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');

        // Eigene JavaScript-Dateien
        wp_enqueue_script('rmd-qr-admin-script', RMD_QR_PLUGIN_URL . 'assets/js/admin-script.js', array('jquery', 'wp-color-picker'), RMD_QR_VERSION, true);
        wp_enqueue_script('rmd-qr-cleanup-script', RMD_QR_PLUGIN_URL . 'assets/js/cleanup-script.js', array('jquery'), RMD_QR_VERSION, true);
        
        // Daten für JavaScript bereitstellen
        wp_localize_script('rmd-qr-admin-script', 'rmdQrData', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('qr_nonce'),
            'downloadUrl' => admin_url('admin.php'),
            'downloadNonce' => wp_create_nonce('download_nonce'),
            'presets' => $this->get_presets()
        ));
    }
    
    public function handle_cleanup_actions() {
        // Berechtigung prüfen
        if (!$this->user_can_use_qr_generator()) {
            return;
        }
        
        if (isset($_POST['cleanup_action']) && wp_verify_nonce($_POST['cleanup_nonce'], 'cleanup_qr_files')) {
            if ($_POST['cleanup_action'] === 'delete_selected' && !empty($_POST['selected_files'])) {
                $this->delete_selected_files($_POST['selected_files']);
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-success"><p>Ausgewählte Dateien wurden gelöscht.</p></div>';
                });
            } elseif ($_POST['cleanup_action'] === 'delete_month') {
                $month = sanitize_text_field($_POST['selected_month']);
                $deleted_count = $this->delete_month_files($month);
                add_action('admin_notices', function() use ($deleted_count, $month) {
                    echo '<div class="notice notice-success"><p>' . $deleted_count . ' Dateien aus ' . $month . ' wurden gelöscht.</p></div>';
                });
            }
        }
    }
    
    public function admin_page() {
        // Berechtigung prüfen
        if (!$this->user_can_use_qr_generator()) {
            wp_die(__('Sie haben nicht die erforderlichen Berechtigungen für diese Seite.'));
        }
        
        echo '<div class="wrap">';
        echo '<h1>QR-Code erstellen</h1>';
        echo '<div class="qr-generator-container">';
        
        // Linke Spalte
        echo '<div class="qr-settings-column">';
        echo '<form id="qr-form" method="post">';
        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row"><label for="qr_type">QR-Code Typ *</label></th>';
        echo '<td>';
        echo '<select name="qr_type" id="qr_type" required>';
        echo '<option value="link" selected>Link/URL</option>';
        echo '<option value="text">Text</option>';
        echo '<option value="event">Event/Termin</option>';
        echo '<option value="girocode">Girocode (SEPA-Überweisung)</option>';
        echo '</select>';
        echo '<p class="description">Wählen Sie den gewünschten QR-Code Typ aus.</p>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';
        
        // Link Felder
        echo '<div id="link_fields" class="qr-type-section">';
        echo '<h3>QR-Code-Daten</h3>';
        echo '<div class="qr-section-content">';
        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row"><label for="link_url">URL *</label></th>';
        echo '<td>';
        echo '<input type="url" name="link_url" id="link_url" class="regular-text" placeholder="https://example.com">';
        echo '<p class="description">Geben Sie eine vollständige URL ein.</p>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';
        echo '</div>';
        echo '</div>';
        
        // Text Felder
        echo '<div id="text_fields" class="qr-type-section" style="display:none;">';
        echo '<h3>QR-Code-Daten</h3>';
        echo '<div class="qr-section-content">';
        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row"><label for="text_content">Text *</label></th>';
        echo '<td>';
        echo '<textarea name="text_content" id="text_content" rows="4" cols="50" placeholder="Ihr Text hier..." maxlength="500"></textarea>';
        echo '<p class="description">Maximale Länge: 500 Zeichen.</p>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';
        echo '</div>';
        echo '</div>';
        
        // Event Felder
        echo '<div id="event_fields" class="qr-type-section" style="display:none;">';
        echo '<h3>QR-Code-Daten</h3>';
        echo '<div class="qr-section-content">';
        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row"><label for="event_title">Event Titel *</label></th>';
        echo '<td>';
        echo '<input type="text" name="event_title" id="event_title" class="regular-text" maxlength="100">';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="event_location">Ort</label></th>';
        echo '<td>';
        echo '<input type="text" name="event_location" id="event_location" class="regular-text" maxlength="100">';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="event_start">Startdatum *</label></th>';
        echo '<td>';
        echo '<input type="datetime-local" name="event_start" id="event_start" required>';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="event_end">Enddatum</label></th>';
        echo '<td>';
        echo '<input type="datetime-local" name="event_end" id="event_end">';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="event_description">Beschreibung</label></th>';
        echo '<td>';
        echo '<textarea name="event_description" id="event_description" rows="3" cols="50" maxlength="200"></textarea>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';
        echo '</div>';
        echo '</div>';

        // Girocode Felder
        echo '<div id="girocode_fields" class="qr-type-section" style="display:none;">';
        echo '<h3>QR-Code-Daten</h3>';
        echo '<div class="qr-section-content">';
        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row"><label for="giro_name">Empfänger *</label></th>';
        echo '<td>';
        echo '<input type="text" name="giro_name" id="giro_name" class="regular-text" maxlength="70">';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="giro_iban">IBAN *</label></th>';
        echo '<td>';
        echo '<input type="text" name="giro_iban" id="giro_iban" class="regular-text" maxlength="42" placeholder="DE00 0000 0000 0000 0000 00">';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="giro_bic">BIC</label></th>';
        echo '<td>';
        echo '<input type="text" name="giro_bic" id="giro_bic" class="regular-text" maxlength="11">';
        echo '<p class="description">Optional innerhalb des EWR.</p>';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="giro_amount">Betrag (EUR)</label></th>';
        echo '<td>';
        echo '<input type="text" name="giro_amount" id="giro_amount" class="small-text" inputmode="decimal" placeholder="0,00">';
        echo '<p class="description">Optional. Leer lassen, damit der Betrag in der Banking-App eingegeben wird.</p>';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="giro_purpose">Verwendungszweck</label></th>';
        echo '<td>';
        echo '<input type="text" name="giro_purpose" id="giro_purpose" class="regular-text" maxlength="140">';
        echo '<p class="description">Maximale Länge: 140 Zeichen.</p>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';
        echo '</div>';
        echo '</div>';

        // Gestaltung
        echo '<div class="qr-type-section">';
        echo '<h3>Gestaltung</h3>';
        echo '<div class="qr-section-content">';
        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row">Farben</th>';
        echo '<td>';
        echo '<div style="display: flex; gap: 20px; align-items: center;">';
        echo '<div>';
        echo '<label for="fg_color" style="display: block; margin-bottom: 5px;">Vordergrund:</label>';
        echo '<input type="text" name="fg_color" id="fg_color" value="#000000" class="color-picker">';
        echo '</div>';
        echo '<div>';
        echo '<label for="bg_color" style="display: block; margin-bottom: 5px;">Hintergrund:</label>';
        echo '<input type="text" name="bg_color" id="bg_color" value="#ffffff" class="color-picker">';
        echo '</div>';
        echo '</div>';
        echo '<p class="description">Wählen Sie die Farben für QR-Code Module und Hintergrund</p>';
        echo '<div id="contrast_warning" style="display:none; color: #d63638; font-weight: bold; margin-top: 5px;">';
        echo '⚠️ Bitte beachten Sie, dass die Erkennbarkeit mit diesen Einstellungen nicht ideal sein könnte.';
        echo '</div>';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="size">Größe (px)</label></th>';
        echo '<td>';
        echo '<input type="range" name="size" id="size" value="400" min="100" max="1000" step="10">';
        echo '<span id="size_display">400</span> px';
        echo '<p class="description">Größe zwischen 100 und 1000 Pixel</p>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';
        echo '</div>';
        echo '</div>';
        echo '</form>';
        
        // Submit Buttons
        echo '<div class="qr-submit-section">';
        echo '<h3>QR-Code generieren</h3>';
        echo '<div class="qr-buttons-row">';
        echo '<input type="button" id="generate_qr_png" class="button-primary" value="QR-Code erstellen (PNG)">';
        echo '<input type="button" id="generate_qr_svg" class="button-secondary" value="QR-Code erstellen (SVG)">';
        echo '</div>';
        echo '<div class="qr-loading">';
        echo '<span id="loading" style="display:none;">Wird generiert...</span>';
        echo '</div>';
        echo '</div>';
        
        // Preset Buttons
        $this->render_preset_buttons();
        
        echo '</div>';
        
        // Rechte Spalte
        echo '<div class="qr-preview-column">';
        echo '<div id="qr_preview"></div>';
        echo '<div id="qr_download" class="qr-download-section" style="display:none;">';
        echo '<p class="submit">';
        echo '<input type="button" id="download_qr" class="button-secondary" value="Herunterladen">';
        echo '</p>';
        echo '</div>';
        echo '<div id="error_messages"></div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
    
    private function render_preset_buttons() {
        $presets = $this->get_presets();
        if (empty($presets)) {
            return;
        }
        
        echo '<div class="preset-buttons">';
        echo '<h3>Schnell-Auswahl (Presets)</h3>';
        
        foreach ($presets as $preset) {
            $disabled = isset($preset['disabled']) && $preset['disabled'] ? 'disabled="disabled"' : '';
            $data_attributes = '';
            
            foreach ($preset['data'] as $key => $value) {
                $data_attributes .= ' data-' . esc_attr($key) . '="' . esc_attr($value) . '"';
            }
            
            echo '<button type="button" class="button preset-btn" ' . $disabled . $data_attributes . '>';
            echo '<span class="qr-type-badge qr-type-' . esc_attr($preset['type']) . '">' . esc_html(ucfirst($preset['type'])) . '</span> ';
            echo esc_html($preset['label']);
            echo '</button>';
        }
        
        echo '</div>';
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Sie haben nicht die erforderlichen Berechtigungen für diese Seite.'));
        }

        $settings = $this->get_settings();

        echo '<div class="wrap">';
        echo '<h1>QR-Code-Generator Einstellungen</h1>';
        settings_errors();
        echo '<form method="post" action="options.php">';
        settings_fields('rmd_qr_settings_group');

        // Rollen
        echo '<div class="qr-type-section">';
        echo '<h3>Zugriff</h3>';
        echo '<div class="qr-section-content">';
        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row">Erlaubte Rollen</th>';
        echo '<td>';
        echo '<fieldset>';
        foreach (wp_roles()->get_names() as $role => $name) {
            $checked = in_array($role, $settings['roles'], true) ? ' checked' : '';
            echo '<label><input type="checkbox" name="' . esc_attr(RMD_QR_OPTION) . '[roles][]" value="' . esc_attr($role) . '"' . $checked . '> ' . esc_html(translate_user_role($name)) . '</label><br>';
        }
        echo '</fieldset>';
        echo '<p class="description">Administratoren haben immer Zugriff. Die Einstellungen sind nur für Administratoren sichtbar.</p>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';
        echo '</div>';
        echo '</div>';

        // Presets
        echo '<div class="qr-type-section">';
        echo '<h3>Presets</h3>';
        echo '<div class="qr-section-content">';
        echo '<p class="description">Presets erscheinen als Schnell-Auswahl auf der Seite „Erstellen“ und füllen das Formular vor. Inaktive Presets werden ausgegraut angezeigt.</p>';
        echo '<p class="description">Felder dürfen Shortcodes enthalten, z. B. <code>[cgv iban]</code>. Sie werden beim Öffnen der Seite „Erstellen“ aufgelöst. Datumsfelder unterstützen keine Shortcodes.</p>';
        echo '<div id="rmd-qr-presets">';
        foreach ($settings['presets'] as $index => $preset) {
            $this->render_preset_row($index, $preset);
        }
        echo '</div>';
        echo '<p><button type="button" class="button" id="rmd-qr-add-preset">Preset hinzufügen</button></p>';
        echo '<script type="text/html" id="tmpl-rmd-qr-preset">';
        $this->render_preset_row('__INDEX__', array());
        echo '</script>';
        echo '</div>';
        echo '</div>';

        submit_button();
        echo '</form>';
        echo '</div>';
    }

    private function render_preset_row($index, $preset) {
        $preset = wp_parse_args($preset, array(
            'type' => 'link',
            'label' => '',
            'active' => true,
            'url' => '',
            'text' => '',
            'event_title' => '',
            'event_location' => '',
            'event_start' => '',
            'event_end' => '',
            'event_description' => '',
            'giro_name' => '',
            'giro_iban' => '',
            'giro_bic' => '',
            'giro_amount' => '',
            'giro_purpose' => ''
        ));
        $name = RMD_QR_OPTION . '[presets][' . $index . ']';
        $types = array('link' => 'Link/URL', 'text' => 'Text', 'event' => 'Event/Termin', 'girocode' => 'Girocode');

        echo '<div class="rmd-qr-preset-row" data-type="' . esc_attr($preset['type']) . '">';
        echo '<table class="form-table">';

        echo '<tr>';
        echo '<th scope="row">Bezeichnung *</th>';
        echo '<td><input type="text" class="regular-text" name="' . esc_attr($name) . '[label]" value="' . esc_attr($preset['label']) . '"></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">Typ</th>';
        echo '<td>';
        echo '<select class="rmd-qr-preset-type" name="' . esc_attr($name) . '[type]">';
        foreach ($types as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($preset['type'], $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select> ';
        echo '<label><input type="checkbox" name="' . esc_attr($name) . '[active]" value="1"' . checked($preset['active'], true, false) . '> Aktiv</label>';
        echo '</td>';
        echo '</tr>';

        echo '<tr class="rmd-qr-field-link">';
        echo '<th scope="row">URL</th>';
        echo '<td><input type="text" class="regular-text" name="' . esc_attr($name) . '[url]" value="' . esc_attr($preset['url']) . '" placeholder="https://example.com"></td>';
        echo '</tr>';

        echo '<tr class="rmd-qr-field-text">';
        echo '<th scope="row">Text</th>';
        echo '<td><textarea rows="4" cols="50" maxlength="500" name="' . esc_attr($name) . '[text]">' . esc_textarea($preset['text']) . '</textarea></td>';
        echo '</tr>';

        echo '<tr class="rmd-qr-field-event">';
        echo '<th scope="row">Event Titel</th>';
        echo '<td><input type="text" class="regular-text" maxlength="100" name="' . esc_attr($name) . '[event_title]" value="' . esc_attr($preset['event_title']) . '"></td>';
        echo '</tr>';
        echo '<tr class="rmd-qr-field-event">';
        echo '<th scope="row">Ort</th>';
        echo '<td><input type="text" class="regular-text" maxlength="100" name="' . esc_attr($name) . '[event_location]" value="' . esc_attr($preset['event_location']) . '"></td>';
        echo '</tr>';
        echo '<tr class="rmd-qr-field-event">';
        echo '<th scope="row">Startdatum</th>';
        echo '<td><input type="datetime-local" name="' . esc_attr($name) . '[event_start]" value="' . esc_attr($preset['event_start']) . '"></td>';
        echo '</tr>';
        echo '<tr class="rmd-qr-field-event">';
        echo '<th scope="row">Enddatum</th>';
        echo '<td><input type="datetime-local" name="' . esc_attr($name) . '[event_end]" value="' . esc_attr($preset['event_end']) . '"></td>';
        echo '</tr>';
        echo '<tr class="rmd-qr-field-event">';
        echo '<th scope="row">Beschreibung</th>';
        echo '<td><textarea rows="3" cols="50" maxlength="200" name="' . esc_attr($name) . '[event_description]">' . esc_textarea($preset['event_description']) . '</textarea></td>';
        echo '</tr>';

        echo '<tr class="rmd-qr-field-girocode">';
        echo '<th scope="row">Empfänger</th>';
        echo '<td><input type="text" class="regular-text" maxlength="70" name="' . esc_attr($name) . '[giro_name]" value="' . esc_attr($preset['giro_name']) . '"></td>';
        echo '</tr>';
        echo '<tr class="rmd-qr-field-girocode">';
        echo '<th scope="row">IBAN</th>';
        echo '<td><input type="text" class="regular-text" maxlength="42" name="' . esc_attr($name) . '[giro_iban]" value="' . esc_attr($preset['giro_iban']) . '"></td>';
        echo '</tr>';
        echo '<tr class="rmd-qr-field-girocode">';
        echo '<th scope="row">BIC</th>';
        echo '<td><input type="text" class="regular-text" maxlength="11" name="' . esc_attr($name) . '[giro_bic]" value="' . esc_attr($preset['giro_bic']) . '"></td>';
        echo '</tr>';
        echo '<tr class="rmd-qr-field-girocode">';
        echo '<th scope="row">Betrag (EUR)</th>';
        echo '<td><input type="text" class="small-text" inputmode="decimal" name="' . esc_attr($name) . '[giro_amount]" value="' . esc_attr($preset['giro_amount']) . '"></td>';
        echo '</tr>';
        echo '<tr class="rmd-qr-field-girocode">';
        echo '<th scope="row">Verwendungszweck</th>';
        echo '<td><input type="text" class="regular-text" maxlength="140" name="' . esc_attr($name) . '[giro_purpose]" value="' . esc_attr($preset['giro_purpose']) . '"></td>';
        echo '</tr>';

        echo '</table>';
        echo '<p><button type="button" class="button-link button-link-delete rmd-qr-remove-preset">Preset entfernen</button></p>';
        echo '</div>';
    }

    public function cleanup_page() {
        // Berechtigung prüfen
        if (!$this->user_can_use_qr_generator()) {
            wp_die(__('Sie haben nicht die erforderlichen Berechtigungen für diese Seite.'));
        }
        
        $current_month = isset($_GET['month']) ? sanitize_text_field($_GET['month']) : date('Y-m');
        $available_months = $this->get_available_months();
        $qr_files = $this->get_qr_files_by_month($current_month);
        
        echo '<div class="wrap">';
        echo '<h1>QR-Codes aufräumen</h1>';
        echo '<div class="cleanup-container">';
        
        // Monats-Auswahl
        echo '<div class="month-selector">';
        echo '<h3>Monat auswählen</h3>';
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="rmd-qr-cleanup">';
        echo '<select name="month" onchange="this.form.submit()">';
        foreach ($available_months as $month => $label) {
            $selected = ($current_month === $month) ? 'selected' : '';
            echo '<option value="' . esc_attr($month) . '" ' . $selected . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</form>';
        echo '</div>';
        
        if (!empty($qr_files)) {
            echo '<form method="post" id="cleanup-form">';
            wp_nonce_field('cleanup_qr_files', 'cleanup_nonce');
            
            echo '<div class="cleanup-actions">';
            echo '<h3>QR-Code Dateien in ' . esc_html(date('F Y', strtotime($current_month . '-01'))) . ' (' . count($qr_files) . ' Dateien)</h3>';
            echo '<div class="action-buttons">';
            echo '<button type="button" id="select-all" class="button">Alle auswählen</button>';
            echo '<button type="button" id="select-none" class="button">Alle abwählen</button>';
            echo '<button type="submit" name="cleanup_action" value="delete_selected" class="button button-secondary" onclick="return confirm(\'Ausgewählte Dateien löschen?\')">Ausgewählte löschen</button>';
            echo '<button type="submit" name="cleanup_action" value="delete_month" class="button button-primary" onclick="return confirm(\'ALLE Dateien dieses Monats löschen?\')">Gesamten Monat löschen</button>';
            echo '<input type="hidden" name="selected_month" value="' . esc_attr($current_month) . '">';
            echo '</div>';
            echo '</div>';
            
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead>';
            echo '<tr>';
            echo '<td class="manage-column column-cb check-column"><input type="checkbox" id="cb-select-all"></td>';
            echo '<th scope="col" class="manage-column">Dateiname</th>';
            echo '<th scope="col" class="manage-column">Typ</th>';
            echo '<th scope="col" class="manage-column">Größe</th>';
            echo '<th scope="col" class="manage-column">Erstellt</th>';
            echo '<th scope="col" class="manage-column">Vorschau</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';
            
            foreach ($qr_files as $file) {
                echo '<tr>';
                echo '<th scope="row" class="check-column">';
                echo '<input type="checkbox" name="selected_files[]" value="' . esc_attr($file['path']) . '">';
                echo '</th>';
                echo '<td><strong>' . esc_html($file['name']) . '</strong></td>';
                echo '<td><span class="qr-type-badge qr-type-' . esc_attr($file['qr_type']) . '">' . esc_html(ucfirst($file['qr_type'])) . '</span></td>';
                echo '<td>' . esc_html($file['size']) . '</td>';
                echo '<td>' . esc_html($file['date']) . '</td>';
                echo '<td>';
                if ($file['extension'] === 'png') {
                    echo '<img src="' . esc_url($file['url']) . '" alt="QR Preview" style="max-width: 50px; max-height: 50px;">';
                } else {
                    echo '<span class="dashicons dashicons-media-code" title="SVG-Datei"></span>';
                }
                echo '</td>';
                echo '</tr>';
            }
            
            echo '</tbody>';
            echo '</table>';
            echo '</form>';
        } else {
            echo '<div class="no-files-message">';
            echo '<p>Keine QR-Code Dateien in ' . esc_html(date('F Y', strtotime($current_month . '-01'))) . ' gefunden.</p>';
            echo '</div>';
        }
        
        echo '</div>';
        echo '</div>';
    }
    
    public function generate_qr_code_ajax() {
        if (!wp_verify_nonce($_POST['nonce'], 'qr_nonce')) {
            wp_send_json_error('Sicherheitsüberprüfung fehlgeschlagen');
        }
        
        // Berechtigung prüfen
        if (!$this->user_can_use_qr_generator()) {
            wp_send_json_error('Unzureichende Berechtigung');
        }
        
        $type = sanitize_text_field($_POST['qr_type']);
        $fg_color = sanitize_hex_color($_POST['fg_color']);
        $bg_color = sanitize_hex_color($_POST['bg_color']);
        $size = intval($_POST['size']);
        $format = sanitize_text_field($_POST['format']);
        
        if (!in_array($type, $this->preset_types, true)) {
            wp_send_json_error('Ungültiger QR-Code Typ');
        }
        
        if (!$fg_color) $fg_color = '#000000';
        if (!$bg_color) $bg_color = '#ffffff';
        
        if ($size < 100 || $size > 1000) {
            wp_send_json_error('Größe muss zwischen 100 und 1000 Pixel liegen');
        }
        
        if (!in_array($format, array('png', 'svg'))) {
            wp_send_json_error('Ungültiges Format');
        }
        
        $content = $this->generate_content($type, $_POST);
        
        if (!$content) {
            wp_send_json_error('Inhalt konnte nicht generiert werden');
        }
        
        try {
            $result = $this->create_qr_code($content, $size, $fg_color, $bg_color, $format);
            
            if ($result) {
                wp_send_json_success($result);
            } else {
                wp_send_json_error('QR-Code konnte nicht generiert werden');
            }
        } catch (Exception $e) {
            wp_send_json_error('Fehler beim Generieren: ' . $e->getMessage());
        }
    }
    
    private function generate_content($type, $data) {
        switch ($type) {
            case 'link':
                $url = esc_url_raw($data['link_url']);
                if (!filter_var($url, FILTER_VALIDATE_URL)) {
                    return false;
                }
                return $url;
                
            case 'text':
                $text = sanitize_textarea_field($data['text_content']);
                if (empty(trim($text))) {
                    return false;
                }
                if (strlen($text) > 500) {
                    return false;
                }
                return $text;
                
            case 'event':
                $title = sanitize_text_field($data['event_title']);
                $location = sanitize_text_field($data['event_location']);
                $start = sanitize_text_field($data['event_start']);
                $end = sanitize_text_field($data['event_end']);
                $description = sanitize_textarea_field($data['event_description']);
                
                if (empty(trim($title)) || empty($start)) {
                    return false;
                }
                
                $start_time = strtotime($start);
                if (!$start_time) {
                    return false;
                }
                
                $end_time = null;
                if (!empty($end)) {
                    $end_time = strtotime($end);
                    if ($end_time && $end_time < $start_time) {
                        return false;
                    }
                }
                
                $start_formatted = date('Ymd\\THis', $start_time);
                $end_formatted = $end_time ? date('Ymd\\THis', $end_time) : date('Ymd\\THis', $start_time + 3600);
                
                $content = "BEGIN:VEVENT\r\n";
                $content .= "SUMMARY:" . $title . "\r\n";
                $content .= "DTSTART:" . $start_formatted . "\r\n";
                $content .= "DTEND:" . $end_formatted . "\r\n";
                if (!empty($location)) {
                    $content .= "LOCATION:" . $location . "\r\n";
                }
                if (!empty($description)) {
                    $content .= "DESCRIPTION:" . $description . "\r\n";
                }
                $content .= "END:VEVENT";

                return $content;

            case 'girocode':
                // EPC069-12 (Girocode), Version 002: BIC innerhalb des EWR optional
                $name = substr(sanitize_text_field($data['giro_name']), 0, 70);
                $iban = $this->normalize_iban($data['giro_iban']);
                $bic = $this->normalize_bic($data['giro_bic']);
                $amount = $this->normalize_amount($data['giro_amount']);
                $purpose = substr(sanitize_text_field($data['giro_purpose']), 0, 140);

                if (empty(trim($name)) || !$this->is_valid_iban($iban)) {
                    return false;
                }
                if ($bic !== '' && !$this->is_valid_bic($bic)) {
                    return false;
                }
                if ($amount !== '' && ((float) $amount < 0.01 || (float) $amount > 999999999.99)) {
                    return false;
                }

                $lines = array(
                    'BCD',
                    '002',
                    '1',
                    'SCT',
                    $bic,
                    $name,
                    $iban,
                    $amount !== '' ? 'EUR' . $amount : '',
                    '',
                    '',
                    $purpose
                );

                return implode("\n", $lines);

            default:
                return false;
        }
    }
    
    private function create_qr_code($content, $size, $fg_color, $bg_color, $format) {
        $filename_base = $this->generate_smart_filename($content);
        $filename = $filename_base . '_' . time() . '_' . wp_rand(1000, 9999);
        
        $upload_info = $this->get_upload_dir();
        $file_dir = $upload_info['path'];
        $file_url_base = $upload_info['url'];
        
        $fg_color_clean = str_replace('#', '', $fg_color);
        $bg_color_clean = str_replace('#', '', $bg_color);
        $margin_modules = max(4, round($size / 25));
        
        if ($format === 'svg') {
            $filename .= '.svg';
            $file_path = $file_dir . '/' . $filename;
            
            $api_url = "https://api.qrserver.com/v1/create-qr-code/";
            $params = array(
                'data' => $content,
                'size' => $size . 'x' . $size,
                'color' => $fg_color_clean,
                'bgcolor' => $bg_color_clean,
                'margin' => $margin_modules,
                'format' => 'svg',
                'ecc' => 'M'
            );
            
            $api_url_with_params = $api_url . '?' . http_build_query($params);
            
            $response = wp_remote_get($api_url_with_params, array(
                'timeout' => 30,
                'user-agent' => 'WordPress/QR-Generator',
                'headers' => array('Accept' => 'image/svg+xml,*/*')
            ));
            
            if (is_wp_error($response)) {
                return false;
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code !== 200) {
                return false;
            }
            
            $svg_content = wp_remote_retrieve_body($response);
            if (empty($svg_content) || strpos($svg_content, '<svg') === false) {
                return false;
            }
            
            $svg_content = preg_replace('/width="[^"]*"/', 'width="200"', $svg_content);
            $svg_content = preg_replace('/height="[^"]*"/', 'height="200"', $svg_content);
            $svg_content = str_replace('<svg', '<svg style="max-width: 200px; max-height: 200px; width: auto; height: auto;"', $svg_content);
            
            file_put_contents($file_path, $svg_content);
            
            return array(
                'html' => '<div><h3>Generierter QR-Code:</h3>' . $svg_content . '<p><strong>Format:</strong> SVG | <strong>Größe:</strong> ' . $size . 'x' . $size . ' px</p><p><strong>Datei:</strong> ' . esc_html($filename) . '</p><div class="svg-preview-notice" style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 8px; margin-top: 10px; border-radius: 4px; color: #856404;"><strong>Hinweis:</strong> Die SVG-Vorschau wird hier verkleinert dargestellt. Die heruntergeladene Datei hat die korrekte Größe von ' . $size . 'x' . $size . ' Pixel.</div></div>',
                'filename' => $filename
            );
            
        } else {
            $filename .= '.png';
            $file_path = $file_dir . '/' . $filename;
            
            $api_url = "https://api.qrserver.com/v1/create-qr-code/";
            $params = array(
                'data' => $content,
                'size' => $size . 'x' . $size,
                'color' => $fg_color_clean,
                'bgcolor' => $bg_color_clean,
                'margin' => $margin_modules,
                'format' => 'png',
                'ecc' => 'M'
            );
            
            $api_url_with_params = $api_url . '?' . http_build_query($params);
            
            $response = wp_remote_get($api_url_with_params, array(
                'timeout' => 30,
                'user-agent' => 'WordPress/QR-Generator',
                'headers' => array('Accept' => 'image/png,image/*,*/*')
            ));
            
            if (is_wp_error($response)) {
                return false;
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code !== 200) {
                return false;
            }
            
            $image_data = wp_remote_retrieve_body($response);
            if (empty($image_data)) {
                return false;
            }
            
            if (substr($image_data, 0, 8) !== "\x89PNG\r\n\x1a\n") {
                return false;
            }
            
            $saved = file_put_contents($file_path, $image_data);
            if (!$saved) {
                return false;
            }
            
            $file_url = $file_url_base . '/' . $filename;
            
            $preview_html = '<div>';
            $preview_html .= '<h3>Generierter QR-Code:</h3>';
            $preview_html .= '<img src="' . esc_url($file_url) . '" alt="QR Code" style="border: 1px solid #ddd; padding: 10px;">';
            $preview_html .= '<p><strong>Format:</strong> PNG | <strong>Größe:</strong> ' . $size . 'x' . $size . ' px</p>';
            $preview_html .= '<p><strong>Datei:</strong> ' . esc_html($filename) . '</p>';
            $preview_html .= '</div>';
            
            return array(
                'html' => $preview_html,
                'filename' => $filename
            );
        }
    }
    
    private function generate_smart_filename($content) {
        if (filter_var($content, FILTER_VALIDATE_URL)) {
            $parsed_url = parse_url($content);
            $domain = isset($parsed_url['host']) ? $parsed_url['host'] : 'url';
            $domain = str_replace('www.', '', $domain);
            $domain = preg_replace('/[^a-zA-Z0-9\-]/', '', $domain);
            return 'qr-link-' . substr($domain, 0, 20);
        } elseif (strpos($content, "BCD\n") === 0) {
            $lines = explode("\n", $content);
            $name_clean = $this->clean_filename_text(isset($lines[5]) ? $lines[5] : '', 3);
            return 'qr-girocode-' . ($name_clean ?: 'zahlung');
        } elseif (strpos($content, 'BEGIN:VEVENT') !== false) {
            $lines = explode("\r\n", $content);
            $title = '';
            $date = '';
            
            foreach ($lines as $line) {
                if (strpos($line, 'SUMMARY:') === 0) {
                    $title = substr($line, 8);
                } elseif (strpos($line, 'DTSTART:') === 0) {
                    $date_string = substr($line, 8);
                    if (strlen($date_string) >= 8) {
                        $year = substr($date_string, 0, 4);
                        $month = substr($date_string, 4, 2);
                        $day = substr($date_string, 6, 2);
                        $date = $year . '-' . $month . '-' . $day;
                    }
                }
            }
            
            $title_clean = $this->clean_filename_text($title, 3);
            
            if ($date && $title_clean) {
                return 'qr-event-' . $date . '-' . $title_clean;
            } elseif ($title_clean) {
                return 'qr-event-' . $title_clean;
            } else {
                return 'qr-event';
            }
        } else {
            $text_clean = $this->clean_filename_text($content, 3);
            return 'qr-text-' . ($text_clean ?: 'content');
        }
    }
    
    private function clean_filename_text($text, $max_words = 3) {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $words = preg_split('/\s+/', $text);
        $words = array_slice($words, 0, $max_words);
        $clean_words = array();
        
        foreach ($words as $word) {
            $clean_word = preg_replace('/[^a-zA-Z0-9äöüÄÖÜß]/', '', $word);
            if (strlen($clean_word) > 0) {
                $clean_words[] = substr($clean_word, 0, 15);
            }
        }
        
        return implode('-', $clean_words);
    }
    
    public function download_qr_code() {
        if (isset($_GET['download']) && isset($_GET['nonce']) && wp_verify_nonce($_GET['nonce'], 'download_nonce')) {
            // Berechtigung prüfen
            if (!$this->user_can_use_qr_generator()) {
                wp_die(__('Sie haben nicht die erforderlichen Berechtigungen für diese Aktion.'));
            }
            
            $filename = sanitize_file_name($_GET['download']);
            $upload_info = $this->get_upload_dir();
            $file_path = $upload_info['path'] . '/' . $filename;
            
            if (file_exists($file_path)) {
                $extension = pathinfo($filename, PATHINFO_EXTENSION);
                
                if ($extension === 'svg') {
                    header('Content-Type: image/svg+xml');
                } else {
                    header('Content-Type: image/png');
                }
                
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . filesize($file_path));
                header('Cache-Control: no-cache, must-revalidate');
                
                readfile($file_path);
                wp_schedule_single_event(time() + 300, 'delete_qr_file', array($file_path));
                exit;
            } else {
                wp_die('Datei nicht gefunden: ' . $filename);
            }
        }
    }
    
    private function get_available_months() {
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];
        $months = array();
        
        for ($i = 0; $i < 12; $i++) {
            $date = date('Y-m', strtotime("-$i months"));
            $year = substr($date, 0, 4);
            $month = substr($date, 5, 2);
            $month_dir = $base_dir . '/' . $year . '/' . $month;
            
            if (is_dir($month_dir)) {
                $files = $this->scan_qr_files($month_dir);
                if (!empty($files)) {
                    $months[$date] = date('F Y', strtotime($date . '-01'));
                }
            }
        }
        
        if (empty($months)) {
            $current = date('Y-m');
            $months[$current] = date('F Y');
        }
        
        return $months;
    }
    
    private function get_qr_files_by_month($month) {
        $upload_dir = wp_upload_dir();
        $year = substr($month, 0, 4);
        $month_num = substr($month, 5, 2);
        $month_dir = $upload_dir['basedir'] . '/' . $year . '/' . $month_num;
        $month_url = $upload_dir['baseurl'] . '/' . $year . '/' . $month_num;
        
        if (!is_dir($month_dir)) {
            return array();
        }
        
        $files = $this->scan_qr_files($month_dir);
        $result = array();
        
        foreach ($files as $file) {
            $file_path = $month_dir . '/' . $file;
            $file_url = $month_url . '/' . $file;
            
            if (is_file($file_path)) {
                $result[] = array(
                    'name' => $file,
                    'path' => $file_path,
                    'url' => $file_url,
                    'size' => $this->format_file_size(filesize($file_path)),
                    'date' => date('d.m.Y H:i', filemtime($file_path)),
                    'extension' => pathinfo($file, PATHINFO_EXTENSION),
                    'qr_type' => $this->detect_qr_type($file)
                );
            }
        }
        
        usort($result, function($a, $b) {
            return filemtime($b['path']) - filemtime($a['path']);
        });
        
        return $result;
    }
    
    private function scan_qr_files($directory) {
        if (!is_dir($directory)) {
            return array();
        }
        
        $files = scandir($directory);
        $qr_files = array();
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            if (preg_match('/^qr-(link|text|event|girocode)-.*\.(png|svg)$/', $file)) {
                $qr_files[] = $file;
            }
        }
        
        return $qr_files;
    }
    
    private function detect_qr_type($filename) {
        if (strpos($filename, 'qr-link-') === 0) {
            return 'link';
        } elseif (strpos($filename, 'qr-text-') === 0) {
            return 'text';
        } elseif (strpos($filename, 'qr-event-') === 0) {
            return 'event';
        } elseif (strpos($filename, 'qr-girocode-') === 0) {
            return 'girocode';
        }
        return 'unknown';
    }
    
    private function format_file_size($bytes) {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' B';
        }
    }
    
    private function delete_selected_files($file_paths) {
        $deleted = 0;
        foreach ($file_paths as $file_path) {
            $file_path = sanitize_text_field($file_path);
            $filename = basename($file_path);
            if (preg_match('/^qr-(link|text|event|girocode)-.*\.(png|svg)$/', $filename) && file_exists($file_path)) {
                if (unlink($file_path)) {
                    $deleted++;
                }
            }
        }
        return $deleted;
    }
    
    private function delete_month_files($month) {
        $files = $this->get_qr_files_by_month($month);
        $deleted = 0;
        
        foreach ($files as $file) {
            if (file_exists($file['path']) && unlink($file['path'])) {
                $deleted++;
            }
        }
        
        return $deleted;
    }
}

add_action('delete_qr_file', function($file_path) {
    if (file_exists($file_path)) {
        unlink($file_path);
    }
});

new RMDQRCodeGenerator();

register_activation_hook(__FILE__, 'rmd_qrg_create_directories');

function rmd_qrg_create_directories() {
    $upload_dir = wp_upload_dir();
    if (!file_exists($upload_dir['path'])) {
        wp_mkdir_p($upload_dir['path']);
    }
}
?>