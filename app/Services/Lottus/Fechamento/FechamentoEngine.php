<?php

namespace App\Services\Lottus\Fechamento;

use App\Models\LotofacilConcurso;
use App\Services\Lottus\Analysis\CorrelationAnalysisService;
use App\Services\Lottus\Analysis\CycleAnalysisService;
use App\Services\Lottus\Analysis\DelayAnalysisService;
use App\Services\Lottus\Analysis\FrequencyAnalysisService;
use App\Services\Lottus\Analysis\StructureAnalysisService;
use App\Services\Lottus\Data\HistoricalDataService;

class FechamentoEngine
{
    public function __construct(
        protected HistoricalDataService $historicalDataService,
        protected FrequencyAnalysisService $frequencyAnalysisService,
        protected DelayAnalysisService $delayAnalysisService,
        protected CorrelationAnalysisService $correlationAnalysisService,
        protected StructureAnalysisService $structureAnalysisService,
        protected CycleAnalysisService $cycleAnalysisService,
        protected FechamentoPatternPredictionService $patternPredictionService,
        protected FechamentoCandidateSelector $candidateSelector,
        protected FechamentoBaseCompetitionService $baseCompetitionService,
        protected FechamentoBaseVariationService $baseVariationService,
        protected FechamentoCombinationGenerator $combinationGenerator,
        protected FechamentoScoreService $scoreService,
        protected FechamentoCoverageOptimizerService $coverageOptimizerService,
        protected FechamentoReducer $reducer,
        protected FechamentoPersistenceService $persistenceService
    ) {
    }

    public function generate(array $payload): array
    {
        $email = (string) ($payload['email'] ?? '');
        $quantidadeDezenas = (int) ($payload['quantidade_dezenas'] ?? 0);
        $cupom = $payload['cupom'] ?? null;

        /** @var LotofacilConcurso|null $concursoBase */
        $concursoBase = $payload['concurso_base'] ?? null;

        $this->validatePayload($email, $quantidadeDezenas, $concursoBase);

        $historico = $this->historicalDataService->getUntilContest($concursoBase->concurso);

        if ($historico->isEmpty()) {
            throw new \Exception('Histórico insuficiente para gerar o fechamento.');
        }

        $frequencyContext = $this->frequencyAnalysisService->analyze($historico);
        $delayContext = $this->delayAnalysisService->analyze($historico);
        $correlationContext = $this->correlationAnalysisService->analyze($historico);
        $structureContext = $this->structureAnalysisService->analyze($historico);
        $cycleContext = $this->cycleAnalysisService->analyze($historico);

        $patternContext = $this->patternPredictionService->predict(
            historico: $historico,
            frequencyContext: $frequencyContext,
            delayContext: $delayContext,
            correlationContext: $correlationContext,
            structureContext: $structureContext,
            cycleContext: $cycleContext,
            concursoBase: $concursoBase
        );

        $quantidadeJogos = (int) config("lottus_fechamento.output_games.{$quantidadeDezenas}", 0);

        if ($quantidadeJogos <= 0) {
            throw new \Exception('Quantidade de jogos do fechamento não configurada.');
        }

        $baseCommercialSeed = $this->resolveCommercialSeed(
            payload: $payload,
            email: $email,
            quantidadeDezenas: $quantidadeDezenas,
            concursoBase: $concursoBase
        );

        $maxRegenerationAttempts = $this->commercialRegenerationAttempts($quantidadeDezenas);
        $lastDuplicateDetected = false;

        for ($generationAttempt = 0; $generationAttempt < $maxRegenerationAttempts; $generationAttempt++) {
            $commercialSeed = $generationAttempt === 0
                ? $baseCommercialSeed
                : $this->nextCommercialSeed($baseCommercialSeed, $generationAttempt + 1000);

            $dezenasBaseInicial = $this->candidateSelector->select(
                $quantidadeDezenas,
                $frequencyContext,
                $delayContext,
                $correlationContext,
                $structureContext,
                $cycleContext,
                $concursoBase
            );

            if (count($dezenasBaseInicial) !== $quantidadeDezenas) {
                throw new \Exception('Falha ao selecionar as dezenas base inicial do fechamento.');
            }

            $dezenasBase = $this->baseCompetitionService->selectWinningBase(
                primaryBase: $dezenasBaseInicial,
                quantidadeDezenas: $quantidadeDezenas,
                historico: $historico,
                frequencyContext: $frequencyContext,
                delayContext: $delayContext,
                correlationContext: $correlationContext,
                structureContext: $structureContext,
                cycleContext: $cycleContext,
                concursoBase: $concursoBase,
                patternContext: $patternContext
            );

            if (count($dezenasBase) !== $quantidadeDezenas) {
                throw new \Exception('Falha ao selecionar a base vencedora do fechamento.');
            }

            if ($generationAttempt > 0) {
                $dezenasBase = $this->resolveRegeneratedCommercialBase(
                    dezenasBase: $dezenasBase,
                    quantidadeDezenas: $quantidadeDezenas,
                    generationAttempt: $generationAttempt,
                    frequencyContext: $frequencyContext
                );
            }

            $bases = [$dezenasBase];

            if ((bool) config('lottus_fechamento.base_variations.enabled', false)) {
                $numberScores = $this->baseCompetitionService->getLastNumberScores();

                if (empty($numberScores)) {
                    $numberScores = $frequencyContext['scores'] ?? [];
                }

                $bases = $this->baseVariationService->generate(
                    base: $dezenasBase,
                    numberScores: $numberScores,
                    quantidadeDezenas: $quantidadeDezenas,
                    maxVariations: match ($quantidadeDezenas) {
                        16 => $generationAttempt > 0 ? 3 : 1,
                        17 => $generationAttempt > 0 ? 4 : 2,
                        18 => $generationAttempt > 0 ? 5 : 3,
                        19 => $generationAttempt > 0 ? 6 : 4,
                        20 => $generationAttempt > 0 ? 7 : 5,
                        default => $generationAttempt > 0 ? 5 : 3,
                    }
                );
            }

            $combinations = [];

            foreach ($bases as $baseVariation) {
                $generated = $this->combinationGenerator->generate(
                    $baseVariation,
                    $quantidadeDezenas
                );

                if (! empty($generated)) {
                    $combinations = array_merge($combinations, $generated);
                }
            }

            $combinations = array_values(array_unique(
                array_map(
                    fn ($game) => implode('-', array_map('intval', $game)),
                    $combinations
                )
            ));

            $combinations = array_map(
                fn ($key) => array_map('intval', explode('-', $key)),
                $combinations
            );

            if (empty($combinations)) {
                continue;
            }

            $scoredCombinations = $this->scoreService->score(
                $combinations,
                $frequencyContext,
                $delayContext,
                $correlationContext,
                $structureContext,
                $cycleContext,
                $concursoBase
            );

            $scoredCombinations = $this->prepareCommercialCandidatePool(
                scoredCombinations: $scoredCombinations,
                commercialSeed: $commercialSeed
            );

            $selectedGames = $this->coverageOptimizerService->optimize(
                $scoredCombinations,
                $quantidadeJogos,
                $dezenasBase,
                $this->baseCompetitionService->getLastNumberScores() ?: ($frequencyContext['scores'] ?? [])
            );

            if (count($selectedGames) < $quantidadeJogos) {
                $selectedGames = $this->reducer->reduce(
                    $scoredCombinations,
                    $quantidadeJogos,
                    $dezenasBase
                );
            }

            if (count($selectedGames) < $quantidadeJogos) {
                continue;
            }

            $commercialPortfolio = $this->resolveUniqueCommercialPortfolio(
                selectedGames: $selectedGames,
                candidatePool: $scoredCombinations,
                commercialSeed: $commercialSeed,
                quantidadeJogos: $quantidadeJogos,
                quantidadeDezenas: $quantidadeDezenas,
                concursoBase: $concursoBase
            );

            if (is_array($commercialPortfolio) && ! empty($commercialPortfolio['jogos'])) {
                return $this->persistenceService->store(
                    email: $email,
                    concursoBase: $concursoBase,
                    quantidadeDezenas: $quantidadeDezenas,
                    dezenasBase: $dezenasBase,
                    jogos: $commercialPortfolio['jogos'],
                    cupom: $cupom,
                    commercialSeed: $commercialPortfolio['commercial_seed']
                );
            }

            $lastDuplicateDetected = true;
        }

        if ($lastDuplicateDetected) {
            throw new \Exception('Não foi possível gerar um fechamento comercialmente único após múltiplas tentativas seguras. Tente novamente para preservar a exclusividade do lote.');
        }

        throw new \Exception('O fechamento não conseguiu selecionar a quantidade final de jogos.');
    }

    protected function validatePayload(
        string $email,
        int $quantidadeDezenas,
        ?LotofacilConcurso $concursoBase
    ): void {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('E-mail inválido para geração do fechamento.');
        }

        $min = (int) config('lottus_fechamento.min_dezenas', 16);
        $max = (int) config('lottus_fechamento.max_dezenas', 20);

        if ($quantidadeDezenas < $min || $quantidadeDezenas > $max) {
            throw new \InvalidArgumentException("A quantidade de dezenas deve estar entre {$min} e {$max}.");
        }

        if (! $concursoBase) {
            throw new \InvalidArgumentException('Concurso base não informado para o fechamento.');
        }
    }

    protected function commercialRegenerationAttempts(int $quantidadeDezenas): int
    {
        $configuredAttempts = (int) config('lottus_fechamento.commercial_regeneration_attempts', 0);

        if ($configuredAttempts > 0) {
            return $configuredAttempts;
        }

        return match ($quantidadeDezenas) {
            16 => 40,
            17 => 30,
            18 => 24,
            19 => 20,
            20 => 18,
            default => 24,
        };
    }

    protected function resolveRegeneratedCommercialBase(
        array $dezenasBase,
        int $quantidadeDezenas,
        int $generationAttempt,
        array $frequencyContext
    ): array {
        $numberScores = $this->baseCompetitionService->getLastNumberScores();

        if (empty($numberScores)) {
            $numberScores = $frequencyContext['scores'] ?? [];
        }

        $scoreMap = $this->normalizeNumberScores($numberScores);

        if (empty($scoreMap)) {
            return $this->normalizeGame($dezenasBase);
        }

        $variations = $this->baseVariationService->generate(
            base: $dezenasBase,
            numberScores: $scoreMap,
            quantidadeDezenas: $quantidadeDezenas,
            maxVariations: match ($quantidadeDezenas) {
                16 => 10,
                17 => 9,
                18 => 8,
                19 => 7,
                20 => 7,
                default => 8,
            }
        );

        $validVariations = array_values(array_filter(
            $variations,
            fn ($variation) => is_array($variation) && count($variation) === $quantidadeDezenas
        ));

        $statisticalMutations = $this->generateStatisticalBaseMutations(
            dezenasBase: $dezenasBase,
            quantidadeDezenas: $quantidadeDezenas,
            scoreMap: $scoreMap
        );

        $validVariations = array_values(array_unique(
            array_map(
                fn ($variation) => implode('-', $this->normalizeGame($variation)),
                array_merge($validVariations, $statisticalMutations)
            )
        ));

        if (empty($validVariations)) {
            return $this->normalizeGame($dezenasBase);
        }

        $index = ($generationAttempt - 1) % count($validVariations);

        return array_map('intval', explode('-', $validVariations[$index]));
    }

    protected function normalizeNumberScores(array $numberScores): array
    {
        $scoreMap = [];

        foreach ($numberScores as $key => $value) {
            if (is_array($value)) {
                $number = (int) ($value['dezena'] ?? $value['number'] ?? $value['numero'] ?? $key);
                $score = (float) ($value['score'] ?? $value['value'] ?? $value['peso'] ?? 0.0);
            } else {
                $number = (int) $key;
                $score = (float) $value;
            }

            if ($number >= 1 && $number <= 25) {
                $scoreMap[$number] = $score;
            }
        }

        if (count($scoreMap) < 25) {
            for ($number = 1; $number <= 25; $number++) {
                $scoreMap[$number] = $scoreMap[$number] ?? 0.0;
            }
        }

        arsort($scoreMap);

        return $scoreMap;
    }

    protected function generateStatisticalBaseMutations(
        array $dezenasBase,
        int $quantidadeDezenas,
        array $scoreMap
    ): array {
        $base = $this->normalizeGame($dezenasBase);
        $baseLookup = array_fill_keys($base, true);

        $baseByWeakness = $base;
        usort($baseByWeakness, function (int $a, int $b) use ($scoreMap): int {
            $scoreComparison = ($scoreMap[$a] ?? 0.0) <=> ($scoreMap[$b] ?? 0.0);

            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }

            return $a <=> $b;
        });

        $outsideByStrength = array_values(array_filter(
            array_keys($scoreMap),
            fn ($number) => ! isset($baseLookup[(int) $number])
        ));

        usort($outsideByStrength, function (int $a, int $b) use ($scoreMap): int {
            $scoreComparison = ($scoreMap[$b] ?? 0.0) <=> ($scoreMap[$a] ?? 0.0);

            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }

            return $a <=> $b;
        });

        if (empty($baseByWeakness) || empty($outsideByStrength)) {
            return [];
        }

        $mutations = [];
        $maxSingleSwaps = min(count($baseByWeakness), count($outsideByStrength), match ($quantidadeDezenas) {
            16 => 9,
            17 => 8,
            18 => 7,
            19 => 6,
            20 => 5,
            default => 7,
        });

        for ($i = 0; $i < $maxSingleSwaps; $i++) {
            $mutation = array_values(array_diff($base, [$baseByWeakness[$i]]));
            $mutation[] = (int) $outsideByStrength[$i];
            $mutation = $this->normalizeGame($mutation);

            if (count($mutation) === $quantidadeDezenas) {
                $mutations[] = $mutation;
            }
        }

        $maxDoubleSwaps = min(
            max(0, count($baseByWeakness) - 1),
            max(0, count($outsideByStrength) - 1),
            match ($quantidadeDezenas) {
                16 => 6,
                17 => 5,
                18 => 4,
                19 => 3,
                20 => 2,
                default => 4,
            }
        );

        for ($i = 0; $i < $maxDoubleSwaps; $i++) {
            $remove = [$baseByWeakness[$i], $baseByWeakness[$i + 1]];
            $add = [(int) $outsideByStrength[$i], (int) $outsideByStrength[$i + 1]];
            $mutation = array_values(array_diff($base, $remove));
            $mutation = array_merge($mutation, $add);
            $mutation = $this->normalizeGame($mutation);

            if (count($mutation) === $quantidadeDezenas) {
                $mutations[] = $mutation;
            }
        }

        return $mutations;
    }

    protected function resolveCommercialSeed(
        array $payload,
        string $email,
        int $quantidadeDezenas,
        LotofacilConcurso $concursoBase
    ): string {
        $manualSeed = $payload['generation_seed'] ?? $payload['seed'] ?? null;

        if (is_string($manualSeed) && trim($manualSeed) !== '') {
            return hash('sha256', trim($manualSeed));
        }

        return hash('sha256', implode('|', [
            'lottus-fechamento',
            $email,
            $quantidadeDezenas,
            $concursoBase->concurso,
            microtime(true),
            random_int(1, PHP_INT_MAX),
            bin2hex(random_bytes(16)),
        ]));
    }

    protected function prepareCommercialCandidatePool(
        array $scoredCombinations,
        string $commercialSeed
    ): array {
        foreach ($scoredCombinations as $index => &$candidate) {
            $game = $this->normalizeGame($candidate['dezenas'] ?? $candidate);
            $score = (float) ($candidate['score'] ?? 0.0);

            $candidate['dezenas'] = $game;
            $candidate['original_score'] = $score;
            $candidate['commercial_seed_score'] = $this->stableFloat($commercialSeed . '|pool|' . implode('-', $game) . '|' . $index);
            $candidate['commercial_fingerprint'] = hash('sha256', $commercialSeed . '|' . implode('-', $game));
        }

        unset($candidate);

        usort($scoredCombinations, function (array $a, array $b): int {
            $scoreComparison = ($b['original_score'] ?? $b['score'] ?? 0) <=> ($a['original_score'] ?? $a['score'] ?? 0);

            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }

            return ($b['commercial_seed_score'] ?? 0) <=> ($a['commercial_seed_score'] ?? 0);
        });

        return $scoredCombinations;
    }


    protected function resolveUniqueCommercialPortfolio(
        array $selectedGames,
        array $candidatePool,
        string $commercialSeed,
        int $quantidadeJogos,
        int $quantidadeDezenas,
        LotofacilConcurso $concursoBase
    ): ?array {
        $maxAttempts = max(1, (int) config('lottus_fechamento.commercial_uniqueness_attempts', 8));
        $lastPortfolio = [];
        $lastCommercialSeed = $commercialSeed;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $attemptSeed = $attempt === 0
                ? $commercialSeed
                : $this->nextCommercialSeed($commercialSeed, $attempt);

            $attemptCandidatePool = $attempt === 0
                ? $candidatePool
                : $this->prepareCommercialCandidatePool($candidatePool, $attemptSeed);

            $attemptPortfolio = $this->finalizeCommercialPortfolio(
                selectedGames: $selectedGames,
                candidatePool: $attemptCandidatePool,
                commercialSeed: $attemptSeed,
                quantidadeJogos: $quantidadeJogos,
                quantidadeDezenas: $quantidadeDezenas
            );

            $lastPortfolio = $attemptPortfolio;
            $lastCommercialSeed = $attemptSeed;

            if (count($attemptPortfolio) < $quantidadeJogos) {
                continue;
            }

            $fingerprint = $this->persistenceService->portfolioFingerprint($attemptPortfolio);

            if (! $this->persistenceService->hasIdenticalCommercialPortfolio(
                concursoBase: $concursoBase,
                quantidadeDezenas: $quantidadeDezenas,
                quantidadeJogos: $quantidadeJogos,
                portfolioFingerprint: $fingerprint
            )) {
                return [
                    'jogos' => $attemptPortfolio,
                    'commercial_seed' => $attemptSeed,
                ];
            }
        }

        if (count($lastPortfolio) < $quantidadeJogos) {
            return null;
        }

        return null;
    }

    protected function nextCommercialSeed(string $commercialSeed, int $attempt): string
    {
        return hash('sha256', implode('|', [
            $commercialSeed,
            'retry',
            $attempt,
            microtime(true),
            random_int(1, PHP_INT_MAX),
            bin2hex(random_bytes(16)),
        ]));
    }

    protected function finalizeCommercialPortfolio(
        array $selectedGames,
        array $candidatePool,
        string $commercialSeed,
        int $quantidadeJogos,
        int $quantidadeDezenas
    ): array {
        $selectedGames = $this->normalizeGameCollection($selectedGames);
        $candidatePool = $this->normalizeGameCollection($candidatePool);

        if (empty($selectedGames)) {
            return [];
        }

        $protectedCount = $this->protectedCommercialCoreCount($quantidadeJogos, $quantidadeDezenas);
        $variationCount = $this->commercialVariationCount($quantidadeJogos, $quantidadeDezenas);

        usort($selectedGames, function (array $a, array $b): int {
            $aSurvivor = ! empty($a['raw_survivor_priority']);
            $bSurvivor = ! empty($b['raw_survivor_priority']);

            if ($aSurvivor !== $bSurvivor) {
                return $aSurvivor ? -1 : 1;
            }

            return ($b['original_score'] ?? $b['score'] ?? 0) <=> ($a['original_score'] ?? $a['score'] ?? 0);
        });

        $protected = array_slice($selectedGames, 0, $protectedCount);
        $replaceable = array_slice($selectedGames, $protectedCount);

        $final = [];
        $seen = [];

        foreach ($protected as $gameData) {
            $this->appendUniqueGame($final, $seen, $gameData, $commercialSeed);
        }

        $alternatives = $this->commercialAlternatives(
            candidatePool: $candidatePool,
            selectedGames: $selectedGames,
            commercialSeed: $commercialSeed,
            quantidadeJogos: $quantidadeJogos,
            quantidadeDezenas: $quantidadeDezenas
        );

        $addedAlternatives = 0;

        foreach ($alternatives as $alternative) {
            if ($addedAlternatives >= $variationCount) {
                break;
            }

            if ($this->appendUniqueGame($final, $seen, $alternative, $commercialSeed)) {
                $addedAlternatives++;
            }
        }

        foreach ($replaceable as $gameData) {
            if (count($final) >= $quantidadeJogos) {
                break;
            }

            $this->appendUniqueGame($final, $seen, $gameData, $commercialSeed);
        }

        foreach ($candidatePool as $gameData) {
            if (count($final) >= $quantidadeJogos) {
                break;
            }

            $this->appendUniqueGame($final, $seen, $gameData, $commercialSeed);
        }

        usort($final, function (array $a, array $b) use ($commercialSeed): int {
            $aSurvivor = ! empty($a['raw_survivor_priority']);
            $bSurvivor = ! empty($b['raw_survivor_priority']);

            if ($aSurvivor !== $bSurvivor) {
                return $aSurvivor ? -1 : 1;
            }

            $scoreComparison = ($b['original_score'] ?? $b['score'] ?? 0) <=> ($a['original_score'] ?? $a['score'] ?? 0);

            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }

            $aGame = $this->normalizeGame($a['dezenas'] ?? $a);
            $bGame = $this->normalizeGame($b['dezenas'] ?? $b);

            return $this->stableFloat($commercialSeed . '|final|' . implode('-', $bGame))
                <=> $this->stableFloat($commercialSeed . '|final|' . implode('-', $aGame));
        });

        return array_slice($final, 0, $quantidadeJogos);
    }

    protected function commercialAlternatives(
        array $candidatePool,
        array $selectedGames,
        string $commercialSeed,
        int $quantidadeJogos,
        int $quantidadeDezenas
    ): array {
        $selectedKeys = [];

        foreach ($selectedGames as $selectedGame) {
            $selectedKeys[$this->gameKey($selectedGame['dezenas'] ?? $selectedGame)] = true;
        }

        $selectedScores = array_map(
            fn (array $gameData) => (float) ($gameData['original_score'] ?? $gameData['score'] ?? 0.0),
            $selectedGames
        );

        $minSelectedScore = empty($selectedScores) ? 0.0 : min($selectedScores);
        $bestSelectedScore = empty($selectedScores) ? 0.0 : max($selectedScores);
        $scoreFloor = $this->commercialScoreFloor($minSelectedScore, $bestSelectedScore, $quantidadeDezenas);

        $limit = min(
            count($candidatePool),
            max($quantidadeJogos * 8, 160)
        );

        $candidates = [];

        foreach (array_slice($candidatePool, 0, $limit) as $candidate) {
            $game = $this->normalizeGame($candidate['dezenas'] ?? $candidate);
            $key = implode('-', $game);

            if (isset($selectedKeys[$key])) {
                continue;
            }

            $score = (float) ($candidate['original_score'] ?? $candidate['score'] ?? 0.0);

            if ($score < $scoreFloor) {
                continue;
            }

            $candidate['_commercial_choice_score'] =
                ($score * 1000.0) +
                ((float) ($candidate['commercial_seed_score'] ?? $this->stableFloat($commercialSeed . '|alt|' . $key)) * 100.0);

            $candidates[] = $candidate;
        }

        usort($candidates, function (array $a, array $b): int {
            if (($a['_commercial_choice_score'] ?? 0) === ($b['_commercial_choice_score'] ?? 0)) {
                return ($b['original_score'] ?? $b['score'] ?? 0) <=> ($a['original_score'] ?? $a['score'] ?? 0);
            }

            return ($b['_commercial_choice_score'] ?? 0) <=> ($a['_commercial_choice_score'] ?? 0);
        });

        foreach ($candidates as &$candidate) {
            $candidate['commercial_replacement'] = true;
            unset($candidate['_commercial_choice_score']);
        }

        unset($candidate);

        return $candidates;
    }

    protected function protectedCommercialCoreCount(int $quantidadeJogos, int $quantidadeDezenas): int
    {
        return match ($quantidadeDezenas) {
            16 => max(10, (int) floor($quantidadeJogos * 0.75)),
            17 => max(14, (int) floor($quantidadeJogos * 0.70)),
            18 => max(24, (int) floor($quantidadeJogos * 0.68)),
            19 => max(30, (int) floor($quantidadeJogos * 0.66)),
            20 => max(36, (int) floor($quantidadeJogos * 0.64)),
            default => max(24, (int) floor($quantidadeJogos * 0.68)),
        };
    }

    protected function commercialVariationCount(int $quantidadeJogos, int $quantidadeDezenas): int
    {
        return match ($quantidadeDezenas) {
            16 => max(2, (int) floor($quantidadeJogos * 0.12)),
            17 => max(3, (int) floor($quantidadeJogos * 0.14)),
            18 => max(5, (int) floor($quantidadeJogos * 0.16)),
            19 => max(7, (int) floor($quantidadeJogos * 0.18)),
            20 => max(9, (int) floor($quantidadeJogos * 0.20)),
            default => max(5, (int) floor($quantidadeJogos * 0.16)),
        };
    }

    protected function commercialScoreFloor(
        float $minSelectedScore,
        float $bestSelectedScore,
        int $quantidadeDezenas
    ): float {
        $gap = max(1.0, $bestSelectedScore - $minSelectedScore);

        $tolerance = match ($quantidadeDezenas) {
            16 => 0.10,
            17 => 0.12,
            18 => 0.16,
            19 => 0.18,
            20 => 0.20,
            default => 0.16,
        };

        return $minSelectedScore - ($gap * $tolerance);
    }

    protected function normalizeGameCollection(array $games): array
    {
        $normalized = [];

        foreach ($games as $gameData) {
            $game = $this->normalizeGame($gameData['dezenas'] ?? $gameData);

            if (count($game) !== 15) {
                continue;
            }

            if (is_array($gameData)) {
                $gameData['dezenas'] = $game;
            } else {
                $gameData = ['dezenas' => $game];
            }

            $normalized[] = $gameData;
        }

        return $normalized;
    }

    protected function appendUniqueGame(
        array &$final,
        array &$seen,
        array $gameData,
        string $commercialSeed
    ): bool {
        $game = $this->normalizeGame($gameData['dezenas'] ?? $gameData);
        $key = implode('-', $game);

        if (isset($seen[$key])) {
            return false;
        }

        $gameData['dezenas'] = $game;
        $gameData['commercial_fingerprint'] = hash('sha256', $commercialSeed . '|' . $key);

        $final[] = $gameData;
        $seen[$key] = true;

        return true;
    }

    protected function stableFloat(string $value): float
    {
        $hash = hash('sha256', $value);
        $chunk = substr($hash, 0, 12);
        $integer = hexdec($chunk);

        return $integer / 0xFFFFFFFFFFFF;
    }

    protected function normalizeGame(array $game): array
    {
        $game = array_values(array_unique(array_map('intval', $game)));
        sort($game);

        return $game;
    }

    protected function gameKey(array $game): string
    {
        return implode('-', $this->normalizeGame($game));
    }
}
