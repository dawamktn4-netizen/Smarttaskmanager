<?php
// Copy to chat.local.php. Ollama runs locally and needs no API key.
return [
    'provider' => 'ollama', // ollama, gemini or openai
    'api_key' => '', // Only needed for a cloud provider
    'model' => 'qwen3:4b', // Gemini: gemini-2.5-flash; OpenAI: gpt-4.1-mini
];
