<?php
namespace mod_smartvideo\task;

defined('MOODLE_INTERNAL') || die();

class process_video extends \core\task\adhoc_task {

    public function execute() {
global $DB, $CFG;

        // 1. Get the Data passed from lib.php
        $data = $this->get_custom_data();
        $instanceId = $data->instanceId;

        mtrace("Starting AI processing for SmartVideo ID: " . $instanceId);

        // 2. Get the API Key
        $apiKey = get_config('mod_smartvideo', 'apikey');
        if (empty($apiKey)) {
            mtrace("Error: No API Key found.");
            return;
        }

        // 3. Find the Video File
        $fs = get_file_storage();
        // Context for module instance (We need to find the context ID first)
        $cm = get_coursemodule_from_instance('smartvideo', $instanceId);
        $context = \context_module::instance($cm->id);
        
        $files = $fs->get_area_files($context->id, 'mod_smartvideo', 'content', 0, 'sortorder DESC, id ASC', false);
        if (empty($files)) {
            mtrace("Error: No video file found.");
            return;
        }
        $file = reset($files);

        $tempdir = make_request_directory();
        $inputVideo = $tempdir . '/input_video.tmp';
        $outputAudio = $tempdir . '/output_audio.mp3';

        // Copy video to temp disk so FFmpeg can read it
        $file->copy_content_to($inputVideo);

        // 5. Run FFmpeg (Extract Audio)
        $cmd = "ffmpeg -i " . escapeshellarg($inputVideo) . " -vn -acodec libmp3lame -b:a 128k -y " . escapeshellarg($outputAudio) . " 2>&1";
        exec($cmd, $output, $returnVar);

        if ($returnVar !== 0) {
            mtrace("FFmpeg failed: " . implode("\n", $output));
            return; // Stop if audio extraction failed
        }
        mtrace("Audio extracted successfully.");

        $durationSeconds = $this->get_video_duration($inputVideo);
        mtrace("Video Duration: " . $durationSeconds . " seconds");

        $fileUri = $this->upload_to_gemini($outputAudio, 'audio/mp3', $apiKey);
        if (!$fileUri) {
            mtrace("Failed to upload to Gemini.");
            return;
        }
        mtrace("File uploaded to Gemini. URI: " . $fileUri);

        sleep(5); 
        $jsonResponse = $this->analyze_with_gemini($fileUri, $apiKey, durationSeconds: $durationSeconds);

        if ($jsonResponse) {
                $DB->delete_records('smartvideo_topics', ['smartvideoid' => $instanceId]);

                foreach ($jsonResponse as $topic) {
                    $startTime = $this->parse_timestamp($topic->timestamp_seconds ?? 0);

                    // --- CRITICAL FIX START: The "Suspenders" ---
                    // If we know the duration, and this topic starts AFTER the video ends...
                    if ($durationSeconds > 0 && $startTime > $durationSeconds) {
                        mtrace("Skipping invalid topic at $startTime seconds (Video ends at $durationSeconds)");
                        continue; // Skip this bad topic, don't save it
                    }

                    // --- CRITICAL FIX END ---

                    $record = new \stdClass();
                    $record->smartvideoid = $instanceId;
                    $record->title = $topic->topic ?? 'Unknown Topic';
                    $record->start_seconds = $startTime;
                    $rawKeywords = $topic->keywords ?? [];
if (is_array($rawKeywords)) {
    $record->keywords = implode(', ', $rawKeywords);
} else {
    $record->keywords = (string)$rawKeywords;
}
                    
                    $DB->insert_record('smartvideo_topics', $record);
                }
                
                mtrace("Success: Saved topics to database.");
                
                // Update status to Ready
                $update = new \stdClass();
                $update->id = $instanceId;
                $update->status = 2; 
                $DB->update_record('smartvideo', $update);
            } else {
            mtrace("Error: Invalid JSON response from Gemini.");
        }

        @unlink($inputVideo);
        @unlink($outputAudio);
    }

private function upload_to_gemini($filepath, $mime, $key) {
        mtrace("--- DEBUG: Starting Upload Process ---");
        
        // 1. SANITY CHECKS
        if (!file_exists($filepath)) {
            mtrace("CRITICAL: File not found at $filepath");
            return false;
        }
        $filesize = filesize($filepath);
        if ($filesize === 0 || $filesize === false) {
             mtrace("CRITICAL: File is empty (0 bytes). FFmpeg failed.");
             return false;
        }
        mtrace("DEBUG: File size is " . $filesize . " bytes");

        $url = "https://generativelanguage.googleapis.com/upload/v1beta/files?key=" . $key;
        
        // FIX: Use ONE variable for metadata to ensure length matches
        $metadata = json_encode(['file' => ['display_name' => 'moodle_audio_extract']]);

        // --- STEP 1: HANDSHAKE (Get Upload URL) ---
        mtrace("DEBUG: Sending Handshake to $url");
        
        $headers = [
            'X-Goog-Upload-Protocol: resumable',
            'X-Goog-Upload-Command: start',
            'X-Goog-Upload-Header-Content-Length: ' . $filesize,
            'X-Goog-Upload-Header-Content-Type: ' . $mime,
            'Content-Type: application/json',
            'Content-Length: ' . strlen($metadata) // Now this matches $metadata below
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $metadata); // Sending the correct variable
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_HEADER, true); // We need headers to find the URL
        
        // Security & Timeout Settings
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        
        // Check Handshake Errors
        if ($response === false) {
            mtrace("CURL FATAL ERROR (Handshake): " . curl_error($ch));
            curl_close($ch);
            return false;
        }
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode >= 400) {
            $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $body = substr($response, $header_size);
            mtrace("API HANDSHAKE ERROR ($httpCode): " . $body);
            curl_close($ch);
            return false;
        }

        // Extract Upload URL
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header_text = substr($response, 0, $header_size);
        curl_close($ch); // Close first handle

        if (!preg_match('/x-goog-upload-url: (.*)/i', $header_text, $matches)) {
            mtrace("ERROR: Could not find Upload URL in headers.");
            return false;
        }
        $uploadUrl = trim($matches[1]);
        
        mtrace("DEBUG: Handshake successful. Upload URL obtained.");

        // --- STEP 2: UPLOAD FILE BYTES ---
        $fp = fopen($filepath, 'rb');
        $ch = curl_init($uploadUrl);

        $uploadHeaders = [
            'Content-Length: ' . $filesize,
            'X-Goog-Upload-Offset: 0',
            'X-Goog-Upload-Command: upload, finalize'
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $uploadHeaders);

        curl_setopt($ch, CURLOPT_PUT, true);
        curl_setopt($ch, CURLOPT_INFILE, $fp);
        curl_setopt($ch, CURLOPT_INFILESIZE, $filesize);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        // Security & Timeout Settings
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 minutes

        $response = curl_exec($ch);

        // Check Upload Errors
        if ($response === false) {
            mtrace("CURL FATAL ERROR (Upload): " . curl_error($ch));
            curl_close($ch);
            fclose($fp);
            return false;
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if ($httpCode >= 400) {
            mtrace("UPLOAD API ERROR ($httpCode): " . $response);
            return false;
        }

        mtrace("DEBUG: Upload finished successfully.");
        $json = json_decode($response);
        return $json->file->uri ?? false;
    }

    private function analyze_with_gemini($fileUri, $key, $durationSeconds) {


        if (!$this->wait_for_file_active($fileUri, $key)) {
            return false;
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $key;

        $timeContext = "";
        if ($durationSeconds > 0) {
            $timeContext = "The video duration is exactly $durationSeconds seconds.";
        }

$prompt = "You are an expert educational content indexer for a learning platform.
Your task is to carefully listen to the provided audio and segment it into meaningful, navigable learning sections.

Accuracy is critical. Students will rely on your output to jump directly to relevant parts of the material.

$timeContext

GENERAL RULES:
- Process the audio sequentially from start to end.
- Topics must be ordered chronologically by when they begin.
- Each topic must represent a distinct shift in subject, concept, or sub-topic.
- Do not merge unrelated ideas into one topic.
- Do not create vague or generic topics.
- You may create multiple topics within the same overall subject if the focus changes.

TIMESTAMP RULES:
- \"timestamp_seconds\" must represent the exact moment the topic clearly begins in the audio.
- Timestamps must be whole integers in seconds.
- Do not guess or estimate beyond what is heard.
- Do not generate timestamps greater than the actual audio duration.
- Do not generate overlapping or decreasing timestamps.
- Ignore brief silence, filler speech, or introductory remarks unless they contain instructional content.

KEYWORDS RULES:
- Extract keywords that are explicitly mentioned or clearly implied in the audio.
- Keywords should include technologies, methods, concepts, tools, or frameworks.
- Keywords must be concise and relevant.
- Do not invent terms that are not present in the audio.

OUTPUT FORMAT RULES:
- Make sure to cover the audio from start to end - to have topics also from the later part of the video
- Output only raw JSON.
- Do not use markdown formatting.
- Do not include explanations, comments, or extra text.
- The output must be a valid JSON array.

OUTPUT EXAMPLE SCHEMA:
[
  {
    \"topic\": \"Clear, descriptive title of the topic\",
    \"timestamp_seconds\": 0,
    \"keywords\": [\"keyword1\", \"keyword2\", \"keyword3\"]
  }
]
  
OUTPUT ONLY IN WELL FORMATTED JSON OUTPUT";

$data = [
    "contents" => [
        [
            "parts" => [
                ["text" => $prompt],
                ["file_data" => [
                    "mime_type" => "audio/mp3",
                    "file_uri" => $fileUri
                ]]
            ]
        ]
    ],
    "generationConfig" => [
        "temperature" => 0.2,
        "topP" => 0.9,
        "maxOutputTokens" => 8192,
        "responseMimeType" => "application/json"
    ]
];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);

        // --- STEP 2: DEBUG THE RAW RESPONSE ---
        // If this prints an error JSON, you will finally know why!
        mtrace("DEBUG: Raw Gemini Response: " . substr($response, 0, 500) . "..."); 
        // --------------------------------------
        
        
        // --- DEBUGGING ---
        if (curl_errno($ch)) {
            mtrace("CURL ERROR (Sending Bytes): " . curl_error($ch));
        }
        // -----------------
        
        curl_close($ch);

        $result = json_decode($response);

        
        // Extract text
        $text = $result->candidates[0]->content->parts[0]->text ?? '';

        if (preg_match('/```json(.*?)```/s', $text, $matches)) {
            $text = trim($matches[1]);
        }

        return json_decode($text);
    }

    private function parse_timestamp($val) {
        if (is_numeric($val)) {
            return intval($val);
        }

        // 2. Handle "MM:SS" or "HH:MM:SS" format
        if (is_string($val) && strpos($val, ':') !== false) {
            $parts = explode(':', $val);
            $seconds = 0;
            
            // Reverse loop: Seconds -> Minutes -> Hours
            $parts = array_reverse($parts);
            foreach ($parts as $index => $part) {
                $seconds += intval($part) * pow(60, $index);
            }
            return $seconds;
        }

        return intval($val);
    }

    private function wait_for_file_active($fileUri, $apiKey) {
        mtrace("DEBUG: Checking file state for $fileUri");

        // 1. Extract the "files/ID" part from the URI
        // URI format: https://generativelanguage.googleapis.com/v1beta/files/abc12345
        $parts = explode('/files/', $fileUri);
        if (count($parts) < 2) {
            mtrace("ERROR: Could not parse file ID from URI.");
            return false;
        }
        $fileName = 'files/' . $parts[1]; // e.g. "files/abc12345"

        $url = "https://generativelanguage.googleapis.com/v1beta/$fileName?key=" . $apiKey;

        for ($i = 0; $i < 24; $i++) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $response = curl_exec($ch);
            curl_close($ch);

            $data = json_decode($response);
            
            // Check State
            $state = $data->state ?? 'UNKNOWN';
            mtrace("DEBUG: File State is: " . $state);

            if ($state === 'ACTIVE') {
                return true;
            }
            if ($state === 'FAILED') {
                mtrace("ERROR: File processing failed on Google side.");
                return false;
            }

            mtrace("Waiting for file to process...");
            sleep(5);
        }

        mtrace("ERROR: Timed out waiting for file to process.");
        return false;
    }

    private function get_video_duration($filepath) {
        // Build command to inspect file
        // 2>&1 redirects stderr to stdout so we can read the output
        $cmd = "ffmpeg -i " . escapeshellarg($filepath) . " 2>&1";
        exec($cmd, $output);
        
        $outputStr = implode("\n", $output);
        
        // Regex to find "Duration: 00:05:23.45"
        if (preg_match('/Duration: (\d{2}):(\d{2}):(\d{2})/', $outputStr, $matches)) {
            $hours = intval($matches[1]);
            $minutes = intval($matches[2]);
            $seconds = intval($matches[3]);
            return ($hours * 3600) + ($minutes * 60) + $seconds;
        }
        
        return 0; // Failed to get duration
    }

}