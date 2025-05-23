<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tm_site', function (Blueprint $table) {
            $table->string('site_id', 32)->primary();
            $table->string('site_name', 64)->nullable();  // contoh kolom tambahan
            $table->string('location', 128)->nullable();  // contoh kolom tambahan
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tm_site');
    }
};