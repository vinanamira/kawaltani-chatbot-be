<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * Daftar exception yang tidak dilaporkan.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * Daftar input yang tidak di-flash untuk validasi.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Daftarkan callback untuk handling exception.
     */
    public function register(): void
    {
        $this->renderable(function (Throwable $e, $request) {
            if (
                $request->expectsJson() &&
                ($e instanceof \Illuminate\Database\QueryException || $e instanceof \PDOException)
            ) {
                Log::error("Database Error: " . $e->getMessage());
                return response()->json([
                    'message' => 'Terjadi kesalahan saat mengambil riwayat chat. Silakan coba lagi.'
                ], 500);
            }
        });
    }
}
