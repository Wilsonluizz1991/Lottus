<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LotofacilConcursoSincronizado
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public int $concurso
    ) {
    }
}
