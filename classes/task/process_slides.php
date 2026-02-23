<?php
namespace mod_smartvideo\task;

defined('MOODLE_INTERNAL') || die();

class process_slides extends \core\task\adhoc_task {

    public function execute() {
        global $DB;

        // 1. Get the Data passed from lib.php
        $data = $this->get_custom_data();
        $instanceId = $data->instanceId;

        mtrace("Starting AI processing for SmartVideo SLIDES (ID: $instanceId)");

        // 2. Get the API Key
        $apiKey = get_config('mod_smartvideo', 'apikey');
        if (empty($apiKey)) {
            mtrace("Error: No API Key found.");
            return;
        }

        // 3. Find the PDF File
        $fs = get_file_storage();
        
        // Retrieve the context based on the instance ID
        $cm = get_coursemodule_from_instance('smartvideo', $instanceId);
        $context = \context_module::instance($cm->id);
        
        // Look specifically in the 'slides' file area
        $files = $fs->get_area_files($context->id, 'mod_smartvideo', 'slides', 0, 'sortorder DESC, id ASC', false);
        
        if (empty($files)) {
            mtrace("Error: No PDF slides found in file area.");
            return;
        }
        
        $file = reset($files); // Get the first file
        
        // 4. Prepare File for Upload
        // We copy it to a temp path to ensure we have a clean local path for cURL
        $tempdir = make_request_directory();
        $inputPdf = $tempdir . '/input_slides.pdf';
        $file->copy_content_to($inputPdf);

        mtrace("PDF copied to temp storage. Uploading to Gemini...");

        // 5. Upload to Gemini
        // We use application/pdf mime type
        $fileUri = $this->upload_to_gemini($inputPdf, 'application/pdf', $apiKey);
        
        if (!$fileUri) {
            mtrace("Failed to upload PDF to Gemini.");
            @unlink($inputPdf);
            return;
        }
        mtrace("PDF uploaded. URI: " . $fileUri);

        // 6. Analyze and Generate Summary
        // We wait briefly before asking for content
        sleep(5); 
        $summaryHtml = $this->analyze_slides($fileUri, $apiKey);

        if ($summaryHtml) {
            // 7. Save to Database
            $update = new \stdClass();
            $update->id = $instanceId;
            $update->summary = $summaryHtml;
            $update->summaryformat = FORMAT_HTML; // Ensure Moodle knows this is HTML
            $update->timemodified = time();
            
            $DB->update_record('smartvideo', $update);
            
            mtrace("Success: Summary saved to database.");
        } else {
            mtrace("Error: No summary generated.");
        }

        // Cleanup
        @unlink($inputPdf);
    }

    /**
     * Sends the prompt to Gemini to summarize the slides
     */
    private function analyze_slides($fileUri, $key) {
        
        mtrace("DEBUG: Waiting for file to become active...");
        // Wait for PDF to be processed by Google (Status=ACTIVE)
        if (!$this->wait_for_file_active($fileUri, $key)) {
            mtrace("ERROR: File never became ACTIVE.");
            return false;
        }

        // 1. CHECK MODEL NAME
        // Try 'gemini-1.5-flash' first. If that fails, we can try 'gemini-1.5-pro'
        $model = 'gemini-2.5-flash'; 
        $url = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=" . $key;

        mtrace("DEBUG: Generating content using model: $model");

        $prompt = "You are an expert academic tutor. 
        Your task is to analyze the attached lecture slides PDF and create a comprehensive study summary for students.

        OUTPUT FORMATTING RULES:
        1. Return the result in clean, semantic HTML format (no markdown blocks).
        2. Use <h3> for main section headers.
        3. Use <p> for paragraphs.
        4. Use <ul> and <li> for bullet points.
        5. Use <strong> for key terminology.

        CONTENT RULES:
        1. Start with a 'Video Overview' paragraph summarizing the main theme.
        2. Break down the content into 'Key Concepts' or 'Lecture Sections'.
        3. Capture the most important definitions and formulas if present.
        ";

        $data = [
            "contents" => [
                [
                    "parts" => [
                        ["text" => $prompt],
                        ["file_data" => [
                            "mime_type" => "application/pdf",
                            "file_uri" => $fileUri
                        ]]
                    ]
                ]
            ],
            "generationConfig" => [
                "temperature" => 0.3,
                "maxOutputTokens" => 8192
            ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120); // Give it 2 minutes

        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            mtrace("CURL ERROR (Generation): " . curl_error($ch));
            curl_close($ch);
            return false;
        }
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // --- NEW DEBUGGING BLOCK ---
        if ($httpCode >= 400) {
            mtrace("API ERROR ($httpCode): " . $response);
            return false;
        }

        $result = json_decode($response);
        
        // Check if we have a valid candidate
        if (empty($result->candidates)) {
            mtrace("ERROR: No candidates returned. Raw Response: " . substr($response, 0, 500));
            // Check for prompt feedback (safety filters)
            if (isset($result->promptFeedback)) {
                mtrace("Prompt Feedback: " . json_encode($result->promptFeedback));
            }
            return false;
        }

        // Extract text
        $text = $result->candidates[0]->content->parts[0]->text ?? '';

        if (empty($text)) {
            mtrace("ERROR: Candidate found but text was empty.");
            return false;
        }

        // Strip markdown code blocks if Gemini adds them
        if (preg_match('/```html(.*?)```/s', $text, $matches)) {
            $text = trim($matches[1]);
        } elseif (preg_match('/```(.*?)```/s', $text, $matches)) {
             $text = trim($matches[1]);
        }

        mtrace("DEBUG: Summary generated successfully (" . strlen($text) . " chars).");
        return $text;
    }
    // --- REUSED HELPER FUNCTIONS ---

private function upload_to_gemini($filepath, $mime, $key) {
        $filesize = filesize($filepath);
        $url = "https://generativelanguage.googleapis.com/upload/v1beta/files?key=" . $key;
        
        // Metadata for the file
        $metadata = json_encode(['file' => ['display_name' => 'moodle_slides_pdf']]);

        // 1. HANDSHAKE (Start Resumable Session)
        $headers = [
            'X-Goog-Upload-Protocol: resumable',
            'X-Goog-Upload-Command: start',
            'X-Goog-Upload-Header-Content-Length: ' . $filesize,
            'X-Goog-Upload-Header-Content-Type: ' . $mime,
            'Content-Type: application/json',
            'Content-Length: ' . strlen($metadata)
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $metadata);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_HEADER, true); // Include headers in output
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        
        // Check for basic connection errors
        if ($response === false) {
            mtrace("CURL CONNECTION ERROR: " . curl_error($ch));
            curl_close($ch);
            return false;
        }

        // Check HTTP Status Code
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header_text = substr($response, 0, $header_size);
        $body = substr($response, $header_size); // The actual error message from Google

        curl_close($ch);

        // If Google says "No" (400, 403, 500, etc.)
        if ($httpCode >= 400) {
            mtrace("API HANDSHAKE FAILED (HTTP $httpCode)");
            mtrace("Response Body: " . $body); // <--- THIS WILL SHOW YOU THE REAL ERROR
            return false;
        }

        // Try to find the Upload URL
        if (!preg_match('/x-goog-upload-url: (.*)/i', $header_text, $matches)) {
            mtrace("ERROR: HTTP 200 OK, but 'x-goog-upload-url' header is missing.");
            mtrace("Headers received: " . $header_text);
            return false;
        }
        
        $uploadUrl = trim($matches[1]);
        mtrace("Handshake successful. URL obtained.");

        // 2. UPLOAD BYTES
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
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300); // Wait up to 5 mins for upload

        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            mtrace("CURL UPLOAD ERROR: " . curl_error($ch));
            curl_close($ch);
            fclose($fp);
            return false;
        }
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if ($httpCode >= 400) {
            mtrace("UPLOAD FAILED (HTTP $httpCode): " . $response);
            return false;
        }

        $json = json_decode($response);
        return $json->file->uri ?? false;
    }

    private function wait_for_file_active($fileUri, $apiKey) {
        // Extract ID from URI
        $parts = explode('/files/', $fileUri);
        if (count($parts) < 2) {
             mtrace("ERROR: Invalid File URI format: $fileUri");
             return false;
        }
        
        $fileName = 'files/' . $parts[1];
        $url = "https://generativelanguage.googleapis.com/v1beta/$fileName?key=" . $apiKey;

        mtrace("DEBUG: Polling file status: $fileName");

        // INCREASE TIMEOUT: Check every 5 seconds for up to 5 minutes (60 checks)
        for ($i = 0; $i < 60; $i++) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $response = curl_exec($ch);
            
            if (curl_errno($ch)) {
                mtrace("CURL ERROR (Polling): " . curl_error($ch));
                curl_close($ch);
                sleep(5);
                continue;
            }
            curl_close($ch);

            $data = json_decode($response);
            
            // 1. Check for API Errors (e.g. 404, 403)
            if (isset($data->error)) {
                 mtrace("API ERROR during polling: " . json_encode($data->error));
                 return false;
            }

            $state = $data->state ?? 'UNKNOWN';
            
            // 2. Logging to help you see progress
            mtrace("DEBUG: Check " . ($i+1) . "/60 - State: " . $state);

            if ($state === 'ACTIVE') {
                mtrace("SUCCESS: File is ready.");
                return true;
            }
            
            if ($state === 'FAILED') {
                mtrace("ERROR: File processing failed.");
                return false;
            }

            // Wait 5 seconds before next check
            sleep(5);
        }

        mtrace("ERROR: Timed out (5 minutes) waiting for file to become ACTIVE.");
        return false;
    }
}