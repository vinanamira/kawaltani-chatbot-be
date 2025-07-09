<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatResponse extends Model
{
    protected $table = 'chat_response';
    protected $primaryKey = 'res_id';
    public $timestamps = true;

    protected $fillable = ['mess_id', 'response'];

    public function message()
    {
        return $this->belongsTo(ChatUser::class, 'mess_id', 'mess_id');
    }
}
