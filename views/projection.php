<?php
// Simplified local version (XAMPP):
// - No portfolio start date
// - Assume Vanguard-style daily compounding (no compounding selector)
// - Social Security starts immediately (no start-year field)
// - Use POST so we don't clutter the URL and we avoid Safari showing a long query string

$sessionStarted = false;
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    $sessionStarted = true;
}

$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

$showResults = (($_GET['show'] ?? '') === '1');

$downloadCsv = (($_GET['download'] ?? '') === '1');

// If the user clicks the CSV download link, stream the last generated table as a CSV file.
// This reads from the session (set during the POST/Redirect/GET flow).
if (!$isPost && $downloadCsv && isset($_SESSION['last_results']['rows'])) {
    $rowsForCsv = $_SESSION['last_results']['rows'];

    $filename = 'retirement-projection-' . date('Y-m-d') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');

    // Optional: UTF-8 BOM for Excel compatibility
    fwrite($out, "\xEF\xBB\xBF");

    // Header row (matches the on-screen table columns)
    fputcsv($out, [
        'Year',
        'Start Balance',
        'Withdrawal',
        'Social Security Income',
        'Total Pre-Tax Income',
        'Balance After Withdrawal',
        'End Balance'
    ]);

    foreach ($rowsForCsv as $r) {
        fputcsv($out, [
            (int)($r['year'] ?? 0),
            isset($r['start']) ? number_format((float)$r['start'], 2, '.', '') : '',
            isset($r['withdrawal']) ? number_format((float)$r['withdrawal'], 2, '.', '') : '',
            isset($r['ss_income']) ? number_format((float)$r['ss_income'], 2, '.', '') : '',
            isset($r['total_income']) ? number_format((float)$r['total_income'], 2, '.', '') : '',
            isset($r['after_withdrawal']) ? number_format((float)$r['after_withdrawal'], 2, '.', '') : '',
            isset($r['end']) ? number_format((float)$r['end'], 2, '.', '') : '',
        ]);
    }

    fclose($out);
    exit;
}

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

function post_date(string $key) {
    $v = post_str($key, '');
    if ($v === '') return '';
    // Expect HTML date input format: YYYY-MM-DD
    $dt = DateTime::createFromFormat('Y-m-d', $v);
    if (!$dt) return '';
    $errors = DateTime::getLastErrors();
    if ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) return '';
    return $dt->format('Y-m-d');
}

function fmt_date(string $ymd): string {
    if ($ymd === '') return '';
    try {
        $d = new DateTime($ymd);
        return $d->format('m/d/Y');
    } catch (Exception $e) {
        return $ymd;
    }
}

function pct_to_decimal($pct) {
    if ($pct === '') return '';
    return ((float)$pct) / 100;
}

/* Inputs (POST only) */
$currentPortfolio     = $isPost ? post_float('current_portfolio') : '';
$portfolioAsOfDate    = $isPost ? post_date('portfolio_as_of_date') : date('Y-m-d');
$withdrawalDate       = $isPost ? post_date('withdrawal_date') : '';

$ratePct              = $isPost ? post_float('rate') : ''; // percent (e.g., 8)

$firstYearWithdrawal  = $isPost ? post_float('first_year_withdrawal') : '';
$withdrawRatePct      = $isPost ? post_float('withdraw_rate') : '';
$years                = $isPost ? post_int('years') : '';

$ssAnnualIncome       = $isPost ? post_float('ss_annual_income') : '';
$ssColaPct            = $isPost ? post_float('ss_cola') : '';

/* Display-only values for the Results section (so we can clear the form without blanking the results) */
$displayCurrentPortfolio     = '';
$displayPortfolioAsOfDate    = '';
$displayWithdrawalDate       = '';
$displayFuturePortfolioValue = '';
$displayRatePct              = '';
$displaySsAnnualIncome       = '';
$displaySsColaPct            = '';

/* Percents -> decimals */
$rate         = ($ratePct === '' ? '' : pct_to_decimal($ratePct));
$withdrawRate = ($withdrawRatePct === '' ? '' : pct_to_decimal($withdrawRatePct));
$ssCola       = ($ssColaPct === '' ? '' : pct_to_decimal($ssColaPct));

/* Derived */
$start = ($currentPortfolio === '' ? '' : (float)$currentPortfolio);
$startYear = (int)date('Y');

$futurePortfolioValue = '';
$daysToWithdrawal = '';

if ($start !== '' && $rate !== '' && $portfolioAsOfDate !== '' && $withdrawalDate !== '') {
    try {
        $asOf = new DateTime($portfolioAsOfDate);
        $wd   = new DateTime($withdrawalDate);

        // Only compute when withdrawal date is same day or later than "as of" date
        if ($wd >= $asOf) {
            $interval = $asOf->diff($wd);
            $daysToWithdrawal = (int)$interval->days;

            // Vanguard-style daily compounding over the exact day count
            $futurePortfolioValue = (float)$start * pow(1 + ((float)$rate / 365), $daysToWithdrawal);

            // Use the withdrawal date year as the first projection year
            $startYear = (int)$wd->format('Y');

            // And use the future value as the starting balance for the projection table
            $start = (float)$futurePortfolioValue;
        }
    } catch (Exception $e) {
        // Leave computed fields blank on invalid dates
        $futurePortfolioValue = '';
        $daysToWithdrawal = '';
    }
}

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
     $ssCola !== '' &&
     $portfolioAsOfDate !== '' &&
     $withdrawalDate !== '' &&
     ($futurePortfolioValue !== ''));

$calcError = '';
if ($isPost && !$canBuildTable) {
    $missing = [];
    if ($start === '') $missing[] = 'Current Portfolio Value ($)';
    if ($rate === '') $missing[] = 'Expected Annual Return Rate (%)';
    if ($portfolioAsOfDate === '') $missing[] = 'Current Portfolio Value as of Date';
    if ($withdrawalDate === '') $missing[] = 'Date of Expected Withdrawals from Portfolio';
    if ($portfolioAsOfDate !== '' && $withdrawalDate !== '' && $futurePortfolioValue === '') $missing[] = 'Withdrawal date must be on or after the portfolio "as of" date';
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

    // Persist results so the form can clear after refresh (POST/Redirect/GET)
    $_SESSION['last_results'] = [
        'rows' => $rows,
        'summary' => [
            'currentPortfolio'     => $currentPortfolio,
            'portfolioAsOfDate'    => $portfolioAsOfDate,
            'withdrawalDate'       => $withdrawalDate,
            'futurePortfolioValue' => $futurePortfolioValue,
            'ratePct'              => $ratePct,
            'firstYearWithdrawal'  => $firstYearWithdrawal,
            'withdrawRatePct'      => $withdrawRatePct,
            'years'                => $years,
            'ssAnnualIncome'       => $ssAnnualIncome,
            'ssColaPct'            => $ssColaPct,
        ],
    ];

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Location: ' . $_SERVER['PHP_SELF'] . '?show=1&ts=' . time() . '#results');
    exit;
}

if (!$isPost && $showResults && isset($_SESSION['last_results']['rows'])) {
    $rows = $_SESSION['last_results']['rows'];

    $summary = $_SESSION['last_results']['summary'] ?? [];

    // Values for display in the Results section
    $displayCurrentPortfolio     = $summary['currentPortfolio'] ?? '';
    $displayPortfolioAsOfDate    = $summary['portfolioAsOfDate'] ?? date('Y-m-d');
    $displayWithdrawalDate       = $summary['withdrawalDate'] ?? '';
    $displayFuturePortfolioValue = $summary['futurePortfolioValue'] ?? '';
    $displayRatePct              = $summary['ratePct'] ?? '';
    $displaySsAnnualIncome       = $summary['ssAnnualIncome'] ?? '';
    $displaySsColaPct            = $summary['ssColaPct'] ?? '';

    // Repopulate form fields from the last run so the user can make small adjustments
    $currentPortfolio     = $displayCurrentPortfolio;
    $portfolioAsOfDate    = ($displayPortfolioAsOfDate !== '' ? $displayPortfolioAsOfDate : date('Y-m-d'));
    $withdrawalDate       = $displayWithdrawalDate;
    $futurePortfolioValue = $displayFuturePortfolioValue;

    $ratePct              = $displayRatePct;

    $firstYearWithdrawal  = $summary['firstYearWithdrawal'] ?? '';
    $withdrawRatePct      = $summary['withdrawRatePct'] ?? '';
    $years                = $summary['years'] ?? '';

    $ssAnnualIncome       = $displaySsAnnualIncome;
    $ssColaPct            = $displaySsColaPct;
}
?>
<link rel="stylesheet" href="/css/styles.css?v=3">
<div class="page container" style="max-width: 1100px; margin: 0 auto; padding: 24px 24px 40px;">
<div class="top-nav" style="margin-bottom: 12px;">
  <a class="home-btn" href="/" style="display:inline-flex; align-items:center; padding:8px 14px; border-radius:999px; border:1px solid #e5e7eb; background:#ffffff; color:#111827; text-decoration:none; font-weight:600; line-height:1; white-space:nowrap;">
    Return to home page
  </a>
</div>

<h1 style="text-align: center; margin: 0 0 18px 0;">Retirement Income Projection</h1>

<p class="intro">
This calculator helps individuals and couples estimate how two major retirement income sources can work together over time: Social Security benefits and withdrawals from an investment portfolio (such as a 401(k) or IRA). Enter your current portfolio value, expected return assumptions, withdrawal approach, and Social Security estimates (including COLA) to generate a year-by-year projection. The results are meant to support planning and “what-if” testing—not to predict markets—so you can see how different assumptions may affect income and portfolio sustainability. Portfolio growth is modeled using Vanguard-style daily compounding.
</p>

<form id="projection-form" method="post" action="">

<label>
Current Portfolio Value ($):
<input type="text" inputmode="decimal" name="current_portfolio" value="<?= htmlspecialchars($currentPortfolio === '' ? '' : (string)$currentPortfolio) ?>">
</label>

<br><br>

<div style="margin: 12px 0 18px 0;">
  <label for="portfolio_as_of_date_display" style="display:block; margin-bottom:6px; font-weight:600;">
    Current Portfolio Value as of:
  </label>
  <input
    type="text"
    id="portfolio_as_of_date_display"
    inputmode="numeric"
    placeholder="MM/DD/YYYY"
    value="<?= htmlspecialchars($portfolioAsOfDate === '' ? '' : fmt_date((string)$portfolioAsOfDate)) ?>"
    style="width: 100%; max-width: 260px; padding: 8px;"
    autocomplete="off"
  >
  <input type="hidden" name="portfolio_as_of_date" id="portfolio_as_of_date" value="<?= htmlspecialchars($portfolioAsOfDate === '' ? date('Y-m-d') : (string)$portfolioAsOfDate) ?>">
  <small style="color: #666; display:block; margin-top:4px;">Type the date (e.g. 01/15/2025)—no calendar scrolling.</small>
</div>

<div style="margin: 0 0 24px 0;">
  <label for="withdrawal_date_display" style="display:block; margin-bottom:6px; font-weight:600;">
    Date of Expected Withdrawals from Portfolio:
  </label>
  <input
    type="text"
    id="withdrawal_date_display"
    inputmode="numeric"
    placeholder="MM/DD/YYYY"
    value="<?= htmlspecialchars($withdrawalDate === '' ? '' : fmt_date((string)$withdrawalDate)) ?>"
    style="width: 100%; max-width: 260px; padding: 8px;"
    autocomplete="off"
  >
  <input type="hidden" name="withdrawal_date" id="withdrawal_date" value="<?= htmlspecialchars($withdrawalDate === '' ? '' : (string)$withdrawalDate) ?>">
  <small style="color: #666; display:block; margin-top:4px;">Type date (e.g. 06/01/2026).</small>
</div>

<?php if ($futurePortfolioValue !== '' && $withdrawalDate !== ''): ?>
<p style="margin: 0 0 18px 0;">
Future Portfolio Value on <?= htmlspecialchars(fmt_date((string)$withdrawalDate)) ?>: $<?= number_format((float)$futurePortfolioValue, 0) ?>
</p>
<?php endif; ?>

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

<script>
(function() {
  function parseToYmd(val) {
    if (!val || typeof val !== 'string') return '';
    val = val.trim();
    var m, d, y;
    if (/^\d{4}-\d{1,2}-\d{1,2}$/.test(val)) {
      var p = val.split('-');
      y = parseInt(p[0], 10);
      m = parseInt(p[1], 10);
      d = parseInt(p[2], 10);
    } else {
      var parts = val.split(/[\/\-\.]/);
      if (parts.length >= 3) {
        if (parts[0].length === 4) {
          y = parseInt(parts[0], 10);
          m = parseInt(parts[1], 10);
          d = parseInt(parts[2], 10);
        } else {
          m = parseInt(parts[0], 10);
          d = parseInt(parts[1], 10);
          y = parseInt(parts[2], 10);
        }
      } else return '';
    }
    if (isNaN(m) || isNaN(d) || isNaN(y) || m < 1 || m > 12 || d < 1 || d > 31) return '';
    var date = new Date(y, m - 1, d);
    if (date.getFullYear() !== y || date.getMonth() !== m - 1 || date.getDate() !== d) return '';
    return y + '-' + String(m).padStart(2, '0') + '-' + String(d).padStart(2, '0');
  }
  function syncDisplayToHidden(displayId, hiddenId) {
    var disp = document.getElementById(displayId);
    var hid = document.getElementById(hiddenId);
    if (!disp || !hid) return;
    var ymd = parseToYmd(disp.value);
    if (ymd) hid.value = ymd;
  }
  var form = document.getElementById('projection-form');
  if (form) {
    ['portfolio_as_of_date_display', 'withdrawal_date_display'].forEach(function(displayId, i) {
      var hidId = displayId.replace('_display', '');
      var disp = document.getElementById(displayId);
      if (disp) {
        disp.addEventListener('change', function() { syncDisplayToHidden(displayId, hidId); });
        disp.addEventListener('blur', function() { syncDisplayToHidden(displayId, hidId); });
      }
    });
    form.addEventListener('submit', function() {
      syncDisplayToHidden('portfolio_as_of_date_display', 'portfolio_as_of_date');
      syncDisplayToHidden('withdrawal_date_display', 'withdrawal_date');
    });
  }
})();
</script>

<?php if (!empty($rows)): ?>
<?php
$resultsPortfolioAsOfDate = ($displayPortfolioAsOfDate !== '' ? $displayPortfolioAsOfDate : $portfolioAsOfDate);
$resultsCurrentPortfolio  = ($displayCurrentPortfolio !== '' ? $displayCurrentPortfolio : $currentPortfolio);
$resultsWithdrawalDate    = ($displayWithdrawalDate !== '' ? $displayWithdrawalDate : $withdrawalDate);
$resultsFutureValue       = ($displayFuturePortfolioValue !== '' ? $displayFuturePortfolioValue : $futurePortfolioValue);
$resultsRatePct           = ($displayRatePct !== '' ? $displayRatePct : $ratePct);
$resultsSsAnnualIncome    = ($displaySsAnnualIncome !== '' ? $displaySsAnnualIncome : $ssAnnualIncome);
$resultsSsColaPct         = ($displaySsColaPct !== '' ? $displaySsColaPct : $ssColaPct);
?>
<hr id="results">

<h3 style="font-weight: 600;">Retirement Projection Values</h3>
<p style="margin: 6px 0 18px 0;">
  <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>?download=1&amp;ts=<?= time() ?>">Download spreadsheet as CSV</a>
</p>

<p>Current Portfolio Value as of <?= htmlspecialchars(fmt_date((string)$resultsPortfolioAsOfDate)) ?>: $<?= number_format((float)$resultsCurrentPortfolio, 0) ?></p>

<?php if ($resultsFutureValue !== '' && $resultsWithdrawalDate !== ''): ?>
<p>Future Portfolio Value on <?= htmlspecialchars(fmt_date((string)$resultsWithdrawalDate)) ?>: $<?= number_format((float)$resultsFutureValue, 0) ?></p>
<?php endif; ?>

<h4 style="font-weight: 600; margin-top: 14px;">Investment & Return Assumptions</h4>
<p>Expected Annual Return Rate: <?= htmlspecialchars((string)$resultsRatePct) ?>% (daily compounding, Vanguard-style)</p>

<h4 style="font-weight: 600; margin-top: 14px;">Income Assumptions</h4>
<p>Estimated Annual Social Security Income: $<?= number_format((float)$resultsSsAnnualIncome, 0) ?></p>
<p>Estimated Annual COLA: <?= number_format((float)$resultsSsColaPct, 2) ?>%</p>

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
<hr class="footer-divider" style="margin: 24px 0 0; border: 0; border-top: 1px solid #d0d0d0;">
<?php
  // Shared footer (used across all apps)
  $footerPath = __DIR__ . '/../../../includes/footer.php';
  if (file_exists($footerPath)) {
    include $footerPath;
  } else {
?>
  <div class="site-footer">
    <div class="site-footer__left">© 2026 Ron Belisle</div>
    <div class="site-footer__right">
      <span>If these tools are useful, please consider supporting future development.</span>
      <a class="donate-btn" href="https://www.paypal.com/paypalme/rongbelisle" target="_blank" rel="noopener">Donate</a>
    </div>
  </div>
<?php
  }
?>
</div>
<script>
(function () {
  function isReload() {
    try {
      const navEntries = performance.getEntriesByType && performance.getEntriesByType('navigation');
      if (navEntries && navEntries.length) return navEntries[0].type === 'reload';
      return performance && performance.navigation && performance.navigation.type === 1;
    } catch (e) {
      return false;
    }
  }

  if (!isReload()) return;

  const form = document.getElementById('projection-form');
  if (!form) return;

  const today = new Date().toISOString().slice(0, 10);
  const d = new Date(today);
  const todayMdY = (d.getMonth() + 1) + '/' + d.getDate() + '/' + d.getFullYear();

  form.querySelectorAll('input[name]').forEach((input) => {
    if (input.name === 'portfolio_as_of_date') {
      input.value = today;
      var disp = document.getElementById('portfolio_as_of_date_display');
      if (disp) disp.value = todayMdY;
    } else {
      input.value = '';
    }
  });
  var withdrawalDisp = document.getElementById('withdrawal_date_display');
  if (withdrawalDisp) withdrawalDisp.value = '';
})();
</script>
