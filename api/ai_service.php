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

    public function interpretFeedback($responses) {
        try {
            if (!$this->apiKey) {
                return ["error" => "Gemini API Key not configured in .env"];
            }

            $prompt = "You are a Quality Assurance analyst. Analyze the following respondent feedback from an institutional activity and provide a summarized interpretation.

            DATA:
            " . json_encode($responses) . "

            INSTRUCTIONS:
            1. Extract common themes for 'Complaints' (things that went wrong or were disliked).
            2. Extract 'Suggestions for Improvement' (actionable feedback).
            3. Return the results in a valid JSON format with keys: 'complaints' and 'suggestions'.
            4. Keep descriptions professional, concise, and grouped by theme. 
            5. If there are no complaints or suggestions, state 'None reported'.

            OUTPUT FORMAT:
            {
                \"complaints\": \"Summary text here...\",
                \"suggestions\": \"Summary text here...\"
            }";

            $response = $this->callGemini($prompt);
            
            // Extract JSON if AI wraps it in markdown blocks
            if (preg_match('/\{.*\}/s', $response, $matches)) {
                $json = json_decode($matches[0], true);
                if ($json) return $json;
            }

            return ["error" => "Failed to parse AI response: " . $response];
        } catch (Exception $e) {
            return ["error" => "AI Analysis failed: " . $e->getMessage()];
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
