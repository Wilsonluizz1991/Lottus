<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cupom extends Model
{
    protected $table = 'cupons';

    protected $fillable = [
        'codigo',
        'nome',
        'tipo_desconto',
        'valor_desconto',
        'valor_minimo_pedido',
        'limite_total_uso',
        'limite_uso_por_email',
        'total_usos',
        'ativo',
        'inicio_em',
        'expira_em',
        'observacoes',
    ];

    protected $casts = [
        'valor_desconto' => 'decimal:2',
        'valor_minimo_pedido' => 'decimal:2',
        'ativo' => 'boolean',
        'inicio_em' => 'datetime',
        'expira_em' => 'datetime',
    ];

    public function pedidos(): HasMany
    {
        return $this->hasMany(LottusPedido::class, 'cupom_id');
    }
}