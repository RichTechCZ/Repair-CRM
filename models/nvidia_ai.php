<?php
/**
 * NVIDIA AI Integration Model
 * API endpoint: https://integrate.api.nvidia.com/v1/chat/completions
 * Model: moonshotai/kimi-k2.5
 */

class NVIDIAModel {
    private $invokeUrl = "https://integrate.api.nvidia.com/v1/chat/completions";
    private $apiKey;
    private $model = "moonshotai/kimi-k2.5";
    private $stream = true;
    
    public function __construct($apiKey = null) {
        $this->apiKey = $apiKey ?? getenv('NVIDIA_API_KEY') ?: '';
        $this->headers = [
            "Authorization" => "Bearer " . $this->apiKey,
            "Accept" => $this->stream ? "text/event-stream" : "application/json"
        ];
    }
    
    /**
     * Send message to NVIDIA AI model
     */
    public function sendMessage($message, $context = []) {
        if (empty($this->apiKey)) {
            throw new Exception("NVIDIA_API_KEY is not configured");
        }
        $payload = [
            "model" => $this->model,
            "messages" => [
                [
                    "role" => "user",
                    "content" => $message
                ]
            ],
            "max_tokens" => 16384,
            "temperature" => 1.00,
            "top_p" => 1.00,
            "stream" => $this->stream,
            "chat_template_kwargs" => [
                "thinking" => true
            ]
        ];
        
        // Add context if provided
        if (!empty($context)) {
            $payload["messages"][] = [
                "role" => "system",
                "content" => $context
            ];
        }
        
        try {
            $ch = curl_init($this->invokeUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type: application/json",
                "Authorization: Bearer " . $this->apiKey,
                "Accept: " . ($this->stream ? "text/event-stream" : "application/json")
            ]);
            
            if ($this->stream) {
                curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) {
                    echo $data;
                    return strlen($data);
                });
            }
            
            $response = curl_exec($ch);
            
            if (curl_errno($ch)) {
                throw new Exception("cURL Error: " . curl_error($ch));
            }
            
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($this->stream) {
                // For streaming, response is already handled by callback
                return true;
            } else {
                $result = json_decode($response, true);
                if (isset($result['choices'][0]['message']['content'])) {
                    return $result['choices'][0]['message']['content'];
                } else {
                    throw new Exception("Invalid response format");
                }
            }
            
        } catch (Exception $e) {
            error_log("NVIDIA API Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Synchronous call (non-streaming)
     */
    public function sendSyncMessage($message, $context = []) {
        $this->stream = false;
        return $this->sendMessage($message, $context);
    }
    
    /**
     * Asynchronous streaming call
     */
    public function sendAsyncMessage($message, $context = []) {
        $this->stream = true;
        return $this->sendMessage($message, $context);
    }
    
    /**
     * Get model information
     */
    public function getModelInfo() {
        return [
            "model" => $this->model,
            "api_url" => $this->invokeUrl,
            "streaming" => $this->stream,
            "max_tokens" => 16384
        ];
    }
}
?>
