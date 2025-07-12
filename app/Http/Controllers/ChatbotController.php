<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use App\Models\ChatSession;
use App\Models\ChatUser;
use App\Models\ChatResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

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

        if ($this->isDataQuery($message)) {
            return $this->handleDataQuery($message, $chatName, $user);
        }

        $response = $this->sendGeneralMessageToOpenAI($message);

        $session = ChatSession::where('user_id', $user->user_id)
            ->where('name_chat', $chatName)
            ->first();

        if (! $session) {
            $session = ChatSession::create([
                'user_id' => $user->user_id,
                'name_chat' => $chatName,
            ]);
        }

        $messageModel = ChatUser::create([
            'session_id' => $session->session_id,
            'message' => $message,
        ]);

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
                    ['role' => 'system', 'content' => 'Kamu adalah asisten pertanian cerdas bernama KawalTani. Jawablah hanya pertanyaan yang berkaitan dengan pertanian, lahan, cuaca, kondisi tanaman, atau data dari sensor. Jika pertanyaannya di luar topik pertanian, tolak dengan sopan dan katakan bahwa kamu hanya melayani pertanyaan seputar pertanian.'],
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

    private function isDataQuery(string $message): bool
    {
        $keywords = [
            'suhu',
            'kelembaban',
            'ph tanah',
            'kondisi lahan',
            'kondisi tanaman',
            'cuaca',
            'ringkasan',
            'hari ini',
            'kemarin',
            'berapa derajat',
            'berapa kelembaban',
            'berapa ph',
            'kadar air',
            'sensor',
            'data lahan',
            'hujan',
            'kondisi sekarang',
            'kondisi saat ini',
            'nilai tertinggi',
            'nilai terendah',
            'siram',
            'butuh air',
            'kondisi sawah'
        ];

        $lowerMessage = strtolower($message);

        foreach ($keywords as $keyword) {
            if (str_contains($lowerMessage, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function handleDataQuery(string $message, string $chatName, $user): JsonResponse
    {
        try {
            $targetDate = $this->extractDateFromMessage($message) ?? Carbon::now()->toDateString();

            Log::info("🔍 Permintaan data untuk tanggal: $targetDate oleh user {$user->user_id}");

            $sensorData = \App\Http\Controllers\Riwayat2Controller::getSensorData($user->user_id);

            if (empty($sensorData)) {
                return response()->json([
                    'message' => 'Gagal mengambil data sensor.'
                ], 500);
            }

            $filtered = collect($sensorData)->filter(function ($item) use ($targetDate) {
                return $item['tanggal'] === $targetDate;
            });

            if ($filtered->isEmpty()) {
                Log::info("📭 Tidak ada data sensor untuk tanggal $targetDate");
                return response()->json([
                    'message' => 'Data sensor tidak ditemukan untuk tanggal tersebut.'
                ], 200);
            }

            $excludedKeywords = ['battery', 'battrery', 'solar', 'load voltage', 'current', 'panel'];
            $filtered = $filtered->filter(function ($item) use ($excludedKeywords) {
                foreach ($excludedKeywords as $keyword) {
                    if (Str::contains(Str::lower($item['sensor']), $keyword)) {
                        return false;
                    }
                }
                return true;
            });

            $grouped = $filtered->groupBy('sensor')->map(function ($items, $sensorName) {
                $avg = collect($items)->avg('nilai');
                return [
                    'sensor' => $sensorName,
                    'rata_rata' => round($avg, 2),
                ];
            })->values();

            $summaryPrompt = "Berikut adalah data rata-rata sensor lahan pada tanggal $targetDate:\n";
            foreach ($grouped as $sensorInfo) {
                $summaryPrompt .= "- {$sensorInfo['sensor']}: {$sensorInfo['rata_rata']}\n";
            }
            $summaryPrompt .= "\nTolong buatkan ringkasan kondisi lahan dari data tersebut dalam bentuk paragraf ringkas, ramah, dan mudah dipahami petani.";

            $formattedResponse = $this->sendGeneralMessageToOpenAI($summaryPrompt);

            $session = ChatSession::firstOrCreate([
                'user_id' => $user->user_id,
                'name_chat' => $chatName,
            ]);

            $chatUser = ChatUser::create([
                'session_id' => $session->session_id,
                'message' => $message,
            ]);

            ChatResponse::create([
                'mess_id' => $chatUser->mess_id,
                'response' => $formattedResponse,
            ]);

            return response()->json([
                'message' => $message,
                'response' => $formattedResponse,
                'name_chat' => $chatName,
            ]);
        } catch (\Throwable $e) {
            Log::error("handleDataQuery Error: " . $e->getMessage());
            return response()->json([
                'message' => 'Maaf, sistem mengalami masalah saat memproses pertanyaan berbasis data.'
            ], 500);
        }
    }

    private function extractDateFromMessage(string $message): ?string
    {
        $message = strtolower($message);
        $today = now();

        if (str_contains($message, 'hari ini')) {
            return $today->toDateString(); // format Y-m-d
        } elseif (str_contains($message, 'kemarin')) {
            return $today->subDay()->toDateString();
        }

        $bulanMap = [
            'januari' => '01',
            'februari' => '02',
            'maret' => '03',
            'april' => '04',
            'mei' => '05',
            'juni' => '06',
            'juli' => '07',
            'agustus' => '08',
            'september' => '09',
            'oktober' => '10',
            'november' => '11',
            'desember' => '12',
        ];

        // Format lengkap: Contoh "19 Mei 2025"
        foreach ($bulanMap as $bulanText => $bulanAngka) {
            if (preg_match("/(\d{1,2})\s+$bulanText\s+(\d{4})/", $message, $match)) {
                $day = str_pad($match[1], 2, '0', STR_PAD_LEFT);
                $year = $match[2];
                return "$year-$bulanAngka-$day";
            }
        }

        // Format tanpa tahun: Contoh "19 Mei"
        foreach ($bulanMap as $bulanText => $bulanAngka) {
            if (preg_match("/(\d{1,2})\s+$bulanText/", $message, $match)) {
                $day = str_pad($match[1], 2, '0', STR_PAD_LEFT);
                $year = $today->year;
                return "$year-$bulanAngka-$day";
            }
        }

        // Format angka: "12/07/2024" atau "12-07-2024"
        if (preg_match("/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/", $message, $match)) {
            $day = str_pad($match[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($match[2], 2, '0', STR_PAD_LEFT);
            $year = $match[3];
            return "$year-$month-$day";
        }

        // Format angka: "12/07" atau "12-07" tanpa tahun
        if (preg_match("/(\d{1,2})[\/\-](\d{1,2})/", $message, $match)) {
            $day = str_pad($match[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($match[2], 2, '0', STR_PAD_LEFT);
            $year = $today->year;
            return "$year-$month-$day";
        }

        return null;
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
            $isExist = ChatSession::where('user_id', $user->user_id)
                ->where('name_chat', $request->newName)
                ->exists();

            if ($isExist) {
                return response()->json(['error' => 'Nama chat sudah digunakan oleh Anda'], 422);
            }

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
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'ft:gpt-3.5-turbo-0125:personal:kawaltani-v2:BgSwhzaU',
                'messages' => [
                    ['role' => 'system', 'content' => 'Kamu adalah asisten pertanian cerdas bernama KawalTani. Jawablah hanya pertanyaan yang berkaitan dengan pertanian, lahan, cuaca, kondisi tanaman, atau data dari sensor. Jika pertanyaannya di luar topik pertanian, tolak dengan sopan dan katakan bahwa kamu hanya melayani pertanyaan seputar pertanian.'],
                    ['role' => 'user', 'content' => $message],
                ],
            ]);

            $reply = $response['choices'][0]['message']['content'] ?? 'Tidak ada balasan.';

            $session = ChatSession::create([
                'user_id'   => $userId,
                'name_chat' => $chatName,
            ]);

            $messageModel = ChatUser::create([
                'session_id' => $session->session_id,
                'message'    => $message,
            ]);

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
