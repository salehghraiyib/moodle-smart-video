<?php
defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot.'/course/moodleform_mod.php');

class mod_smartvideo_mod_form extends moodleform_mod {
    function definition() {
        global $CFG;
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('name', 'smartvideo'), array('size'=>'48'));
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->setType('name', PARAM_TEXT);

        $this->standard_intro_elements();


        //video
        $mform->addElement('filemanager', 'videofile', get_string('videofile', 'smartvideo'), null,
            array('subdirs' => 0, 'maxbytes' => $CFG->maxbytes, 'maxfiles' => 1, 'accepted_types' => array('.mp4', '.mov', '.webm')));

        $mform->addHelpButton('videofile', 'videofile', 'smartvideo');

        $mform->addElement('header', 'slidesheader', get_string('slidesheader', 'smartvideo'));
        
        $mform->addElement('filemanager', 'slidefile', get_string('slidefile', 'smartvideo'), null,
            array(
                'subdirs' => 0, 
                'maxbytes' => $CFG->maxbytes, 
                'maxfiles' => 1,
                'accepted_types' => array('.pdf')
            )
        );
        
        $mform->addHelpButton('slidefile', 'slidefile', 'smartvideo');

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }
}