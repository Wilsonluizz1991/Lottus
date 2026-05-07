<?php

namespace App\Console\Commands;

use App\Models\LottusMainLearningRun;
use App\Models\LottusMainLearningSnapshot;
use Illuminate\Console\Command;

class LottusMainLearningStatusCommand extends Command
{
    protected $signature = 'lottus:main-learning-status {--limit=10}';

    protected $description = 'Lista status recente do aprendizado adaptativo do motor principal.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $this->line('Runs recentes');
        $this->table(
            ['ID', 'Concurso', 'Status', 'Decisao', 'Duracao', 'Atualizado'],
            LottusMainLearningRun::query()
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->map(fn (LottusMainLearningRun $run) => [
                    $run->id,
                    $run->concurso,
                    $run->status,
                    $run->decision,
                    $run->duration_ms,
                    optional($run->updated_at)->format('Y-m-d H:i:s'),
                ])
                ->all()
        );

        $this->line('Snapshots recentes');
        $this->table(
            ['ID', 'Base', 'Alvo', 'Status', 'Versao', 'Confianca', 'Criado'],
            LottusMainLearningSnapshot::query()
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->map(fn (LottusMainLearningSnapshot $snapshot) => [
                    $snapshot->id,
                    $snapshot->concurso_base,
                    $snapshot->target_concurso,
                    $snapshot->status,
                    $snapshot->version,
                    $snapshot->confidence,
                    optional($snapshot->created_at)->format('Y-m-d H:i:s'),
                ])
                ->all()
        );

        return self::SUCCESS;
    }
}
