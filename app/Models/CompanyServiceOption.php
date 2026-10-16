<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyServiceOption extends Model
{
    protected $fillable = ['category','code','name','description','is_active','is_system'];

    protected $casts = ['is_active'=>'boolean','is_system'=>'boolean'];
}
