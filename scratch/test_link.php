<?php
function getGoogleDriveDirectLink($url) {
    if (empty($url)) return "EMPTY";
    
    // Extract ID from various Google Drive URL formats
    if (preg_match('/(?:id=|\/d\/|folders\/|file\/d\/)([a-zA-Z0-9_-]+)/', $url, $matches)) {
        $id = $matches[1];
        return "https://lh3.googleusercontent.com/d/" . $id;
    }
    
    return "NO MATCH: " . $url;
}

$test_url = "https://drive.google.com/file/d/17bhngnyF93eEpegTn9dKrXCiRH-vHIzd/view?usp=sharing";
echo getGoogleDriveDirectLink($test_url);
