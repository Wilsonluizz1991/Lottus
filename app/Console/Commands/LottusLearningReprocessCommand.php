<?php

namespace App\Console\Commands;

use App\Jobs\ProcessAdaptiveLearning;
use App\Models\LotofacilConcurso;
use App\Services\Lottus\Learning\AdaptiveLearningPromotionService;
use App\Services\Lottus\Learning\AdaptiveLearningRunService;
use Illuminate\Console\Command;

class LottusLearningReprocessCommand extends Command
{
    protected $signature = 'lottus:learning-reprocess
                            {concurso : Concurso a recalibrar}
                            {--queue : Apenas enfileira o reprocessamento}
                            {--validate-only : Apenas valida o snapshot que mirava este concurso}
                            {--force-validation : Revalida mesmo se o snapshot ja tiver sido validado}';

    protected $description = 'Reprocessa manualmente a calibração adaptativa de um concurso específico';

    public function handle(
        AdaptiveLearningRunService $runService,
        AdaptiveLearningPromotionService $promotionService
    ): int
    {
        $concurso = (int) $this->argument('concurso');

        if (! LotofacilConcurso::query()->where('concurso', $concurso)->exists()) {
            $this->error("Concurso {$concurso} não encontrado.");
            return self::FAILURE;
        }

        if ((bool) $this->option('validate-only')) {
            $this->info("Validando A/B shadow do snapshot que mirava o concurso {$concurso}...");

            $snapshot = $promotionService->validateSnapshotForTarget(
                targetConcurso: $concurso,
                force: (bool) $this->option('force-validation')
            );

            if (! $snapshot) {
                $this->warn("Nenhum snapshot encontrado mirando o concurso {$concurso}.");
                return self::SUCCESS;
            }

            $summary = ($snapshot->validation_metrics ?? [])['summary'] ?? [];

            $this->info('ValidaÃ§Ã£o concluÃ­da.');
            $this->line("Snapshot: {$snapshot->id}");
            $this->line("Status: {$snapshot->validation_status}");
            $this->line("EstratÃ©gia promovida: " . ($snapshot->promoted_strategy ?: '-'));
            $this->line("Score de promoÃ§Ã£o: " . ($snapshot->promotion_score ?? 0));
            $this->line("Resumo: " . json_encode($summary, JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $run = $runService->enqueue(
            concurso: $concurso,
            triggeredBy: 'manual_reprocess',
            force: true
        );

        if (! $run) {
            $this->error("Não foi possível criar a run de aprendizado para o concurso {$concurso}.");
            return self::FAILURE;
        }

        if ((bool) $this->option('queue')) {
            ProcessAdaptiveLearning::dispatch($run->id)->onQueue('learning');

            $this->info("Reprocessamento enfileirado para o concurso {$concurso}.");
            $this->line("Run: {$run->id}");
            $this->line("Versão de calibração: {$run->calibration_version}");

            return self::SUCCESS;
        }

        $this->info("Reprocessando aprendizado do concurso {$concurso}...");

        try {
            $processedRun = $runService->processRun($run->id);

            $this->info('Reprocessamento concluído.');
            $this->line("Run: {$processedRun->id}");
            $this->line("Status: {$processedRun->status}");
            $this->line("Versão de calibração: {$processedRun->calibration_version}");
            $this->line("Duração (ms): {$processedRun->duration_ms}");
            $this->line("Ajustes: {$processedRun->adjustments_count}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Falha no reprocessamento: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
