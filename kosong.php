<?php
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

try {
    $response = Http::withHeaders([
        'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
    ])->post('https://api.openai.com/v1/chat/completions', [
        'model' => 'ft:gpt-3.5-turbo-0125:personal:kawaltani-v2:BgSwhzaU',
        'messages' => [
            ['role' => 'system', 'content' => 'Kamu adalah asisten pertanian cerdas bernama KawalTani. 
            Berikan informasi umum, tips, dan edukasi seputar pertanian. Jawablah dengan ramah dan 
            informatif.'],
            ['role' => 'user', 'content' => $message],
        ],
    ]);

    $reply = $response['choices'][0]['message']['content'] ?? 'Tidak ada balasan.';
} catch (\Exception $e) {
    Log::error("OpenAI Call Error in newChat: " . $e->getMessage());
    $reply = 'Maaf, saya KawalTani sedang mengalami masalah untuk menghubungi AI utama. Silakan coba lagi nanti.';
}