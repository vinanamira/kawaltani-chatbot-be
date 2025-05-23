<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Chatbot;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ChatbotController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        $request->validate([
            'message'    => 'nullable|string',
            'name_chat'  => 'nullable|string|max:64',
        ]);

        try {
            // Kirim ke OpenAI
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    ['role' => 'system', 'content' => 'Kamu adalah asisten pertanian...'],
                    ['role' => 'user', 'content' => $request->message],
                ],
            ]);

            $reply = $response['choices'][0]['message']['content'] ?? 'Tidak ada balasan.';

            // Ambil beberapa kata pertama dari pesan sebagai nama chat
            $generatedNameChat = Str::limit($request->message, 40, '...'); // UBAH INI MENJADI 40
            $nameChat = $request->name_chat ?? $generatedNameChat;

            Chatbot::create([
                'name_chat' => $nameChat,
                'message'   => $request->message,
                'response'  => $reply,
            ]);

            return response()->json([
                'name_chat' => $nameChat,
                'message'   => $request->message,
                'response'  => $reply,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Gagal menghubungi OpenAI: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function getHistoryByNameChat($nameChat): JsonResponse
    {
        $history = Chatbot::where('name_chat', $nameChat)
            ->orderBy('created_at')
            ->get();

        return response()->json($history);
    }

    public function deleteByNameChat($nameChat): JsonResponse
    {
        Chatbot::where('name_chat', $nameChat)->delete();

        return response()->json(['message' => 'Percakapan berhasil dihapus']);
    }

    public function listChats(): JsonResponse
    {
        $latestChats = Chatbot::selectRaw('MAX(id) as id, name_chat, MAX(created_at) as created_at')
            ->groupBy('name_chat')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($latestChats);
    }

    public function renameChat(Request $request, $nameChat): JsonResponse
    {
        $request->validate([
            'newName' => 'required|string|max:64',
        ]);

        // Pastikan nama baru belum dipakai
        if (Chatbot::where('name_chat', $request->newName)->exists()) {
            return response()->json(['error' => 'Nama chat sudah digunakan'], 422);
        }

        Chatbot::where('name_chat', $nameChat)->update(['name_chat' => $request->newName]);

        return response()->json(['message' => 'Nama chat berhasil diganti']);
    }

    public function newChat(Request $request): JsonResponse
    {
        $message = $request->input('message');

        // Jika tidak ada pesan, cukup kembalikan name_chat kosong (tanpa simpan apa pun)
        if (!$message || trim($message) === '') {
            return response()->json([
                'name_chat' => '', // Atau tetap 'percakapan-' . Str::uuid() jika Anda ingin nama sementara
                'response' => '',
            ]);
        }

        $chatName = Str::limit($message, 40, '...'); // UBAH INI MENJADI 40

        // Kirim ke OpenAI jika ada pesan
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
        ])->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                ['role' => 'system', 'content' => 'Kamu adalah asisten pertanian...'],
                ['role' => 'user', 'content' => $message],
            ],
        ]);

        $reply = $response['choices'][0]['message']['content'] ?? 'Tidak ada balasan.';

        Chatbot::create([
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
