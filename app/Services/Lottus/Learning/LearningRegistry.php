<?php

namespace App\Services\Lottus\Learning;

use App\Services\Lottus\Learning\Contracts\LearningStrategyInterface;
use App\Services\Lottus\Learning\Drivers\SimpleReinforcementLearningDriver;

class LearningRegistry
{
    public function __construct(
        protected SimpleReinforcementLearningDriver $simpleReinforcementLearningDriver
    ) {
    }

    /**
     * @return array<int, LearningStrategyInterface>
     */
    public function strategies(): array
    {
        return [
            $this->simpleReinforcementLearningDriver,
        ];
    }

    public function strategy(string $engine, string $strategy): ?LearningStrategyInterface
    {
        foreach ($this->strategies() as $learningStrategy) {
            if (
                $learningStrategy->engine() === $engine &&
                $learningStrategy->strategy() === $strategy
            ) {
                return $learningStrategy;
            }
        }

        return null;
    }
}