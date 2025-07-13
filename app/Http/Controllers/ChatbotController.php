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
            'kondisi sawah',
            'data'
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
            $requestedDates = $this->extractAllDatesFromMessage($message);
            Log::info("📅 Tanggal yang terdeteksi:", $requestedDates);

            $masterPromptData = [];

            foreach ($requestedDates as $targetDate) {
                $allSensorDataForDate = \App\Http\Controllers\Riwayat2Controller::getSensorData($user->user_id, $targetDate);

                if ($allSensorDataForDate->isEmpty()) {
                    Log::info("📭 Tidak ada data untuk tanggal $targetDate, melanjutkan ke tanggal berikutnya.");
                    continue; 
                }

                $excludedKeywords = ['battery', 'battrery', 'solar', 'load voltage', 'current', 'panel'];
                $filteredData = $allSensorDataForDate->filter(function ($item) use ($excludedKeywords) {
                    foreach ($excludedKeywords as $keyword) {
                        if (Str::contains(Str::lower($item['sensor']), $keyword)) return false;
                    }
                    return true;
                });

                $requestedPairs = $this->parseSensorAreaPairs($message);
                $dataForThisDate = collect();

                if (!empty($requestedPairs)) {
                    foreach ($requestedPairs as $pair) {
                        [$sensorKeyword, $areaNumber] = $pair;
                        $foundItem = $filteredData->first(function ($item) use ($sensorKeyword, $areaNumber) {
                            $sensorName = Str::lower($item['sensor']);
                            $areaMatch = Str::contains($sensorName, 'area ' . $areaNumber);
                            $pattern = preg_quote($sensorKeyword, '/');
                            if ($sensorKeyword === 'temp soil') $pattern = 'temp\.? ?soil';
                            $sensorMatch = preg_match('/\b' . $pattern . '\b/i', $sensorName);
                            return $areaMatch && $sensorMatch;
                        });
                        if ($foundItem) $dataForThisDate->push($foundItem);
                    }
                } else {
                    $generalKeywords = $this->extractSensorKeywordsFromMessage($message);
                    if (!empty($generalKeywords)) {
                        $dataForThisDate = $filteredData->filter(function ($item) use ($generalKeywords) {
                            $sensorName = Str::lower($item['sensor']);
                            foreach ($generalKeywords as $keyword) {
                                $pattern = preg_quote($keyword, '/');
                                if ($keyword === 'temp soil') $pattern = 'temp\.? ?soil';
                                if (preg_match('/\b' . $pattern . '\b/i', $sensorName)) return true;
                            }
                            return false;
                        });
                    }
                }

                if ($dataForThisDate->isNotEmpty()) {
                    $masterPromptData[$targetDate] = $dataForThisDate;
                }
            }

            if (empty($masterPromptData)) {
                return response()->json(['message' => $message, 'response' => "Maaf, saya tidak dapat menemukan data apa pun untuk tanggal dan sensor yang Anda minta.", 'name_chat' => $chatName]);
            }

            // Buat prompt komprehensif dari semua data yang terkumpul
            $summaryPrompt = "Berikut adalah data sensor lahan (nilai tertinggi) berdasarkan permintaan spesifik dari beberapa tanggal:\n";
            foreach ($masterPromptData as $date => $dataList) {
                $formattedDate = Carbon::parse($date)->isoFormat('D MMMM YYYY');
                $summaryPrompt .= "\nUntuk tanggal $formattedDate:\n";
                foreach ($dataList->unique()->values() as $sensorInfo) {
                    $summaryPrompt .= "- {$sensorInfo['sensor']}: {$sensorInfo['nilai']}\n";
                }
            }
            $summaryPrompt .= "\nTolong buatkan ringkasan kondisi lahan dari semua data tersebut dalam bentuk paragraf. Penting: Pisahkan ringkasan untuk setiap tanggal dan sebutkan secara eksplisit nama sensor beserta areanya. Buatlah ringkasan yang ramah dan mudah dipahami petani.";

            $formattedResponse = $this->sendGeneralMessageToOpenAI($summaryPrompt);
            $session = ChatSession::firstOrCreate(['user_id' => $user->user_id, 'name_chat' => $chatName]);
            $chatUser = ChatUser::create(['session_id' => $session->session_id, 'message' => $message]);
            ChatResponse::create(['mess_id' => $chatUser->mess_id, 'response' => $formattedResponse]);

            return response()->json(['message' => $message, 'response' => $formattedResponse, 'name_chat' => $chatName]);
        } catch (\Throwable $e) {
            Log::error("handleDataQuery Error: " . $e->getMessage() . " on line " . $e->getLine());
            return response()->json(['message' => 'Maaf, sistem mengalami masalah saat memproses pertanyaan berbasis data.'], 500);
        }
    }


    private function extractSensorKeywordsFromMessage(string $message): array
    {
        $lowerMessage = strtolower($message);

        $sensorMap = [
            'kelembaban tanah'      => 'humidity soil',
            'suhu tanah'            => 'temp soil',
            'kelembaban lingkungan' => 'environtment humidity',
            'suhu lingkungan'       => 'env temp',
            'curah hujan'           => 'curah hujan',
            'kecepatan angin'       => 'wind speed',
            'suhu'                  => 'env temp',
            'kelembaban'            => 'environtment humidity',
            'ph'                    => 'ph',
            'fosfor'                => 'phosphorus',
            'nitrogen'              => 'nitrogen',
            'kalium'                => 'potassium',
            'konduktivitas'         => 'conductifity',
            'keasaman'              => 'conductifity',
            'ec'                    => 'electrical conductifity',
        ];

        $foundKeywords = [];
        foreach ($sensorMap as $userInput => $dbKeyword) {
            if (str_contains($lowerMessage, $userInput)) {
                $foundKeywords[] = $dbKeyword;
            }
        }

        $foundKeywords = array_unique($foundKeywords);

        if (in_array('temp soil', $foundKeywords) && in_array('env temp', $foundKeywords)) {
            $foundKeywords = array_filter($foundKeywords, fn($kw) => $kw !== 'env temp');
        }

        if (in_array('humidity soil', $foundKeywords) && in_array('environtment humidity', $foundKeywords)) {
            $foundKeywords = array_filter($foundKeywords, fn($kw) => $kw !== 'environtment humidity');
        }

        return array_values($foundKeywords);
    }

    private function extractAreaFromMessage(string $message): ?int
    {
        $lowerMessage = strtolower($message);
        // Ini agar pengguna bisa bertanya mengenai 1 area saja
        if (preg_match('/area\s*(\d+)/', $lowerMessage, $matches)) {
            return (int)$matches[1];
        }
        return null;
    }

    private function parseSensorAreaPairs(string $message): array
    {
        $cleanedMessage = str_replace(['saya melihat data lahan', 'di tanggal'], '', $message);

        // Pecah pesan berdasarkan koma dan kata "dan"
        $parts = preg_split('/, dan|,| dan /', $cleanedMessage, -1, PREG_SPLIT_NO_EMPTY);

        $pairs = [];
        $lastArea = null;

        foreach (array_reverse($parts) as $part) {
            $part = trim($part);
            if (empty($part)) continue;

            $currentArea = $this->extractAreaFromMessage($part);
            if ($currentArea !== null) {
                $lastArea = $currentArea;
            }

            $foundSensors = $this->extractSensorKeywordsFromMessage($part);

            foreach ($foundSensors as $sensor) {
                if ($lastArea !== null) {
                    $isDuplicate = collect($pairs)->contains(function ($p) use ($sensor, $lastArea) {
                        return $p[0] === $sensor && $p[1] === $lastArea;
                    });

                    if (!$isDuplicate) {
                        $pairs[] = [$sensor, $lastArea];
                    }
                }
            }
        }

        return array_reverse($pairs);
    }

    private function extractAllDatesFromMessage(string $message): array
    {
        $lowerMessage = strtolower($message);
        $today = now();
        $foundDates = [];

        if (str_contains($lowerMessage, 'hari ini')) $foundDates[] = $today->toDateString();
        if (str_contains($lowerMessage, 'kemarin')) $foundDates[] = $today->clone()->subDay()->toDateString();

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

        if (preg_match_all("/(\d{1,2})\s+(" . implode('|', array_keys($bulanMap)) . ")(?:\s+(\d{4}))?/i", $lowerMessage, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $year = $match[3] ?? $today->year;
                $month = $bulanMap[strtolower($match[2])];
                $day = str_pad($match[1], 2, '0', STR_PAD_LEFT);
                $foundDates[] = "$year-$month-$day";
            }
        }

        if (preg_match_all("/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/", $lowerMessage, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $year = $match[3];
                $month = str_pad($match[2], 2, '0', STR_PAD_LEFT);
                $day = str_pad($match[1], 2, '0', STR_PAD_LEFT);
                $foundDates[] = "$year-$month-$day";
            }
        }

        if (empty($foundDates)) {
            $foundDates[] = $today->toDateString();
        }

        return array_unique($foundDates);
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
