<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('chatbot', function (Blueprint $table) {
            $table->string('user_id', 32); 
            $table->text('message');
            $table->text('response');
            $table->timestamps();

            $table->foreign('user_id')
                ->references('user_id')
                ->on('tm_user')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot');
    }
};
