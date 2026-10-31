<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** SK Pengurus Koperasi beserta file SK-nya. */
class BoardDecree extends Model
{
    protected $fillable = ['number', 'decree_date', 'effective_from', 'effective_until', 'file_path', 'file_name', 'notes', 'created_by'];

    protected $casts = [
        'decree_date' => 'date:Y-m-d',
        'effective_from' => 'date:Y-m-d',
        'effective_until' => 'date:Y-m-d',
    ];

    protected $hidden = ['file_path'];

    public function members()
    {
        return $this->hasMany(BoardMember::class)->orderBy('sort');
    }
}
