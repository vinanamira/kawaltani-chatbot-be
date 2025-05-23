<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Area extends Model
{
    protected $table = 'area';
    protected $primaryKey = 'id';
    protected $fillable = ['id', 'name', 'type', 'site_id'];

    public $timestamps = false;

    public function site()
    {
        return $this->belongsTo(Site::class, 'site_id', 'site_id');
    }
}