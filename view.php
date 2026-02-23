<?php
require_once('../../config.php');
require_once($CFG->dirroot.'/mod/smartvideo/lib.php');
use core\url;

// 1. Get the Course Module ID (cmid)
$id = required_param('id', PARAM_INT);

// 2. Load the Course Module and Activity Instance
$cm = get_coursemodule_from_id('smartvideo', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$smartvideo = $DB->get_record('smartvideo', array('id' => $cm->instance), '*', MUST_EXIST);

// 3. Security Checks (Login & Permissions)
require_login($course, true, $cm);

/** @var context $context */
$context = context_module::instance($cm->id, MUST_EXIST);

require_capability('mod/smartvideo:view', $context);

// 4. Setup the Page
$PAGE->set_url('/mod/smartvideo/view.php', array('id' => $cm->id));
$PAGE->set_title(format_string($smartvideo->name));
$PAGE->set_heading(format_string($course->fullname));

// 5. Get the Video URL
// We look for the file we saved in 'content' filearea
$fs = get_file_storage();
$files = $fs->get_area_files($context->id, 'mod_smartvideo', 'content', 0, 'sortorder DESC, id ASC', false);
$videoUrl = '';

if (!empty($files)) {
    $file = reset($files); // Get the first file
    $videoUrl = url::make_pluginfile_url(
        $file->get_contextid(),
        $file->get_component(),
        $file->get_filearea(),
        $file->get_itemid(),
        $file->get_filepath(),
        $file->get_filename()
    );
}

// 6. Get the AI Topics from Database
$topics = $DB->get_records('smartvideo_topics', ['smartvideoid' => $smartvideo->id], 'start_seconds ASC');

$slideFiles = $fs->get_area_files($context->id, 'mod_smartvideo', 'slides', 0, 'sortorder DESC, id ASC', false);
$slideUrl = '';
$slideFilename = '';

if (!empty($slideFiles)) {
    $sFile = reset($slideFiles); // Get the first PDF
    $slideFilename = $sFile->get_filename();
    $slideUrl = url::make_pluginfile_url(
        $sFile->get_contextid(),
        $sFile->get_component(),
        $sFile->get_filearea(),
        $sFile->get_itemid(),
        $sFile->get_filepath(),
        $sFile->get_filename()
    );
}

// --- OUTPUT STARTS HERE ---
echo $OUTPUT->header();

// Include Video.js via CDN (Quickest way for Dev)
echo '<link href="https://vjs.zencdn.net/8.0.0/video-js.css" rel="stylesheet" />';
echo '<script src="https://vjs.zencdn.net/8.0.0/video.min.js"></script>';

// Custom Styles for our Layout
echo "
<style>
    .smartvideo-container { display: flex; flex-wrap: wrap; gap: 20px; margin-top: 20px; }
    .video-panel { flex: 2; min-width: 300px; }
    .transcript-panel { flex: 1; min-width: 250px; background: #f8f9fa; border: 1px solid #ddd; border-radius: 8px; max-height: 500px; display: flex; flex-direction: column; }
    .transcript-header { padding: 15px; border-bottom: 1px solid #ddd; background: #fff; border-radius: 8px 8px 0 0; }
    .topic-list { overflow-y: auto; padding: 0; margin: 0; list-style: none; }
    .topic-item { padding: 12px 15px; border-bottom: 1px solid #eee; cursor: pointer; transition: background 0.2s; }
    .topic-item:hover { background: #e9ecef; }
    .topic-item.active { background: #d1e7dd; border-left: 4px solid #0f5132; }
    .topic-time { font-size: 0.85em; color: #6c757d; font-weight: bold; }
    .topic-title { font-weight: 600; display: block; margin-bottom: 4px; }
    .topic-keywords { font-size: 0.85em; color: #666; }
</style>
";

echo "<h2>" . format_string($smartvideo->name) . "</h2>";

// Layout Container
echo '<div class="smartvideo-container">';

// -- LEFT COLUMN: VIDEO PLAYER --
echo '<div class="video-panel">';
if ($videoUrl) {
    echo '<video id="my-player" class="video-js vjs-big-play-centered vjs-fluid" controls preload="auto" data-setup="{}">
            <source src="' . $videoUrl . '" type="video/mp4" />
            <p class="vjs-no-js">To view this video please enable JavaScript.</p>
          </video>';
} else {
    echo $OUTPUT->notification('No video file found.', 'warning');
}
echo '</div>'; // End video-panel

// -- RIGHT COLUMN: TOPICS & SEARCH --
echo '<div class="transcript-panel">';
    
    // Search Header
    echo '<div class="transcript-header">';
        echo '<input type="text" id="topicSearch" class="form-control" placeholder="Search topics & keywords...">';
    echo '</div>';

    // Topics List
    echo '<ul class="topic-list" id="topicList">';
    if ($topics) {
        foreach ($topics as $t) {
            // Format seconds into MM:SS
            $minutes = floor($t->start_seconds / 60);
            $seconds = $t->start_seconds % 60;
            $timeLabel = sprintf("%02d:%02d", $minutes, $seconds);

            echo '<li class="topic-item" data-time="' . $t->start_seconds . '">';
            echo '  <span class="topic-time">' . $timeLabel . '</span>';
            echo '  <span class="topic-title">' . htmlspecialchars($t->title) . '</span>';
            if (!empty($t->keywords)) {
                echo '  <div class="topic-keywords">' . htmlspecialchars($t->keywords) . '</div>';
            }
            echo '</li>';
        }
    } else {
        echo '<li class="p-3 text-muted text-center">No AI topics generated yet.</li>';
    }
    echo '</ul>';

echo '</div>'; // End transcript-panel
echo '</div>'; // End smartvideo-container

// --- NEW SECTION: SUMMARY & SLIDES ---
echo '<div class="summary-panel" style="margin-top: 30px; background: #fff; padding: 25px; border: 1px solid #e0e0e0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">';

    echo '<div style="display:flex; justify-content:space-between; align-items:center; border-bottom: 2px solid #f2f2f2; padding-bottom: 15px; margin-bottom: 20px;">';
        echo '<h3 style="margin:0;">Lecture Summary</h3>';
        
        // Download Button (Only if PDF exists)
        if ($slideUrl) {
            echo '<a href="' . $slideUrl . '" class="btn btn-secondary" target="_blank">
                    <i class="fa fa-file-pdf-o"></i> Download Slides (' . $slideFilename . ')
                  </a>';
        }
    echo '</div>';

    echo '<div class="summary-content">';
        if (!empty($smartvideo->summary)) {
            // format_text ensures the HTML is rendered safely and correctly
            echo format_text($smartvideo->summary, $smartvideo->summaryformat, ['context' => $context]);
        } else {
            echo '<p class="text-muted"><em>A summary is being generated by AI. Please check back later.</em></p>';
        }
    echo '</div>';

echo '</div>';

// --- JAVASCRIPT LOGIC ---
// Note: We use pure JS here to keep it simple and dependency-free
echo "
<script>
document.addEventListener('DOMContentLoaded', function() {
    var player = videojs('my-player');
    var list = document.getElementById('topicList');
    var searchInput = document.getElementById('topicSearch');
    var items = document.querySelectorAll('.topic-item');

    // 1. Handle Click to Jump
    items.forEach(function(item) {
        item.addEventListener('click', function() {
            var time = this.getAttribute('data-time');
            player.currentTime(time);
            player.play();
            
            // Highlight active
            items.forEach(i => i.classList.remove('active'));
            this.classList.add('active');
        });
    });

    // 2. Handle Search Filtering
    searchInput.addEventListener('keyup', function() {
        var filter = this.value.toLowerCase();
        
        items.forEach(function(item) {
            var text = item.textContent || item.innerText;
            if (text.toLowerCase().indexOf(filter) > -1) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
            }
        });
    });

    // 3. Highlight current topic while playing
    player.on('timeupdate', function() {
        var currentTime = player.currentTime();
        
        // Find the topic that is currently playing
        var activeItem = null;
        items.forEach(function(item) {
            var time = parseFloat(item.getAttribute('data-time'));
            if (currentTime >= time) {
                activeItem = item;
            }
        });

        if (activeItem) {
            items.forEach(i => i.classList.remove('active'));
            activeItem.classList.add('active');
            
            // Optional: Auto-scroll the list to keep active item in view
            // activeItem.scrollIntoView({behavior: 'smooth', block: 'nearest'});
        }
    });
});
</script>
";

echo $OUTPUT->footer();