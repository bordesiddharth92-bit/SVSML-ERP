<?php
/**
 * SVSML-ERP — Travel reference data (Module 9 redesign)
 *
 * Static dropdown data + row-type configuration consumed by
 * crew-travel.php, travel.php and crew-portal.php.
 *
 * The data lives in code (not in the DB) on purpose:
 *   - it changes very rarely
 *   - keeping it in code avoids an extra dropdowns.php category
 *     just for Module 9
 *   - it's easy to extend by editing this file directly
 *
 * If you need to add an airport, country or visa type, add it to the
 * relevant array below and commit. No migration needed.
 */

// =========================================================
// Indian airports (IATA — CITY)
// =========================================================
const TRAVEL_INDIAN_AIRPORTS = [
    'DEL - DELHI',           'BOM - MUMBAI',          'MAA - CHENNAI',
    'BLR - BENGALURU',       'CCU - KOLKATA',         'HYD - HYDERABAD',
    'COK - KOCHI',           'AMD - AHMEDABAD',       'GOI - GOA (DABOLIM)',
    'GOX - GOA (MOPA)',      'PNQ - PUNE',            'JAI - JAIPUR',
    'LKO - LUCKNOW',         'IXC - CHANDIGARH',      'IXB - BAGDOGRA',
    'GAU - GUWAHATI',        'BHO - BHOPAL',          'IDR - INDORE',
    'NAG - NAGPUR',          'VTZ - VISAKHAPATNAM',   'TRV - THIRUVANANTHAPURAM',
    'IXM - MADURAI',         'CJB - COIMBATORE',      'IXE - MANGALURU',
    'ATQ - AMRITSAR',        'VNS - VARANASI',        'BBI - BHUBANESWAR',
    'PAT - PATNA',           'SXR - SRINAGAR',        'IXJ - JAMMU',
    'IXL - LEH',             'RPR - RAIPUR',          'JLR - JABALPUR',
    'IXA - AGARTALA',        'IMF - IMPHAL',          'GAY - GAYA',
    'KNU - KANPUR',          'IXR - RANCHI',          'UDR - UDAIPUR',
    'JDH - JODHPUR',         'IXZ - PORT BLAIR',      'RJA - RAJAHMUNDRY',
    'TIR - TIRUPATI',        'TRZ - TIRUCHIRAPALLI',  'HBX - HUBLI',
    'STV - SURAT',           'BDQ - VADODARA',        'RAJ - RAJKOT',
    'BHJ - BHUJ',            'DED - DEHRADUN',        'PGH - PANTNAGAR',
    'DHM - DHARAMSHALA',     'KUU - KULLU',           'SLV - SHIMLA',
    'AJL - AIZAWL',          'DMU - DIMAPUR',         'TEZ - TEZPUR',
    'JRH - JORHAT',          'DIB - DIBRUGARH',       'CCJ - KOZHIKODE',
    'CNN - KANNUR',          'IXS - SILCHAR',         'SHL - SHILLONG',
    'BHU - BHAVNAGAR',       'JGA - JAMNAGAR',        'BEK - BAREILLY',
    'AGR - AGRA',            'GWL - GWALIOR',         'KQH - KISHANGARH (AJMER)',
    'BUP - BATHINDA',        'LDH - LUDHIANA',        'KUU - KULLU MANALI',
    'IXD - PRAYAGRAJ',       'GOP - GORAKHPUR',       'AIP - ADAMPUR',
    'JSA - JAISALMER',       'BKB - BIKANER',         'IXY - KANDLA',
    'PBD - PORBANDAR',       'DIU - DIU',             'IXG - BELGAUM',
    'HJR - KHAJURAHO',       'JGB - JAGDALPUR',       'KLH - KOLHAPUR',
    'BHB - BAGDOGRA',        'PYG - PAKYONG (SIKKIM)',
];

// =========================================================
// International airports (IATA — CITY) — major hubs by region
// =========================================================
const TRAVEL_INTL_AIRPORTS = [
    // Middle East
    'DXB - DUBAI',           'AUH - ABU DHABI',       'SHJ - SHARJAH',
    'DOH - DOHA',            'KWI - KUWAIT CITY',     'MCT - MUSCAT',
    'BAH - MANAMA',          'RUH - RIYADH',          'JED - JEDDAH',
    'DMM - DAMMAM',          'AMM - AMMAN',           'BEY - BEIRUT',
    'TLV - TEL AVIV',        'IKA - TEHRAN',
    // South / South-East Asia
    'KTM - KATHMANDU',       'DAC - DHAKA',           'CMB - COLOMBO',
    'MLE - MALE',            'RGN - YANGON',          'KBL - KABUL',
    'PBH - PARO',            'ISB - ISLAMABAD',       'KHI - KARACHI',
    'LHE - LAHORE',
    'SIN - SINGAPORE',       'KUL - KUALA LUMPUR',    'BKK - BANGKOK (SUVARNABHUMI)',
    'DMK - BANGKOK (DON MUEANG)','HKT - PHUKET',      'CGK - JAKARTA',
    'DPS - DENPASAR (BALI)', 'SUB - SURABAYA',        'MNL - MANILA',
    'CEB - CEBU',            'HAN - HANOI',           'SGN - HO CHI MINH',
    'PNH - PHNOM PENH',      'REP - SIEM REAP',       'VTE - VIENTIANE',
    // East Asia
    'HKG - HONG KONG',       'TPE - TAIPEI',          'PEK - BEIJING (CAPITAL)',
    'PKX - BEIJING (DAXING)','PVG - SHANGHAI (PUDONG)','SHA - SHANGHAI (HONGQIAO)',
    'CAN - GUANGZHOU',       'CTU - CHENGDU',         'XIY - XI\'AN',
    'NRT - TOKYO (NARITA)',  'HND - TOKYO (HANEDA)',  'KIX - OSAKA (KANSAI)',
    'NGO - NAGOYA',          'ICN - SEOUL (INCHEON)', 'GMP - SEOUL (GIMPO)',
    'PUS - BUSAN',           'ULN - ULAANBAATAR',
    // Europe
    'LHR - LONDON (HEATHROW)','LGW - LONDON (GATWICK)','STN - LONDON (STANSTED)',
    'LTN - LONDON (LUTON)',  'MAN - MANCHESTER',      'BHX - BIRMINGHAM',
    'DUB - DUBLIN',          'EDI - EDINBURGH',
    'CDG - PARIS (CDG)',     'ORY - PARIS (ORLY)',    'FRA - FRANKFURT',
    'MUC - MUNICH',          'TXL - BERLIN (TEGEL)',  'BER - BERLIN (BRANDENBURG)',
    'HAM - HAMBURG',         'DUS - DUSSELDORF',      'AMS - AMSTERDAM',
    'BRU - BRUSSELS',        'ZRH - ZURICH',          'GVA - GENEVA',
    'VIE - VIENNA',          'PRG - PRAGUE',          'WAW - WARSAW',
    'BUD - BUDAPEST',        'OTP - BUCHAREST',       'SOF - SOFIA',
    'BEG - BELGRADE',        'ATH - ATHENS',          'IST - ISTANBUL',
    'SAW - ISTANBUL (SABIHA GOKCEN)','FCO - ROME (FIUMICINO)','MXP - MILAN (MALPENSA)',
    'LIN - MILAN (LINATE)',  'VCE - VENICE',          'NAP - NAPLES',
    'MAD - MADRID',          'BCN - BARCELONA',       'AGP - MALAGA',
    'LIS - LISBON',          'OPO - PORTO',           'ARN - STOCKHOLM',
    'OSL - OSLO',            'CPH - COPENHAGEN',      'HEL - HELSINKI',
    'KEF - REYKJAVIK',       'SVO - MOSCOW (SHEREMETYEVO)','DME - MOSCOW (DOMODEDOVO)',
    'LED - ST. PETERSBURG',  'KBP - KYIV',
    // North America
    'JFK - NEW YORK (JFK)',  'EWR - NEWARK',          'LGA - NEW YORK (LGA)',
    'BOS - BOSTON',          'IAD - WASHINGTON (DULLES)','DCA - WASHINGTON (REAGAN)',
    'PHL - PHILADELPHIA',    'ORD - CHICAGO (O\'HARE)','MDW - CHICAGO (MIDWAY)',
    'ATL - ATLANTA',         'MIA - MIAMI',           'FLL - FORT LAUDERDALE',
    'MCO - ORLANDO',         'IAH - HOUSTON',         'DFW - DALLAS-FORT WORTH',
    'DEN - DENVER',          'SLC - SALT LAKE CITY',  'PHX - PHOENIX',
    'LAS - LAS VEGAS',       'SFO - SAN FRANCISCO',   'LAX - LOS ANGELES',
    'SAN - SAN DIEGO',       'SEA - SEATTLE',         'PDX - PORTLAND',
    'DTW - DETROIT',         'MSP - MINNEAPOLIS',     'YYZ - TORONTO',
    'YUL - MONTREAL',        'YVR - VANCOUVER',       'YYC - CALGARY',
    'MEX - MEXICO CITY',     'CUN - CANCUN',
    // Latin America
    'GRU - SAO PAULO',       'GIG - RIO DE JANEIRO',  'EZE - BUENOS AIRES',
    'SCL - SANTIAGO',        'LIM - LIMA',            'BOG - BOGOTA',
    'PTY - PANAMA CITY',     'UIO - QUITO',
    // Africa
    'JNB - JOHANNESBURG',    'CPT - CAPE TOWN',       'CAI - CAIRO',
    'NBO - NAIROBI',         'ADD - ADDIS ABABA',     'LOS - LAGOS',
    'ABV - ABUJA',           'ACC - ACCRA',           'CMN - CASABLANCA',
    'TUN - TUNIS',           'ALG - ALGIERS',         'DAR - DAR ES SALAAM',
    'EBB - ENTEBBE',         'KGL - KIGALI',          'LUN - LUSAKA',
    'HRE - HARARE',          'MRU - PORT LOUIS',      'SEZ - MAHE',
    'TNR - ANTANANARIVO',
    // Oceania
    'SYD - SYDNEY',          'MEL - MELBOURNE',       'BNE - BRISBANE',
    'PER - PERTH',           'ADL - ADELAIDE',        'AKL - AUCKLAND',
    'CHC - CHRISTCHURCH',    'NAN - NADI (FIJI)',
];

// =========================================================
// Country names (ISO 3166-1)
// =========================================================
const TRAVEL_COUNTRIES = [
    'Afghanistan','Albania','Algeria','Andorra','Angola','Antigua and Barbuda',
    'Argentina','Armenia','Australia','Austria','Azerbaijan',
    'Bahamas','Bahrain','Bangladesh','Barbados','Belarus','Belgium','Belize',
    'Benin','Bhutan','Bolivia','Bosnia and Herzegovina','Botswana','Brazil',
    'Brunei','Bulgaria','Burkina Faso','Burundi',
    'Cabo Verde','Cambodia','Cameroon','Canada','Central African Republic',
    'Chad','Chile','China','Colombia','Comoros','Congo (Brazzaville)',
    'Congo (Kinshasa)','Costa Rica','Cote d\'Ivoire','Croatia','Cuba','Cyprus',
    'Czechia',
    'Denmark','Djibouti','Dominica','Dominican Republic',
    'Ecuador','Egypt','El Salvador','Equatorial Guinea','Eritrea','Estonia',
    'Eswatini','Ethiopia',
    'Fiji','Finland','France',
    'Gabon','Gambia','Georgia','Germany','Ghana','Greece','Grenada','Guatemala',
    'Guinea','Guinea-Bissau','Guyana',
    'Haiti','Honduras','Hungary',
    'Iceland','India','Indonesia','Iran','Iraq','Ireland','Israel','Italy',
    'Jamaica','Japan','Jordan',
    'Kazakhstan','Kenya','Kiribati','Kosovo','Kuwait','Kyrgyzstan',
    'Laos','Latvia','Lebanon','Lesotho','Liberia','Libya','Liechtenstein',
    'Lithuania','Luxembourg',
    'Madagascar','Malawi','Malaysia','Maldives','Mali','Malta','Marshall Islands',
    'Mauritania','Mauritius','Mexico','Micronesia','Moldova','Monaco','Mongolia',
    'Montenegro','Morocco','Mozambique','Myanmar (Burma)',
    'Namibia','Nauru','Nepal','Netherlands','New Zealand','Nicaragua','Niger',
    'Nigeria','North Korea','North Macedonia','Norway',
    'Oman',
    'Pakistan','Palau','Palestine','Panama','Papua New Guinea','Paraguay','Peru',
    'Philippines','Poland','Portugal',
    'Qatar',
    'Romania','Russia','Rwanda',
    'Saint Kitts and Nevis','Saint Lucia','Saint Vincent and the Grenadines',
    'Samoa','San Marino','Sao Tome and Principe','Saudi Arabia','Senegal',
    'Serbia','Seychelles','Sierra Leone','Singapore','Slovakia','Slovenia',
    'Solomon Islands','Somalia','South Africa','South Korea','South Sudan',
    'Spain','Sri Lanka','Sudan','Suriname','Sweden','Switzerland','Syria',
    'Taiwan','Tajikistan','Tanzania','Thailand','Timor-Leste','Togo','Tonga',
    'Trinidad and Tobago','Tunisia','Turkey','Turkmenistan','Tuvalu',
    'Uganda','Ukraine','United Arab Emirates','United Kingdom','United States',
    'Uruguay','Uzbekistan',
    'Vanuatu','Vatican City','Venezuela','Vietnam',
    'Yemen',
    'Zambia','Zimbabwe',
];

// =========================================================
// Visa types (fixed enum)
// =========================================================
const TRAVEL_VISA_TYPES = ['VISIT', 'TRANSIT', 'WORK', 'STUDENT', 'OTHER'];

// =========================================================
// YES / NO / PENDING (used by OKTB, LG)
// =========================================================
const TRAVEL_YNP = ['YES', 'NO', 'PENDING'];

// =========================================================
// Default row catalogue
//
// Each entry maps a recognised detail_label to:
//   - row_type: internal key driving the type-aware renderer
//   - dep / arr: which side(s) are used and how to render them
//   - hint    : short help text shown to operators
//
// 'custom' is the catch-all for free-form rows.
// =========================================================
const TRAVEL_ROW_CATALOG = [
    'Flight Ticket (Domestic)' => [
        'row_type' => 'flight_domestic',
        'sides'    => ['departure', 'arrival'],
        'fields'   => ['airport', 'date', 'time'],
        'airports' => 'indian',                 // both sides Indian
        'hint'     => 'Indian airport on both sides; date + time for each leg.',
    ],
    'Flight Ticket (International)' => [
        'row_type' => 'flight_international',
        'sides'    => ['departure', 'arrival'],
        'fields'   => ['airport', 'date', 'time'],
        'airports' => 'mixed',                  // dep=Indian, arr=International
        'hint'     => 'Indian airport (departure) → International airport (arrival).',
    ],
    'Airport Name (International)' => [
        'row_type' => 'airport_intl',
        'sides'    => ['departure', 'arrival'],
        'fields'   => ['airport'],
        'airports' => 'international',
        'hint'     => 'Both sides international airports (e.g. transit on long routes).',
    ],
    'Visa Country' => [
        'row_type' => 'visa_country',
        'sides'    => ['departure'],
        'fields'   => ['country'],
        'hint'     => 'Country for which the visa is being arranged.',
    ],
    'Visa Type' => [
        'row_type' => 'visa_type',
        'sides'    => ['departure'],
        'fields'   => ['visa_type'],
        'hint'     => 'Visit / Transit / Work / Student / Other.',
    ],
    'OKTB (OK To Board)' => [
        'row_type' => 'oktb',
        'sides'    => ['departure'],
        'fields'   => ['ynp', 'detail'],
        'hint'     => 'OK-to-Board status from the airline + supporting note.',
    ],
    'LG (Landing Permission)' => [
        'row_type' => 'lg',
        'sides'    => ['departure'],
        'fields'   => ['ynp', 'detail'],
        'hint'     => 'Landing permission status + supporting note.',
    ],
];

// -------------------------------------------------------------
// Helper accessors so call sites don't `defined()` constants directly.
// -------------------------------------------------------------

/**
 * Resolve a detail_label to its row-type key. Unknown labels (or
 * field_type='custom') resolve to 'custom'.
 */
function travelRowType(string $label, string $fieldType = 'default'): string
{
    if ($fieldType === 'custom') return 'custom';
    if (isset(TRAVEL_ROW_CATALOG[$label])) {
        return TRAVEL_ROW_CATALOG[$label]['row_type'];
    }
    return 'custom';
}

/**
 * Look up the catalog entry by row_type code (or null for 'custom').
 */
function travelCatalogByType(string $rowType): ?array
{
    foreach (TRAVEL_ROW_CATALOG as $label => $cfg) {
        if ($cfg['row_type'] === $rowType) {
            return ['label' => $label] + $cfg;
        }
    }
    return null;
}

/**
 * Return the airport list applicable to a given catalog entry / side.
 *
 * For 'mixed' (Flight International), the departure side is Indian
 * and the arrival side is International. All other modes use a single
 * list per row type.
 */
function travelAirportsForSide(array $catalog, string $side): array
{
    $mode = $catalog['airports'] ?? null;
    if ($mode === 'indian')        return TRAVEL_INDIAN_AIRPORTS;
    if ($mode === 'international') return TRAVEL_INTL_AIRPORTS;
    if ($mode === 'mixed') {
        return $side === 'departure' ? TRAVEL_INDIAN_AIRPORTS : TRAVEL_INTL_AIRPORTS;
    }
    return [];
}
