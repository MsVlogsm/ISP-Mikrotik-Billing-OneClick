<?php

declare(strict_types=1);

namespace XLink\SmsBridge\Models;

use Illuminate\Database\Eloquent\Model;

class SmsGateway extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'sms_gateways';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'api_url',
        'api_send_url',
        'api_balance_url',
        'api_profile_url',
        'api_key',
        'api_secret',
        'username',
        'password',
        'sender_id',
        'priority',
        'is_active',
        'is_default'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'priority' => 'integer',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];
}
