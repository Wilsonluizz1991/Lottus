<?php

namespace App\Services\Lottus\Tuning;

class PortfolioGuardianScenarioFactory
{
    public function makeScenario(?array $base = null): array
    {
        $scenario = $base ?? config('lottus_portfolio_tuning.default', []);
        $searchSpace = config('lottus_portfolio_guardian.search_space', []);

        foreach ($searchSpace as $path => $values) {
            if (empty($values)) {
                continue;
            }

            $this->setByPath(
                $scenario,
                $path,
                $values[array_rand($values)]
            );
        }

        return $scenario;
    }

    protected function setByPath(array &$array, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $current = &$array;

        foreach ($segments as $segment) {
            if (! isset($current[$segment]) || ! is_array($current[$segment])) {
                $current[$segment] = [];
            }

            $current = &$current[$segment];
        }

        $current = $value;
    }
}