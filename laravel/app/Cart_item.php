<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Cart_item extends Model
{
    //
    public function carts(){
        return $this->belongsToMany(Cart::class);
    }
}
