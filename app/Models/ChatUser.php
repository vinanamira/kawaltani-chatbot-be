<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatUser extends Model
{
    protected $table = 'chat_user';
    protected $primaryKey = 'mess_id';
    public $timestamps = true;

    protected $fillable = ['session_id', 'message'];

    public function response()
    {
        return $this->hasOne(ChatResponse::class, 'mess_id', 'mess_id');
    }
}