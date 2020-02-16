<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    //

    public function courses(){
        return $this->belongsToMany(Course::class);
    }

    public function payments(){
        return $this->belongsTo(Payment::class);
    }
}
