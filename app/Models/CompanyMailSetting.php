<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyMailSetting extends Model
{
    protected $fillable = [
        'enabled', 'mailer', 'host', 'port', 'encryption', 'username',
        'password_encrypted', 'from_address', 'from_name', 'reply_to', 'signature',
    ];

    protected $casts = ['enabled' => 'boolean', 'port' => 'integer'];
}
