<?php
if (!defined('INCLUDED')) {
    http_response_code(403);
    exit;
}
/**
 * Analytics — Countries template
 */

$range = $_GET['range'] ?? '30d';
if (!in_array($range, ['7d', '30d', '90d', '365d'], true)) $range = '30d';

$stats     = an_build_aggregate($range);
$countries = $stats['top_countries'] ?? [];
$total     = max(1, array_sum($countries));

require_once dirname(__DIR__, 2) . '/analytics-geoip.php';
$geoInstalled = an_geoip_installed();

$ranges = [
    '7d'   => an_t('an_range_7d',   'Last 7 days',   'admin'),
    '30d'  => an_t('an_range_30d',  'Last 30 days',  'admin'),
    '90d'  => an_t('an_range_90d',  'Last 90 days',  'admin'),
    '365d' => an_t('an_range_365d', 'Last 365 days', 'admin'),
];

// ISO 3166-1 country names (subset covering ~99% of web traffic)
$countryNames = [
    'AD'=>'Andorra','AE'=>'UAE','AF'=>'Afghanistan','AG'=>'Antigua','AL'=>'Albania',
    'AM'=>'Armenia','AO'=>'Angola','AR'=>'Argentina','AT'=>'Austria','AU'=>'Australia',
    'AZ'=>'Azerbaijan','BA'=>'Bosnia','BB'=>'Barbados','BD'=>'Bangladesh','BE'=>'Belgium',
    'BF'=>'Burkina Faso','BG'=>'Bulgaria','BH'=>'Bahrain','BI'=>'Burundi','BJ'=>'Benin',
    'BN'=>'Brunei','BO'=>'Bolivia','BR'=>'Brazil','BS'=>'Bahamas','BT'=>'Bhutan',
    'BW'=>'Botswana','BY'=>'Belarus','BZ'=>'Belize','CA'=>'Canada','CD'=>'DR Congo',
    'CF'=>'CAR','CG'=>'Congo','CH'=>'Switzerland','CI'=>'Ivory Coast','CL'=>'Chile',
    'CM'=>'Cameroon','CN'=>'China','CO'=>'Colombia','CR'=>'Costa Rica','CU'=>'Cuba',
    'CV'=>'Cape Verde','CY'=>'Cyprus','CZ'=>'Czech Republic','DE'=>'Germany','DJ'=>'Djibouti',
    'DK'=>'Denmark','DM'=>'Dominica','DO'=>'Dominican Rep.','DZ'=>'Algeria','EC'=>'Ecuador',
    'EE'=>'Estonia','EG'=>'Egypt','ER'=>'Eritrea','ES'=>'Spain','ET'=>'Ethiopia',
    'FI'=>'Finland','FJ'=>'Fiji','FR'=>'France','GA'=>'Gabon','GB'=>'United Kingdom',
    'GD'=>'Grenada','GE'=>'Georgia','GH'=>'Ghana','GM'=>'Gambia','GN'=>'Guinea',
    'GQ'=>'Eq. Guinea','GR'=>'Greece','GT'=>'Guatemala','GW'=>'Guinea-Bissau','GY'=>'Guyana',
    'HN'=>'Honduras','HR'=>'Croatia','HT'=>'Haiti','HU'=>'Hungary','ID'=>'Indonesia',
    'IE'=>'Ireland','IL'=>'Israel','IN'=>'India','IQ'=>'Iraq','IR'=>'Iran',
    'IS'=>'Iceland','IT'=>'Italy','JM'=>'Jamaica','JO'=>'Jordan','JP'=>'Japan',
    'KE'=>'Kenya','KG'=>'Kyrgyzstan','KH'=>'Cambodia','KI'=>'Kiribati','KM'=>'Comoros',
    'KN'=>'St Kitts','KP'=>'North Korea','KR'=>'South Korea','KW'=>'Kuwait','KZ'=>'Kazakhstan',
    'LA'=>'Laos','LB'=>'Lebanon','LC'=>'St Lucia','LI'=>'Liechtenstein','LK'=>'Sri Lanka',
    'LR'=>'Liberia','LS'=>'Lesotho','LT'=>'Lithuania','LU'=>'Luxembourg','LV'=>'Latvia',
    'LY'=>'Libya','MA'=>'Morocco','MC'=>'Monaco','MD'=>'Moldova','ME'=>'Montenegro',
    'MG'=>'Madagascar','MH'=>'Marshall Is.','MK'=>'North Macedonia','ML'=>'Mali','MM'=>'Myanmar',
    'MN'=>'Mongolia','MR'=>'Mauritania','MT'=>'Malta','MU'=>'Mauritius','MV'=>'Maldives',
    'MW'=>'Malawi','MX'=>'Mexico','MY'=>'Malaysia','MZ'=>'Mozambique','NA'=>'Namibia',
    'NE'=>'Niger','NG'=>'Nigeria','NI'=>'Nicaragua','NL'=>'Netherlands','NO'=>'Norway',
    'NP'=>'Nepal','NR'=>'Nauru','NZ'=>'New Zealand','OM'=>'Oman','PA'=>'Panama',
    'PE'=>'Peru','PG'=>'Papua NG','PH'=>'Philippines','PK'=>'Pakistan','PL'=>'Poland',
    'PT'=>'Portugal','PW'=>'Palau','PY'=>'Paraguay','QA'=>'Qatar','RO'=>'Romania',
    'RS'=>'Serbia','RU'=>'Russia','RW'=>'Rwanda','SA'=>'Saudi Arabia','SB'=>'Solomon Is.',
    'SC'=>'Seychelles','SD'=>'Sudan','SE'=>'Sweden','SG'=>'Singapore','SI'=>'Slovenia',
    'SK'=>'Slovakia','SL'=>'Sierra Leone','SM'=>'San Marino','SN'=>'Senegal','SO'=>'Somalia',
    'SR'=>'Suriname','SS'=>'South Sudan','ST'=>'São Tomé','SV'=>'El Salvador','SY'=>'Syria',
    'SZ'=>'Eswatini','TD'=>'Chad','TG'=>'Togo','TH'=>'Thailand','TJ'=>'Tajikistan',
    'TL'=>'Timor-Leste','TM'=>'Turkmenistan','TN'=>'Tunisia','TO'=>'Tonga','TR'=>'Turkey',
    'TT'=>'Trinidad','TV'=>'Tuvalu','TZ'=>'Tanzania','UA'=>'Ukraine','UG'=>'Uganda',
    'US'=>'United States','UY'=>'Uruguay','UZ'=>'Uzbekistan','VA'=>'Vatican','VC'=>'St Vincent',
    'VE'=>'Venezuela','VN'=>'Vietnam','VU'=>'Vanuatu','WS'=>'Samoa','YE'=>'Yemen',
    'ZA'=>'South Africa','ZM'=>'Zambia','ZW'=>'Zimbabwe',
];
?>
<div class="an-wrap">

    <div class="an-range-bar">
        <?php foreach ($ranges as $key => $label): ?>
        <a href="<?php echo hsc(an_admin_page_url('countries', ['range' => $key])); ?>"
           class="an-range-btn <?php echo $range === $key ? 'active' : ''; ?>">
            <?php echo hsc($label); ?>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if (!$geoInstalled): ?>
    <div class="an-geoip-notice">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span>
            <?php echo sprintf(
                an_t('an_geoip_not_installed_notice',
                    'Country database not installed. Go to <a href="%s">Settings</a> to download it.',
                    'admin'
                ),
                hsc(an_admin_page_url('settings'))
            ); ?>
        </span>
    </div>
    <?php elseif (empty($countries)): ?>
    <div class="an-panel">
        <p class="an-empty-inline"><?php echo hsc(an_t('an_no_data_short', 'No data for this period.', 'admin')); ?></p>
    </div>
    <?php else: ?>

    <div class="an-panel">
        <h3 class="an-panel-title"><?php echo hsc(an_t('an_countries_title', 'Visitors by country', 'admin')); ?></h3>

        <table class="an-country-table">
            <tbody>
            <?php
            $rank = 0;
            $maxV = max(1, reset($countries));
            foreach ($countries as $cc => $count):
                $rank++;
                $pct    = round($count / $total * 100, 1);
                $barPct = round($count / $maxV * 100);
                $name   = $countryNames[$cc] ?? $cc;
                $flag   = an_country_flag($cc);
            ?>
            <tr>
                <td class="an-country-rank"><?php echo $rank; ?></td>
                <td class="an-country-flag" aria-hidden="true"><?php echo $flag; ?></td>
                <td class="an-country-name"><?php echo hsc($name); ?></td>
                <td class="an-country-cc"><?php echo hsc($cc); ?></td>
                <td class="an-country-bar-cell">
                    <div class="an-country-bar-track">
                        <div class="an-country-bar-fill" style="width:<?php echo $barPct; ?>%"></div>
                    </div>
                </td>
                <td class="an-country-pct"><?php echo $pct; ?>%</td>
                <td class="an-country-count"><?php echo number_format($count); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php endif; ?>

</div>
<?php

/**
 * Returns a flag emoji for an ISO 3166-1 alpha-2 country code.
 * Flag emojis are regional indicator symbols (U+1F1E6–U+1F1FF).
 */
function an_country_flag(string $cc): string
{
    $cc = strtoupper($cc);
    if (strlen($cc) !== 2 || !ctype_alpha($cc)) return '🌐';
    $flag = '';
    foreach (str_split($cc) as $char) {
        $flag .= mb_chr(0x1F1A5 + ord($char), 'UTF-8');
    }
    return $flag;
}
