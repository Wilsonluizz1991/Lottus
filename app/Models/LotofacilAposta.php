<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LotofacilAposta extends Model
{
    use HasFactory;

    protected $table = 'lotofacil_apostas';

    protected $fillable = [
        'user_id',
        'email',
        'token_lote',
        'concurso_base_id',
        'data_esperada_sorteio',
        'dezenas',
        'score',
        'pares',
        'impares',
        'soma',
        'repetidas_ultimo_concurso',
        'quentes',
        'atrasadas',
        'analise',
    ];

    protected $casts = [
        'data_esperada_sorteio' => 'date',
        'dezenas' => 'array',
        'analise' => 'array',
        'score' => 'decimal:2',
    ];

    public function concursoBase()
    {
        return $this->belongsTo(LotofacilConcurso::class, 'concurso_base_id');
    }
}
