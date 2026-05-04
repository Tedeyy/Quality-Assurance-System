<?php
require_once __DIR__ . '/../vendor/autoload.php';

class AIService {
    private $apiKey;
    private $model = "gemini-pro"; 

    public function __construct() {
        $this->apiKey = $this->loadEnvKey('GEMINI_API_KEY');
    }

    protected function loadEnvKey($key) {
        $envPath = __DIR__ . '/../.env';
        if (!file_exists($envPath)) return null;
        
        $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) return null;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) continue;
            if (strpos($line, '=') === false) continue;
            
            list($name, $value) = explode('=', $line, 2);
            if (trim($name) === $key) {
                return trim($value, " \t\n\r\0\x0B\"'");
            }
        }
        return null;
    }

    public function analyzeStructure($structure) {
        try {
            if (!$this->apiKey) {
                return "AI Assistant is currently offline (Gemini API Key not configured in .env).";
            }

            $prompt = "You are an AACCUP assistant. Analyze these scan results: " . json_encode($structure) . ". 
            Provide a concise analysis including:
            1. Template Authenticity: Does it look like a standard AACCUP template?
            2. Completeness: Are any common Areas or Parameters missing?
            3. Observations: Any weirdly named requirements or orphans?
            Format your response in simple HTML (bullet points, bold text). Keep it brief.";
            return $this->callGemini($prompt);
        } catch (Exception $e) {
            return "AI Analysis failed: " . $e->getMessage();
        }
    }

    private function callGemini($prompt) {
        // Use gemini-flash-latest as verified in the model list
        $model = "gemini-flash-latest";
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$this->apiKey}";

        $data = ["contents" => [["parts" => [["text" => $prompt]]]]];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return "AI Assistant unavailable (Status {$httpCode}).";
        }

        $result = json_decode($response, true);
        return $result['candidates'][0]['content']['parts'][0]['text'] ?? "Analysis unavailable.";
    }
}
