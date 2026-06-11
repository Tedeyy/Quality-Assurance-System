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

            $prompt = "You are a strict Quality Assurance analyst tasked with summarizing open-text respondent feedback from an institutional activity.

DATA:
" . json_encode($responses) . "

STRICT RULES:
1. Only extract REAL, SPECIFIC complaints — things that respondents explicitly described as problems, issues, dissatisfactions, or negative experiences. DO NOT invent complaints.
2. Only extract REAL, SPECIFIC suggestions for improvement — actionable ideas that respondents explicitly proposed. DO NOT invent suggestions. 
3. Vague or generic praise (e.g. 'all good', 'nothing to improve', 'N/A', 'none', empty strings) must be treated as NO complaint and NO suggestion.
4. If there are genuinely no complaints found in the data, return null for 'complaints'. Do NOT write phrases like 'None reported' or 'No complaints'.
5. If there are genuinely no suggestions found in the data, return null for 'suggestions'. Do NOT write phrases like 'None reported' or 'No suggestions'.
6. Group and summarize by theme when multiple respondents raise the same issue. Be concise and professional.
7. Return ONLY valid JSON — no markdown, no extra text, no code fences.

OUTPUT FORMAT (strict JSON only):
{
    \"complaints\": \"Grouped summary of real complaints, or null if none\",
    \"suggestions\": \"Grouped summary of real suggestions, or null if none\"
}";

            $response = $this->callGemini($prompt);
            
            // Extract JSON — strip possible markdown code fences
            $cleaned = preg_replace('/```(?:json)?\s*/i', '', $response);
            $cleaned = preg_replace('/```/', '', $cleaned);
            if (preg_match('/(\{.*\})/s', $cleaned, $matches)) {
                $json = json_decode($matches[1], true);
                if (is_array($json)) return $json;
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
