<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LotofacilConcurso extends Model
{
    use HasFactory;

    protected $table = 'lotofacil_concursos';

    protected $fillable = [
        'concurso',
        'data_sorteio',
        'bola1',
        'bola2',
        'bola3',
        'bola4',
        'bola5',
        'bola6',
        'bola7',
        'bola8',
        'bola9',
        'bola10',
        'bola11',
        'bola12',
        'bola13',
        'bola14',
        'bola15',
        'ganhadores_15_acertos',
        'cidade_uf',
        'rateio_15_acertos',
        'ganhadores_14_acertos',
        'rateio_14_acertos',
        'ganhadores_13_acertos',
        'rateio_13_acertos',
        'ganhadores_12_acertos',
        'rateio_12_acertos',
        'ganhadores_11_acertos',
        'rateio_11_acertos',
        'acumulado_15_acertos',
        'arrecadacao_total',
        'estimativa_premio',
        'acumulado_sorteio_especial_lotofacil_independencia',
        'observacao',
        'informado_manualmente',
    ];

    protected $casts = [
        'data_sorteio' => 'date',
        'informado_manualmente' => 'boolean',
    ];

    public function apostas()
    {
        return $this->hasMany(LotofacilAposta::class, 'concurso_base_id');
    }

    public function getDezenasAttribute(): array
    {
        $dezenas = [
            $this->bola1, $this->bola2, $this->bola3, $this->bola4, $this->bola5,
            $this->bola6, $this->bola7, $this->bola8, $this->bola9, $this->bola10,
            $this->bola11, $this->bola12, $this->bola13, $this->bola14, $this->bola15,
        ];

        sort($dezenas);

        return $dezenas;
    }
}