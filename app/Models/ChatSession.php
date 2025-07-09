<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatSession extends Model
{
    protected $table = 'chat_sessions';
    protected $primaryKey = 'session_id';
    public $timestamps = true;

    protected $fillable = ['user_id', 'name_chat'];

    public function messages()
    {
        return $this->hasMany(ChatUser::class, 'session_id', 'session_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function session()
    {
        return $this->belongsTo(ChatSession::class, 'session_id', 'session_id');
    }
}
