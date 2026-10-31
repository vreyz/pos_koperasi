<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BoardMember extends Model
{
    protected $fillable = ['board_decree_id', 'customer_id', 'position', 'sort'];

    public function decree()
    {
        return $this->belongsTo(BoardDecree::class, 'board_decree_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
