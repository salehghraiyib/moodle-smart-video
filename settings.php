<?php
defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings_page = new admin_settingpage(
        'mod_smartvideo',                
        get_string('pluginname', 'mod_smartvideo')
    );

    $settings_page->add(new admin_setting_heading(
        'mod_smartvideo/general_settings',
        get_string('pluginname', 'mod_smartvideo'),
        get_string('general', 'core')
    ));

    $settings_page->add(new admin_setting_configtext(
        'mod_smartvideo/apikey',                    
        'Gemini API Key',                
        'Enter your Google Gemini API Key here (starts with AIza...)', // Description
        '',                             
        PARAM_TEXT                      
    ));

    if (isset($settings) && $settings instanceof admin_category) {
        $settings->add('mod_smartvideo', $settings_page);
    } else {
        $ADMIN->add('modsettings', $settings_page);
    }
}