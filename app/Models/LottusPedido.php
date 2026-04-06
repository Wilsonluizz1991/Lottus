<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LottusPedido extends Model
{
    use HasFactory;

    protected $table = 'lottus_pedidos';

    protected $fillable = [
        'token',
        'email',
        'quantidade',
        'concurso_base_id',
        'valor',
        'jogo',
        'analise',
        'status',
        'gateway',
        'external_reference',
        'gateway_preference_id',
        'payment_url',
        'sandbox_payment_url',
        'payment_id',
        'payment_status',
        'paid_at',
        'expires_at',
        'cupom_id',
        'cupom_codigo',
        'subtotal',
        'desconto',
        'valor_original',
    ];

    protected $casts = [
        'jogo' => 'array',
        'analise' => 'array',
        'paid_at' => 'datetime',
        'expires_at' => 'datetime',
        'valor' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'desconto' => 'decimal:2',
        'valor_original' => 'decimal:2',
        'valor' => 'decimal:2',
    ];

    public function concursoBase()
    {
        return $this->belongsTo(LotofacilConcurso::class, 'concurso_base_id');
    }

    public function isPaid(): bool
    {
        return $this->status === 'pago';
    }

    public function cupom()
    {
        return $this->belongsTo(Cupom::class, 'cupom_id');
    }
}