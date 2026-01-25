<?php

class ProjectionModel
{
    public function projectOneYear(float $startingBalance, float $annualReturnRate): float
    {
        // Daily compounding for one year
        return $startingBalance * pow(1 + $annualReturnRate / 365, 365);
    }

    public function projectYears(
        float $startingBalance,
        float $annualReturnRate,
        int $startYear,
        int $years
    ): array {
        $rows = [];
        $balance = $startingBalance;

        for ($i = 0; $i < $years; $i++) {
            $year = $startYear + $i;
            $start = $balance;

            // Daily compounding for one year
            $balance = $start * pow(1 + $annualReturnRate / 365, 365);

            $rows[] = [
                'year'  => $year,
                'start' => $start,
                'end'   => $balance,
            ];
        }

        return $rows;
    }
}