<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'external_reference',
        'order_code',
        'provider',
        'provider_preference_id',
        'provider_payment_id',
        'provider_collection_id',
        'provider_status',
        'provider_status_detail',
        'payer_email',
        'payer_name',
        'amount',
        'currency_id',
        'description',
        'payment_method_id',
        'payment_type_id',
        'local_status',
        'paid_at',
        'last_synced_at',
        'items',
        'preference_payload',
        'preference_response',
        'last_payment_response',
        'init_point',
        'sandbox_init_point',
    ];

    protected $casts = [
        'items' => 'array',
        'preference_payload' => 'array',
        'preference_response' => 'array',
        'last_payment_response' => 'array',
        'paid_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'amount' => 'decimal:2',
    ];
}