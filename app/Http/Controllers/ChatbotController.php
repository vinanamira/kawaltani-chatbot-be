<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Chatbot;
use App\Models\ChatSession;
use App\Models\ChatUser;
use App\Models\ChatResponse;
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

        // Cek session
        $session = ChatSession::where('user_id', $user->user_id)
            ->where('name_chat', $chatName)
            ->first();

        if (! $session) {
            // Buat session baru jika belum ada
            $session = ChatSession::create([
                'user_id' => $user->user_id,
                'name_chat' => $chatName,
            ]);
        }

        // Simpan pesan user
        $messageModel = ChatUser::create([
            'session_id' => $session->session_id,
            'message' => $message,
        ]);

        // Simpan response AI
        ChatResponse::create([
            'mess_id' => $messageModel->mess_id,
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

            $session = ChatSession::where('user_id', $user->user_id)
                ->where('name_chat', $nameChat)
                ->with(['messages.response']) // eager load semua pesan & balasannya
                ->first();

            if (!$session) {
                return response()->json(null, 204); // No Content
            }

            $data = $session->messages->map(function ($message) {
                return [
                    'message' => $message->message,
                    'response' => $message->response->response ?? null,
                    'sent_at' => $message->created_at,
                ];
            });

            return response()->json($data);
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
            return response()->json(['message' => 'Unauthenticated. Silakan login terlebih dahulu.'], 401);
        }

        try {
            $session = ChatSession::where('user_id', $user->user_id)
                ->where('name_chat', $nameChat)
                ->first();

            if (!$session) {
                return response()->json(['message' => 'Sesi chat tidak ditemukan.'], 404);
            }

            $session->delete(); // Akan otomatis hapus pesan & response karena pakai ON DELETE CASCADE

            return response()->json(['message' => 'Percakapan berhasil dihapus.']);
        } catch (\Exception $e) {
            Log::error("Delete Chat Error: " . $e->getMessage());
            return response()->json(['message' => 'Terjadi kesalahan saat menghapus sesi chat.'], 500);
        }
    }


    public function listChats(): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated. Silakan login terlebih dahulu.'
            ], 401);
        }

        try {
            $chats = ChatSession::where('user_id', $user->user_id)
                ->orderByDesc('updated_at')
                ->get(['session_id', 'name_chat', 'updated_at']); // hanya ambil kolom yang dibutuhkan

            return response()->json($chats);
        } catch (\Exception $e) {
            Log::error("ListChats Error: " . $e->getMessage());
            return response()->json([
                'message' => 'Gagal mengambil daftar chat.'
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
            return response()->json(['message' => 'Unauthenticated. Silakan login terlebih dahulu.'], 401);
        }

        try {
            // Cek apakah nama baru sudah dipakai oleh user yang sama
            $isExist = ChatSession::where('user_id', $user->user_id)
                ->where('name_chat', $request->newName)
                ->exists();

            if ($isExist) {
                return response()->json(['error' => 'Nama chat sudah digunakan oleh Anda'], 422);
            }

            // Ambil sesi yang akan diganti
            $session = ChatSession::where('user_id', $user->user_id)
                ->where('name_chat', $nameChat)
                ->first();

            if (!$session) {
                return response()->json(['message' => 'Sesi chat tidak ditemukan.'], 404);
            }

            $session->name_chat = $request->newName;
            $session->save();

            return response()->json(['message' => 'Nama chat berhasil diganti.']);
        } catch (\Exception $e) {
            Log::error("Rename Chat Error: " . $e->getMessage());
            return response()->json(['message' => 'Gagal mengganti nama sesi chat.'], 500);
        }
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
            // Kirim ke OpenAI
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

            // Buat session baru
            $session = ChatSession::create([
                'user_id'   => $userId,
                'name_chat' => $chatName,
            ]);

            // Simpan pesan user
            $messageModel = ChatUser::create([
                'session_id' => $session->session_id,
                'message'    => $message,
            ]);

            // Simpan balasan AI
            ChatResponse::create([
                'mess_id'  => $messageModel->mess_id,
                'response' => $reply,
            ]);

            return response()->json([
                'name_chat' => $chatName,
                'response'  => $reply,
            ]);
        } catch (\Exception $e) {
            Log::error("OpenAI Call Error in newChat: " . $e->getMessage());
            return response()->json([
                'message' => 'Maaf, KawalTani sedang mengalami masalah teknis. Silakan coba lagi nanti.'
            ], 500);
        }
    }
}