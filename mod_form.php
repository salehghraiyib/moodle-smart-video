<?php
defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot.'/course/moodleform_mod.php');

class mod_smartvideo_mod_form extends moodleform_mod {
    function definition() {
        global $CFG;
        $mform = $this->_form;

        // 1. Standard Name Field
// 1. Standard Name Field
        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('name', 'smartvideo'), array('size'=>'48'));
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->setType('name', PARAM_TEXT);

        $this->standard_intro_elements();

        // 2. The Video Upload Manager
        // This creates the drag-and-drop box
        $mform->addElement('filemanager', 'videofile', get_string('videofile', 'smartvideo'), null,
            array('subdirs' => 0, 'maxbytes' => $CFG->maxbytes, 'maxfiles' => 1, 'accepted_types' => array('.mp4', '.mov', '.webm')));

        $mform->addHelpButton('videofile', 'videofile', 'smartvideo');

        $mform->addElement('header', 'slidesheader', get_string('slidesheader', 'smartvideo'));
        
        $mform->addElement('filemanager', 'slidefile', get_string('slidefile', 'smartvideo'), null,
            array(
                'subdirs' => 0, 
                'maxbytes' => $CFG->maxbytes, 
                'maxfiles' => 1, // Limit to 1 PDF
                'accepted_types' => array('.pdf') // Restrict to PDF only
            )
        );
        
        $mform->addHelpButton('slidefile', 'slidefile', 'smartvideo');

        // Standard Course module elements
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }
}