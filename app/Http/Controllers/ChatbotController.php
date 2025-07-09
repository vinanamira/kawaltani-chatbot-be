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

        if ($this->isDataQuery($message)) {
            return $this->handleDataQuery($message, $chatName, $user);
        }

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
            // 🔍 Ekstraksi tanggal dari pesan (jika ada)
            $date = $this->extractDateFromMessage($message);
            Log::info("📅 Tanggal yang diekstrak dari pesan: " . ($date ?? 'tidak ada'));

            // Ambil data sensor dari Riwayat2Controller
            $sensorData = \App\Http\Controllers\Riwayat2Controller::getSensorData($user->user_id, $date);

            if (empty($sensorData)) {
                return response()->json([
                    'message' => 'Data sensor tidak ditemukan untuk waktu yang dimaksud.'
                ], 204); // Sesuai whitebox: No Content
            }

            // Mapping keyword -> ds_param
            $keywordMap = [
                'soil_temp'  => ['suhu', 'panas', 'derajat'],
                'soil_hum'   => ['lembab', 'kelembaban', 'kelembapan'],
                'soil_ph'    => ['ph', 'keasaman', 'asam'],
                'soil_nitro' => ['nitrogen'],
                'soil_phos'  => ['fosfor'],
                'soil_pot'   => ['kalium'],
                'soil_con'   => ['ec', 'konduktivitas'],
                'soil_tds'   => ['tds'],
                'soil_salin' => ['salinitas'],
            ];

            $foundParam = null;
            foreach ($keywordMap as $param => $aliases) {
                foreach ($aliases as $alias) {
                    if (Str::contains(Str::lower($message), $alias)) {
                        $foundParam = $param;
                        break 2;
                    }
                }
            }

            

            // Cek jika pertanyaan minta ringkasan seluruh kondisi
            if (Str::contains(Str::lower($message), 'kondisi lahan') || Str::contains(Str::lower($message), 'kondisi sawah')) {
                $summary = collect($sensorData)->map(function ($data) {
                    return "- " . $this->paramToLabel($data['sensor']) . ": {$data['nilai']}";
                })->implode("\n");

                $tanggal = $sensorData[0]['tanggal'] ?? ($date ?? 'hari ini');
                $formattedResponse = "Berikut ringkasan kondisi lahan pada tanggal $tanggal:\n" . $summary;

                // Simpan ke DB
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
            }

            // Temukan data sensor dari array hasil getSensorData()
            $foundData = collect($sensorData)->firstWhere('sensor', $foundParam);

            if (!$foundData) {
                return response()->json([
                    'message' => 'Data sensor tidak ditemukan untuk waktu tersebut.'
                ], 204);
            }

            $formattedResponse = "Baik, nilai rata-rata untuk " . $this->paramToLabel($foundParam) .
                " pada tanggal {$foundData['tanggal']} pukul {$foundData['waktu']} adalah {$foundData['nilai']}.";

            // Simpan percakapan
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

    // Untuk menjadikan label yang lebih natural
    private function paramToLabel(string $param): string
    {
        $labels = [
            'soil_temp' => 'suhu tanah',
            'soil_hum' => 'kelembaban tanah',
            'soil_ph' => 'pH tanah',
            'soil_nitro' => 'kandungan nitrogen',
            'soil_phos' => 'kandungan fosfor',
            'soil_pot' => 'kandungan kalium',
            'soil_con' => 'EC (konduktivitas listrik)',
            'soil_tds' => 'TDS',
            'soil_salin' => 'salinitas tanah',
        ];

        return $labels[$param] ?? $param;
    }

    private function extractDateFromMessage(string $message): ?string
    {
        $message = strtolower($message);
        $today = now();

        // Deteksi kata relatif
        if (str_contains($message, 'hari ini')) {
            return $today->toDateString(); // format Y-m-d
        } elseif (str_contains($message, 'kemarin')) {
            return $today->subDay()->toDateString();
        }

        // Coba cari pola tanggal eksplisit
        // Pola: 2 Juli, 02 Juli, 2/07, 02/07, dll
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

        // Contoh cocokkan "2 juli" atau "02 juli"
        foreach ($bulanMap as $bulanText => $bulanAngka) {
            if (preg_match("/(\d{1,2})\s+$bulanText/", $message, $match)) {
                $day = str_pad($match[1], 2, '0', STR_PAD_LEFT);
                $year = now()->year;
                return "$year-$bulanAngka-$day";
            }
        }

        // Contoh cocokkan "2/7" atau "02/07"
        if (preg_match("/(\d{1,2})[\/\-](\d{1,2})/", $message, $match)) {
            $day = str_pad($match[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($match[2], 2, '0', STR_PAD_LEFT);
            $year = now()->year;
            return "$year-$month-$day";
        }

        return null; // Tidak ditemukan
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
