<?php
defined('MOODLE_INTERNAL') || die();

/**
 * 1. Add a new instance of smartvideo
 */
function smartvideo_add_instance($data, $mform) {
    global $DB;

    // Prepare data for DB
    $data->timemodified = time();
    $data->status = 0; // Set status to "New"

    // Insert into 'smartvideo' table
    $data->id = $DB->insert_record('smartvideo', $data);

    // SAVE THE FILE
    /** @var context $context */
    $context = context_module::instance($data->coursemodule, MUST_EXIST);
    file_save_draft_area_files(
        $data->videofile,       // The draft ID from the form
        $context->id,           // The context (Where does it belong?)
        'mod_smartvideo',       // The component
        'content',              // The file area name
        0,                      // Item ID (usually 0 for main content)
        ['subdirs' => 0, 'maxfiles' => 1]
    );

    file_save_draft_area_files(
        $data->slidefile,       // Matches the name in mod_form.php
        $context->id,
        'mod_smartvideo',
        'slides',               // The specific file area for PDFs
        0,
        ['subdirs' => 0, 'maxfiles' => 1]
    );

    $task = new \mod_smartvideo\task\process_video();
    
    // Pass the ID so the task knows which video to process
    $task->set_custom_data(['instanceId' => $data->id]);
    
    // Queue it to run ASAP
    \core\task\manager::queue_adhoc_task($task);

    if (!empty($data->slidefile)) {
        $slide_task = new \mod_smartvideo\task\process_slides();
        $slide_task->set_custom_data(['instanceId' => $data->id]);
        \core\task\manager::queue_adhoc_task($slide_task);
    }

    
    
    return $data->id;
}

// Required stubs to prevent errors
function smartvideo_update_instance($data, $mform) {
    global $DB;

    // 1. Prepare Data
    $data->timemodified = time();
    $data->id = $data->instance;

    // Handle the Editor field (Fixes the NULL error)
    if (isset($data->introeditor)) {
        $data->intro = $data->introeditor['text'];
        $data->introformat = $data->introeditor['format'];
    }

    // Default values if null
    if (empty($data->intro)) {
        $data->intro = '';
    }
    if (empty($data->introformat)) {
        $data->introformat = FORMAT_HTML; 
    }

    

    // 2. Update the record (Only do this ONCE)
    $DB->update_record('smartvideo', $data);

    /** @var context $context */
    $context = context_module::instance($data->coursemodule, MUST_EXIST);
    $fs = get_file_storage();

        // 3. DETECT CHANGES: Get existing PDF hash BEFORE saving
    $oldHash = null;
    $oldFiles = $fs->get_area_files($context->id, 'mod_smartvideo', 'slides', 0, 'sortorder DESC, id ASC', false);
    if (!empty($oldFiles)) {
        $file = reset($oldFiles);
        $oldHash = $file->get_contenthash();
    }

    
    // 4. SAVE THE FILES
    // Save Video
    file_save_draft_area_files(
        $data->videofile, 
        $context->id, 
        'mod_smartvideo', 
        'content', 
        0, 
        ['subdirs' => 0, 'maxfiles' => 1]
    );

    // Save Slides
    file_save_draft_area_files(
        $data->slidefile,
        $context->id,
        'mod_smartvideo',
        'slides',
        0,
        ['subdirs' => 0, 'maxfiles' => 1]
    );

    // 5. DETECT CHANGES: Get new PDF hash AFTER saving
    $newHash = null;
    $newFiles = $fs->get_area_files($context->id, 'mod_smartvideo', 'slides', 0, 'sortorder DESC, id ASC', false);
    if (!empty($newFiles)) {
        $file = reset($newFiles);
        $newHash = $file->get_contenthash();
    }

    // 6. CONDITIONAL TRIGGER
    // Only run AI if a file exists AND it is different from the old one
    if ($newHash && $newHash !== $oldHash) {
        
        // Fetch the current record from the database
        $current = $DB->get_record('smartvideo', array('id' => $data->id));

        // SAFE GUARD: Only try to clear 'summary' if the field actually exists and has content.
        // This prevents the "coding error" if the 'summary' column is missing from your database.
        if (isset($current->summary) && !empty($current->summary)) {
             $update = new stdClass();
             $update->id = $data->id;
             $update->summary = ''; 
             $DB->update_record('smartvideo', $update);
        }

        // Queue the AI Task
        $task = new \mod_smartvideo\task\process_slides();
        $task->set_custom_data(['instanceId' => $data->id]);
        \core\task\manager::queue_adhoc_task($task);
    }

    return true;
}

function smartvideo_delete_instance($id) {
    global $DB;

    if (!$record = $DB->get_record('smartvideo', array('id' => $id))) {
        return false;
    }


    $DB->delete_records('smartvideo_topics', array('smartvideoid' => $id));

    $DB->delete_records('smartvideo', array('id' => $id));

    return true;
}

/**
 * Serves the files from the 'content' file area
 *
 * @param stdClass $course The course object
 * @param stdClass $cm The course module object
 * @param context $context The context
 * @param string $filearea The file area
 * @param array $args The file path arguments
 * @param bool $forcedownload Whether or not to force download
 * @param array $options Additional options
 * @return bool false if file not found, does not return if found - just sends the file
 */
function smartvideo_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options=array()) {
    
    // 1. Check Permissions
    require_login($course, true, $cm);

    if ($filearea !== 'content' && $filearea !== 'slides') {
        return false;
    }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/mod_smartvideo/$filearea/$relativepath";

    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        return false;
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
    return true;
}