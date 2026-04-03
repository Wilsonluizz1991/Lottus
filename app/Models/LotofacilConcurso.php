<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        'rateio_15_acertos',

        'ganhadores_14_acertos',
        'rateio_14_acertos',

        'ganhadores_13_acertos',
        'rateio_13_acertos',

        'ganhadores_12_acertos',
        'rateio_12_acertos',

        'ganhadores_11_acertos',
        'rateio_11_acertos',

        'cidade_uf',
        'observacao',

        'arrecadacao_total',
        'estimativa_premio',
        'acumulado_15_acertos',
        'acumulado_sorteio_especial_lotofacil_independencia',

        'informado_manualmente',
    ];

    protected $casts = [
        'concurso' => 'integer',
        'data_sorteio' => 'date',

        'bola1' => 'integer',
        'bola2' => 'integer',
        'bola3' => 'integer',
        'bola4' => 'integer',
        'bola5' => 'integer',
        'bola6' => 'integer',
        'bola7' => 'integer',
        'bola8' => 'integer',
        'bola9' => 'integer',
        'bola10' => 'integer',
        'bola11' => 'integer',
        'bola12' => 'integer',
        'bola13' => 'integer',
        'bola14' => 'integer',
        'bola15' => 'integer',

        'ganhadores_15_acertos' => 'integer',
        'rateio_15_acertos' => 'float',

        'ganhadores_14_acertos' => 'integer',
        'rateio_14_acertos' => 'float',

        'ganhadores_13_acertos' => 'integer',
        'rateio_13_acertos' => 'float',

        'ganhadores_12_acertos' => 'integer',
        'rateio_12_acertos' => 'float',

        'ganhadores_11_acertos' => 'integer',
        'rateio_11_acertos' => 'float',

        'arrecadacao_total' => 'float',
        'estimativa_premio' => 'float',
        'acumulado_15_acertos' => 'float',
        'acumulado_sorteio_especial_lotofacil_independencia' => 'float',

        'informado_manualmente' => 'boolean',
    ];

    public function apostas()
    {
        return $this->hasMany(LotofacilAposta::class, 'concurso_base_id');
    }

    public function getDezenasAttribute(): array
    {
        $dezenas = [
            $this->bola1,
            $this->bola2,
            $this->bola3,
            $this->bola4,
            $this->bola5,
            $this->bola6,
            $this->bola7,
            $this->bola8,
            $this->bola9,
            $this->bola10,
            $this->bola11,
            $this->bola12,
            $this->bola13,
            $this->bola14,
            $this->bola15,
        ];

        sort($dezenas);

        return $dezenas;
    }
} 