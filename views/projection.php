<?php
// Simplified local version (XAMPP):
// - No portfolio start date
// - Assume Vanguard-style daily compounding (no compounding selector)
// - Social Security starts immediately (no start-year field)
// - Use POST so we don't clutter the URL and we avoid Safari showing a long query string

$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

function post_str(string $key, string $default = ''): string {
    if (!isset($_POST[$key])) return $default;
    return trim((string)$_POST[$key]);
}

function post_float(string $key) {
    $v = post_str($key, '');
    if ($v === '') return '';
    $v = str_replace(',', '', $v);
    if (!is_numeric($v)) return '';
    return (float)$v;
}

function post_int(string $key) {
    $v = post_str($key, '');
    if ($v === '') return '';
    $v = str_replace(',', '', $v);
    if (!is_numeric($v)) return '';
    return (int)$v;
}

function pct_to_decimal($pct) {
    if ($pct === '') return '';
    return ((float)$pct) / 100;
}

/* Inputs (POST only) */
$currentPortfolio     = $isPost ? post_float('current_portfolio') : '';
$ratePct              = $isPost ? post_float('rate') : ''; // percent (e.g., 8)

$firstYearWithdrawal  = $isPost ? post_float('first_year_withdrawal') : '';
$withdrawRatePct      = $isPost ? post_float('withdraw_rate') : '';
$years                = $isPost ? post_int('years') : '';

$ssAnnualIncome       = $isPost ? post_float('ss_annual_income') : '';
$ssColaPct            = $isPost ? post_float('ss_cola') : '';

/* Percents -> decimals */
$rate         = ($ratePct === '' ? '' : pct_to_decimal($ratePct));
$withdrawRate = ($withdrawRatePct === '' ? '' : pct_to_decimal($withdrawRatePct));
$ssCola       = ($ssColaPct === '' ? '' : pct_to_decimal($ssColaPct));

/* Derived */
$start = ($currentPortfolio === '' ? '' : (float)$currentPortfolio);
$startYear = (int)date('Y');

/* Build table only when required fields exist */
$rows = [];

$canBuildTable =
    ($start !== '' &&
     $rate !== '' &&
     $firstYearWithdrawal !== '' &&
     $withdrawRate !== '' &&
     $years !== '' &&
     (int)$years >= 1 &&
     $ssAnnualIncome !== '' &&
     $ssCola !== '');

$calcError = '';
if ($isPost && !$canBuildTable) {
    $missing = [];
    if ($start === '') $missing[] = 'Current Portfolio Value ($)';
    if ($rate === '') $missing[] = 'Expected Annual Return Rate (%)';
    if ($firstYearWithdrawal === '') $missing[] = 'First Year Withdrawal ($)';
    if ($withdrawRate === '') $missing[] = 'Withdrawal Rate (%)';
    if ($years === '' || (int)$years < 1) $missing[] = 'Number of Years (must be 1 or more)';
    if ($ssAnnualIncome === '') $missing[] = 'Estimated Annual Social Security Income ($)';
    if ($ssCola === '') $missing[] = 'Estimated Annual COLA (%)';

    if (!empty($missing)) {
        $calcError = 'Missing/invalid: ' . implode(', ', $missing) . '.';
    }
}

if ($isPost && $canBuildTable) {
    $current = (float)$start;

    for ($i = 0; $i < (int)$years; $i++) {
        $year = (int)$startYear + $i;

        // Social Security starts immediately (year 1 of projection)
        $ssIncome = (float)$ssAnnualIncome * pow(1 + (float)$ssCola, $i);

        $withdrawal = ($i === 0)
            ? (float)$firstYearWithdrawal
            : ($current * (float)$withdrawRate);

        $afterWithdrawal = $current - $withdrawal;

        // Vanguard-style daily compounding (assume 365-day years)
        $growthFactor = pow(1 + ((float)$rate / 365), 365);

        $end = $afterWithdrawal * $growthFactor;

        $rows[] = [
            'year'             => $year,
            'start'            => $current,
            'withdrawal'       => $withdrawal,
            'after_withdrawal' => $afterWithdrawal,
            'end'              => $end,
            'ss_income'        => $ssIncome,
            'total_income'     => ($withdrawal + $ssIncome),
        ];

        $current = $end;
    }
}
?>
<link rel="stylesheet" href="public/css/style.css?v=1">

<h1 style="text-align: center; margin-top: 10px;">Retirement Income Projection</h1>

<p style="max-width: 1200px; margin: 12px auto 24px auto; line-height: 2;">
This tool is designed for individuals or couples who have, or will have, retirement income from two main sources: regular Social Security income and withdrawals from a retirement investment account such as a 401(k), IRA, or similar portfolio. By entering assumptions about spending needs, investment growth, inflation, and Social Security COLA, the tool shows how those income sources work together over time. The purpose is not to predict markets, but to help test assumptions and assess whether a chosen withdrawal approach can realistically support retirement expenses over the long term. Investment growth is modeled using Vanguard-style daily compounding.
</p>

<form method="post" action="">

<label>
Current Portfolio Value ($):
<input type="text" inputmode="decimal" name="current_portfolio" value="<?= htmlspecialchars($currentPortfolio === '' ? '' : (string)$currentPortfolio) ?>">
</label>

<br><br>

<label>
Expected Annual Return Rate (%):
<input type="text" inputmode="decimal" name="rate" value="<?= htmlspecialchars($ratePct === '' ? '' : (string)$ratePct) ?>">
</label>

<br><br>

<h2>Withdrawal Assumptions</h2>

<label>
First Year Withdrawal ($):
<input type="text" inputmode="decimal" name="first_year_withdrawal" value="<?= htmlspecialchars($firstYearWithdrawal === '' ? '' : (string)$firstYearWithdrawal) ?>">
</label>

<br><br>

<label>
Withdrawal Rate (%) of previous year's balance:
<input type="text" inputmode="decimal" name="withdraw_rate" value="<?= htmlspecialchars($withdrawRatePct === '' ? '' : (string)$withdrawRatePct) ?>">
</label>

<br><br>

<label>
Number of Years:
<input type="text" inputmode="numeric" name="years" value="<?= htmlspecialchars($years === '' ? '' : (string)$years) ?>">
</label>

<br><br><br>

<h2>Social Security Income</h2>

<label>
Estimated Annual Social Security Income ($):
<input type="text" inputmode="decimal" name="ss_annual_income" value="<?= htmlspecialchars($ssAnnualIncome === '' ? '' : (string)$ssAnnualIncome) ?>">
</label>

<br><br>

<label>
Estimated Annual COLA (%):
<input type="text" inputmode="decimal" name="ss_cola" value="<?= htmlspecialchars($ssColaPct === '' ? '' : (string)$ssColaPct) ?>">
</label>

<br><br>

<?php if ($calcError !== ''): ?>
<div style="margin: 8px 0 18px 0; padding: 10px 12px; border: 1px solid #d33; background: #fff5f5; max-width: 900px;">
<?= htmlspecialchars($calcError) ?>
</div>
<?php endif; ?>

<button type="submit">
Calculate for spreadsheet to appear below.
</button>

</form>

<?php if (!empty($rows)): ?>
<hr id="results">

<h3 style="font-weight: 600;">Retirement Projection Values</h3>

<p>Current Portfolio Value: $<?= number_format((float)$currentPortfolio, 0) ?></p>

<h4 style="font-weight: 600; margin-top: 14px;">Investment & Return Assumptions</h4>
<p>Expected Annual Return Rate: <?= htmlspecialchars((string)$ratePct) ?>% (daily compounding, Vanguard-style)</p>

<h4 style="font-weight: 600; margin-top: 14px;">Income Assumptions</h4>
<p>Estimated Annual Social Security Income: $<?= number_format((float)$ssAnnualIncome, 0) ?></p>
<p>Estimated Annual COLA: <?= number_format((float)$ssColaPct, 2) ?>%</p>

<table border="1" cellpadding="6" cellspacing="0">
<tr>
    <th>Year</th>
    <th>Start Balance</th>
    <th>Withdrawal</th>
    <th>Social Security Income</th>
    <th>Total Pre-Tax Income</th>
    <th>Balance After Withdrawal</th>
    <th>End Balance</th>
</tr>
<?php foreach ($rows as $row): ?>
<tr>
    <td><?= (int)$row['year'] ?></td>
    <td>$<?= number_format((float)$row['start'], 0) ?></td>
    <td>$<?= number_format((float)$row['withdrawal'], 0) ?></td>
    <td>$<?= number_format((float)$row['ss_income'], 0) ?></td>
    <td>$<?= number_format((float)$row['total_income'], 0) ?></td>
    <td>$<?= number_format((float)$row['after_withdrawal'], 0) ?></td>
    <td>$<?= number_format((float)$row['end'], 0) ?></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>