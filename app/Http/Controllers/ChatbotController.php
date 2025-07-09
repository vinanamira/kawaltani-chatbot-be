<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Chatbot;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use App\Http\Controllers\ChatbotDataController;

class ChatbotController extends Controller
{
    public function send(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated. Silakan login terlebih dahulu.'
            ], 401);
        }

        $request->validate([
            'message' => 'required|string',
            'name_chat' => 'nullable|string',
        ]);

        $message = $request->input('message');
        $chatName = $request->name_chat ?? Str::limit($request->message, 40, '...');

        // Langsung kirim ke OpenAI
        $response = $this->sendGeneralMessageToOpenAI($message);

        // Simpan chat ke database
        Chatbot::create([
            'user_id' => $user->user_id,
            'name_chat' => $chatName,
            'message' => $message,
            'response' => $response,
        ]);

        return response()->json([
            'message' => $message,
            'response' => $response,
            'name_chat' => $chatName,
        ]);
    }


    private function sendGeneralMessageToOpenAI(string $message): string
    {
        try {
            $responseOpenAI = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
                'Content-Type' => 'application/json',
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'ft:gpt-3.5-turbo-0125:personal:kawaltani-v2:BgSwhzaU',
                'messages' => [
                    ['role' => 'system', 'content' => 'Kamu adalah asisten pertanian cerdas bernama KawalTani. Berikan informasi umum, tips, dan edukasi seputar pertanian. Jawablah dengan ramah dan informatif.'],
                    ['role' => 'user', 'content' => $message],
                ],
            ]);

            if ($responseOpenAI->successful()) {
                $body = $responseOpenAI->json();

                if (
                    isset($body['choices'][0]['message']['content']) &&
                    is_string($body['choices'][0]['message']['content'])
                ) {
                    return $body['choices'][0]['message']['content'];
                } else {
                    Log::warning("Struktur response OpenAI tidak sesuai. Isi response: " . json_encode($body));
                    return 'Maaf, saya tidak bisa memahami jawaban dari AI KawalTani.';
                }
            } else {
                Log::error("OpenAI API Error - Status: " . $responseOpenAI->status() . " | Body: " . $responseOpenAI->body());
                return 'Maaf, AI KawalTani tidak dapat merespon saat ini.';
            }
        } catch (\Exception $e) {
            Log::error("OpenAI Call Exception in ChatbotController: " . $e->getMessage());
            return 'Maaf, KawalTani sedang mengalami masalah teknis saat menghubungi AI.';
        }
    }


    public function getHistoryByNameChat($nameChat): JsonResponse
    {
        try {
            $user = Auth::user();
            if (! $user) {
                return response()->json([
                    'message' => 'Unauthenticated. Silakan login terlebih dahulu.'
                ], 401);
            }

            $history = Chatbot::where('user_id', $user->user_id)
                ->where('name_chat', $nameChat)
                ->orderBy('created_at')
                ->get();

            if ($history->isEmpty()) {
                return response()->json([], 204);
            }

            return response()->json($history);
        } catch (\Exception $e) {
            Log::error("Database Error on getHistoryByNameChat: " . $e->getMessage());
            return response()->json([
                'message' => 'Terjadi kesalahan saat mengambil riwayat chat.'
            ], 500);
        }
    }


    public function deleteByNameChat($nameChat): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated. Silakan login terlebih dahulu.'
            ], 401);
        }

        Chatbot::where('user_id', $user->user_id)
            ->where('name_chat', $nameChat)
            ->delete();

        return response()->json(['message' => 'Percakapan berhasil dihapus']);
    }

    public function listChats(): JsonResponse
    {
        try {
            $user = Auth::user();
            if (! $user) {
                return response()->json([
                    'message' => 'Unauthenticated. Silakan login terlebih dahulu.'
                ], 401);
            }

            $latestChats = Chatbot::selectRaw('MAX(id) as id, name_chat, MAX(created_at) as created_at')
                ->where('user_id', $user->user_id)
                ->groupBy('name_chat')
                ->orderByDesc('created_at')
                ->get();

            return response()->json($latestChats);
        } catch (\Exception $e) {
            Log::error("Database KawalTani Chatbot Error: " . $e->getMessage() . " in " . $e->getFile() . " line " . $e->getLine());
            return response()->json([
                'message' => 'Terjadi kesalahan saat mengambil riwayat chat. Silakan coba lagi.'
            ], 500);
        }
    }

    public function renameChat(Request $request, $nameChat): JsonResponse
    {
        $request->validate([
            'newName' => 'required|string|max:64',
        ]);

        $user = Auth::user();
        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated. Silakan login terlebih dahulu.'
            ], 401);
        }

        if (Chatbot::where('user_id', $user->user_id)->where('name_chat', $request->newName)->exists()) {
            return response()->json(['error' => 'Nama chat sudah digunakan oleh Anda'], 422);
        }

        Chatbot::where('user_id', $user->user_id)
            ->where('name_chat', $nameChat)
            ->update(['name_chat' => $request->newName]);

        return response()->json(['message' => 'Nama chat berhasil diganti']);
    }

    public function newChat(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated. Silakan login terlebih dahulu.'
            ], 401);
        }

        $message = $request->input('message');
        $userId = $user->user_id;

        if (!$message || trim($message) === '') {
            return response()->json([
                'name_chat' => '',
                'response' => '',
            ]);
        }

        $chatName = Str::limit($message, 40, '...');

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'ft:gpt-3.5-turbo-0125:personal:kawaltani-v2:BgSwhzaU',
                'messages' => [
                    ['role' => 'system', 'content' => 'Kamu adalah asisten pertanian cerdas bernama KawalTani. Berikan informasi umum, tips, dan edukasi seputar pertanian. Jawablah dengan ramah dan informatif.'],
                    ['role' => 'user', 'content' => $message],
                ],
            ]);

            $reply = $response['choices'][0]['message']['content'] ?? 'Tidak ada balasan.';
        } catch (\Exception $e) {
            Log::error("OpenAI Call Error in newChat: " . $e->getMessage());
            $reply = 'Maaf, saya KawalTani sedang mengalami masalah untuk menghubungi AI utama. Silakan coba lagi nanti.';
        }

        Chatbot::create([
            'user_id'   => $userId,
            'name_chat' => $chatName,
            'message'   => $message,
            'response'  => $reply,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'name_chat' => $chatName,
            'response'  => $reply,
        ]);
    }
}
