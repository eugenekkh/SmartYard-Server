<?php

namespace hw\ip\domophone\ufanet;

use Generator;
use hw\ip\domophone\domophone;

/**
 * Abstract class representing an Ufanet intercom.
 */
abstract class ufanet extends domophone
{

    use \hw\ip\common\ufanet\ufanet;

    /** @var array Set of parameters sent to the intercom for different CMS models. */
    protected const CMS_PARAMS = [
        'BK-100' => ['type' => 'VIZIT', 'mode' => 2], // TODO: check mode 1 and mode 2
        'BK-400' => ['type' => 'VIZIT', 'mode' => 3],
        'COM-25U' => ['type' => 'METAKOM'],
        'COM-100U' => ['type' => 'METAKOM'],
        'COM-220U' => ['type' => 'METAKOM'],
        'FACTORIAL 8x8' => ['type' => 'FACTORIAL'],
        'KKM-100S2' => ['type' => 'BEWARD_100'],
        'KKM-105' => ['type' => 'BEWARD_105_108'],
        'KKM-108' => ['type' => 'BEWARD_105_108'],
        'KM20-1' => ['type' => 'ELTIS', 'mode' => 1, 'edge' => 20],
        'KM100-7.1' => ['type' => 'ELTIS', 'mode' => 1, 'edge' => 100],
        'KM100-7.2' => ['type' => 'ELTIS', 'mode' => 1, 'edge' => 100],
        'KM100-7.3' => ['type' => 'ELTIS', 'mode' => 1, 'edge' => 100],
        'KM100-7.5' => ['type' => 'ELTIS', 'mode' => 1, 'edge' => 100],
        'KMG-100' => ['type' => 'CYFRAL', 'mode' => 1, 'edge' => 100],
        'QAD-100' => ['type' => 'DIGITAL'],
    ];

    protected const PERSONAL_CODE_RFID_DATA_REGEXP = '/^(\d+);3$/';

    /** @var array|null $dialplans An array that holds dialplan information, which may be null if not loaded. */
    protected ?array $dialplans = null;

    /** @var array|null $rfids An array that holds RFID codes information, which may be null if not loaded. */
    protected ?array $rfids = null;

    protected ?string $cmsModelName = null;

    public function addRfid(string $code, int $apartment = 0, int $type = 1): void
    {
        $this->loadRfids();

        $rfidData = "$apartment;$type";

        if ($type === 3) {
            $this->rfids[substr($code, -5)] = $rfidData;
            return;
        }

        $lowercaseCode = strtolower($code);
        $internalRfid = substr($lowercaseCode, 6);
        $externalRfid = '00' . substr($lowercaseCode, 8);

        $this->rfids[$internalRfid] = $rfidData;
        $this->rfids[$externalRfid] = $rfidData;
    }

    public function addRfids(array $rfids): void
    {
        foreach ($rfids as $rfid) {
            $this->addRfid($rfid);
        }
    }

    public function configureApartment(
        int   $apartment,
        int   $code = 0,
        array $sipNumbers = [],
        bool  $cmsEnabled = true,
        array $cmsLevels = []
    ): void
    {
        $this->loadDialplans();

        $this->cleanupApartmentPersonalCodes($apartment);
        if ($code !== 0) {
            $this->addRfid(
                code: (string)$code,
                apartment: $apartment,
                type: 3,
            );
        }

        $this->dialplans[$apartment] = [
            'sip_number' => "$sipNumbers[0]" ?? '',
            'sip' => true,
            'analog' => $cmsEnabled,
            'map' => $this->dialplans[$apartment]['map'] ?? 0,
        ];
    }

    public function configureEncoding(): void
    {
        $this->apiCall('/cgi-bin/configManager.cgi', 'GET', [
            'action' => 'setConfig',

            // Audio stream
            'Encode[0].MainFormat[0].AudioEnable' => 'true',
            'Encode[0].MainFormat[0].Audio.Compression' => 'alaw',
            'Encode[0].MainFormat[0].Audio.Frequency' => 8000,

            // Video main stream
            'Encode[0].MainFormat[0].VideoEnable' => 'true',
            'Encode[0].MainFormat[0].Video.Compression' => 'h264',
            'Encode[0].MainFormat[0].Video.resolution' => '1280x720',
            'Encode[0].MainFormat[0].Video.FPS' => 15,
            'Encode[0].MainFormat[0].Video.GOP' => 1,
            'Encode[0].MainFormat[0].Video.GOPmode' => 'normal',
            'Encode[0].MainFormat[0].Video.BitRate' => 1024,
            'Encode[0].MainFormat[0].Video.BitRateControl' => 'vbr',

            // Video extra stream
            'Encode[0].ExtraFormat[0].VideoEnable' => 'false',
            'Encode[0].ExtraFormat[0].Video.Compression' => 'h264',
            'Encode[0].ExtraFormat[0].Video.resolution' => '640x352',
            'Encode[0].ExtraFormat[0].Video.FPS' => 25,
            'Encode[0].ExtraFormat[0].Video.GOP' => 0.5,
            'Encode[0].ExtraFormat[0].Video.GOPmode' => 'normal',
            'Encode[0].ExtraFormat[0].Video.BitRate' => 348,
            'Encode[0].ExtraFormat[0].Video.BitRateControl' => 'avbr',
        ]);
    }

    public function configureGate(array $links = []): void
    {
        if (empty($links)) {
            return;
        }

        $this->apiCall('/api/v1/configuration', 'PATCH', [
            'commutator' => [
                'type' => 'GATE',
                'mode' => 1,
            ],
        ]);
    }

    public function configureMatrix(array $matrix): void
    {
        $remappedMatrix = $this->remapMatrix($matrix);

        foreach ($this->getApartmentsDialplans(true) as $apartment => $dialplan) {
            foreach ($remappedMatrix as $cell) {
                if ($apartment === $cell['apartment']) {
                    $apartment = $cell['apartment'];
                    $line = $cell['hundreds'] * 100 + $cell['tens'] * 10 + $cell['units'];
                    $this->dialplans[$apartment]['map'] = $line;
                    continue 2;
                }
            }

            $this->dialplans[$apartment]['map'] = 0;
        }
    }

    public function configureSip(
        string $login,
        string $password,
        string $server,
        int    $port = 5060,
        bool   $stunEnabled = false,
        string $stunServer = '',
        int    $stunPort = 3478
    ): void
    {
        $this->apiCall('/api/v1/configuration', 'PATCH', [
            'sip' => [
                'domain' => "$server:$port",
                'user' => $login,
                'password' => $password,
            ],
        ]);
    }

    public function configureUserAccount(string $password): void
    {
        // Empty implementation
    }

    public function deleteApartment(int $apartment = 0): void
    {
        $this->loadDialplans();

        ['map' => $analogReplace, 'analog' => $cmsEnabled] = $this->dialplans[$apartment];

        if ($analogReplace !== 0) {
            $this->dialplans[$apartment] = [
                'sip_number' => '',
                'sip' => false,
                'analog' => $cmsEnabled,
                'map' => $analogReplace,
            ];
        } else {
            unset($this->dialplans[$apartment]);
        }
    }

    public function deleteRfid(string $code = ''): void
    {
        $this->loadRfids();

        if ($code === '') {
            $this->rfids = [];
        } else {
            $lowercaseCode = strtolower($code);
            $internalRfid = substr($lowercaseCode, 6);
            $externalRfid = '00' . substr($lowercaseCode, 8);
            $personalEntryCode = substr($lowercaseCode, -5);
            unset($this->rfids[$internalRfid], $this->rfids[$externalRfid], $this->rfids[$personalEntryCode]);
        }
    }

    public function getLineDiagnostics(int $apartment): string|int|float
    {
        return 0;
    }

    public function openLock(int $lockNumber = 0): void
    {
        $lockNumber++;
        $this->apiCall("/api/v1/doors/$lockNumber/open", 'POST', null, 3);
    }

    public function prepare(): void
    {
        parent::prepare();
        $this->setNetwork();
        $this->setRfidMode();
        $this->setDisplayLocalization();
    }

    public function setAudioLevels(array $levels): void
    {
        if (count($levels) === 2) {
            $this->apiCall('/api/v1/configuration', 'PATCH', [
                'volume' => [
                    'speaker' => $levels[0],
                    'mic' => $levels[1],
                ],
            ]);
        }
    }

    public function setCallTimeout(int $timeout): void
    {
        // Empty implementation
    }

    public function setCmsLevels(array $levels): void
    {
        // Empty implementation
    }

    public function setCmsModel(string $model = ''): void
    {
        $this->apiCall('/api/v1/configuration', 'PATCH', ['commutator' => self::CMS_PARAMS[$model] ?? []]);
    }

    public function setConciergeNumber(int $sipNumber): void
    {
        $this->loadDialplans();

        $this->dialplans['CONS'] = [
            'sip_number' => "$sipNumber",
            'analog' => false,
            'sip' => true,
            'map' => 0,
        ];
    }

    public function setDtmfCodes(
        string $code1 = '1',
        string $code2 = '2',
        string $code3 = '3',
        string $codeCms = '1'
    ): void
    {
        $this->apiCall('/api/v1/configuration', 'PATCH', [
            'door' => [
                'dtmf_open_local' => [$code1, $code2],
                'dtmf_open_remote' => $codeCms,
            ],
        ]);
    }

    public function setLanguage(string $language = 'ru'): void
    {
        // Empty implementation
    }

    public function setPublicCode(int $code = 0): void
    {
        // Empty implementation
    }

    public function setSosNumber(int $sipNumber): void
    {
        $this->loadDialplans();

        $this->dialplans['SOS'] = [
            'sip_number' => "$sipNumber",
            'analog' => false,
            'sip' => true,
            'map' => 0,
        ];
    }

    public function setTalkTimeout(int $timeout): void
    {
        // Empty implementation
    }

    public function setTickerText(string $text = ''): void
    {
        $this->apiCall('/api/v1/configuration', 'PATCH', ['display' => ['labels' => [$text, '', '']]]);
    }

    public function setUnlockTime(int $time = 3): void
    {
        $this->apiCall('/api/v1/configuration', 'PATCH', ['door' => ['open_time' => $time]]);
    }

    public function setUnlocked(bool $unlocked = true): void
    {
        $this->apiCall('/api/v1/configuration', 'PATCH', [
            'door' => [
                'unlock' => $unlocked ? '3000-01-01 00:00:00' : '',
            ],
        ]);
    }

    public function syncData(): void
    {
        $this->uploadDialplans();
        $this->uploadRfids();
        $this->setCmsRange();
    }

    public function transformDbConfig(array $dbConfig): array
    {
        if ($dbConfig['cmsModel'] !== '') {
            $cmsType = self::CMS_PARAMS[$dbConfig['cmsModel']]['type'];
            $this->cmsModelName = $dbConfig['cmsModel'];
            if (in_array($cmsType, ['METAKOM', 'ELTIS', 'BEWARD_105_108'])) {
                $dbConfig['cmsModel'] = $cmsType;
            }

            $dbConfig['matrix'] = $this->remapMatrix($dbConfig['matrix'], $dbConfig['apartments']);
        }

        $dbConfig['cmsLevels'] = [];

        $dbConfig['sip']['stunEnabled'] = false;
        $dbConfig['sip']['stunServer'] = '';
        $dbConfig['sip']['stunPort'] = 3478;

        foreach ($dbConfig['apartments'] as &$apartment) {
            $apartment['cmsLevels'] = [];
        }

        if (!empty($dbConfig['gateLinks'])) {
            unset($dbConfig['gateLinks']);

            $dbConfig['gateLinks'][] = [
                'address' => '',
                'prefix' => 0,
                'firstFlat' => 1,
                'lastFlat' => 1,
            ];
        }

        return $dbConfig;
    }

    protected function getApartments(): array
    {
        $this->loadDialplans();
        $this->loadRfids();

        $apartments = [];

        foreach ($this->dialplans as $apartmentNumber => $dialplan) {
            if ($dialplan['sip'] === false || in_array($apartmentNumber, ['SOS', 'CONS', 'KALITKA', 'FRSI'])) {
                continue;
            }

            // The Ufanet intercom stores personal entry codes as keys. Restore structure that configurator expects
            $currentCode = 0;
            foreach ($this->rfids as $code => $data) {
                if (preg_match(self::PERSONAL_CODE_RFID_DATA_REGEXP, $data, $matches)) {
                    if ($matches[1] == $apartmentNumber) {
                        // Force SmartConfigurator to reconfigure apartment if somehow multiple personal codes exists
                        if ($currentCode !== 0) {
                            $currentCode = -1;
                            break;
                        } else {
                            $currentCode = $code;
                        }
                    }
                }
            }

            $apartments[$apartmentNumber] = [
                'apartment' => $apartmentNumber,
                'code' => $currentCode,
                'sipNumbers' => [$dialplan['sip_number']],
                'cmsEnabled' => $dialplan['analog'],
                'cmsLevels' => [],
            ];
        }

        return $apartments;
    }

    protected function getAudioLevels(): array
    {
        $volume = $this->apiCall('/api/v1/configuration')['volume'];
        return [$volume['speaker'], $volume['mic']];
    }

    protected function getCmsLevels(): array
    {
        return [];
    }

    protected function getCmsModel(): string
    {
        ['type' => $rawType, 'mode' => $mode] = $this->apiCall('/api/v1/configuration')['commutator'];

        return match ($rawType) {
            'DIGITAL' => 'QAD-100',
            'CYFRAL' => 'KMG-100',
            'FACTORIAL' => 'FACTORIAL 8x8',
            'BEWARD_100' => 'KKM-100S2',
            'VIZIT' => match ($mode) {
                2 => 'BK-100',
                3 => 'BK-400',
                default => $rawType,
            },
            default => $rawType,
        };
    }

    protected function getDtmfConfig(): array
    {
        $doorConfig = $this->apiCall('/api/v1/configuration')['door'];

        $dtmfLocal = $doorConfig['dtmf_open_local'] ?? ['1', '2'];
        $dtmfRemote = $doorConfig['dtmf_open_remote'] ?? '1';

        return [
            'code1' => $dtmfLocal[0] ?? '1',
            'code2' => $dtmfLocal[1] ?? '2',
            'code3' => '3',
            'codeCms' => $dtmfRemote,
        ];
    }

    protected function getGateConfig(): array
    {
        ['type' => $type, 'mode' => $mode] = $this->apiCall('/api/v1/configuration')['commutator'];

        if ($type === 'GATE' && $mode === 1) {
            return [[
                'address' => '',
                'prefix' => 0,
                'firstFlat' => 1,
                'lastFlat' => 1,
            ]];
        }

        return [];
    }

    protected function getMatrix(): array
    {
        $this->loadDialplans();

        $matrix = [];
        foreach ($this->getApartmentsDialplans() as $apartment => $dialplan) {
            if (!isset($this->dialplans[$apartment])) {
                continue;
            }

            $cell = self::getMatrixCell($dialplan['map'], $apartment);
            $matrix[$cell['index']] = $cell['value'];
        }

        return $matrix;
    }

    protected function getRfids(): array
    {
        $this->loadRfids();

        $uniqueRfids = [];

        // Get RFIDs and remove leading zeros
        $normalizedRfids = [];
        foreach ($this->rfids as $rfid => $data) {
            $normalizedRfids[ltrim($rfid, '0')] = $data;
        }

        // Identify unique RFIDs
        foreach ($normalizedRfids as $rfid => $data) {
            // Skip personal codes
            if (preg_match(self::PERSONAL_CODE_RFID_DATA_REGEXP, $data)) {
                continue;
            }

            $isUnique = true;

            foreach ($normalizedRfids as $compareRfid) {
                if ($rfid !== $compareRfid && str_contains($compareRfid, $rfid)) {
                    $isUnique = false;
                    break;
                }
            }

            if ($isUnique) {
                $uniqueRfids[] = $rfid;
            }
        }

        // Convert RFIDs to uppercase and pad them with leading zeros
        return array_map(fn($rfid) => str_pad(strtoupper($rfid), 14, '0', STR_PAD_LEFT), $uniqueRfids);
    }

    protected function cleanupApartmentPersonalCodes(int $apartment): void
    {
        $this->rfids = array_filter($this->rfids, function (string $data) use ($apartment) {
            return "$apartment;3" != $data;
        });
    }

    protected function getSipConfig(): array
    {
        [
            'domain' => $domain,
            'user' => $user,
            'password' => $password,
        ] = $this->apiCall('/api/v1/configuration')['sip'];

        [$server, $port] = explode(':', $domain, 2);

        return [
            'server' => $server,
            'port' => $port,
            'login' => $user,
            'password' => $password,
            'stunEnabled' => false,
            'stunServer' => '',
            'stunPort' => 3478,
        ];
    }

    protected function getTickerText(): string
    {
        return $this->apiCall('/api/v1/configuration')['display']['labels'][0] ?? '';
    }

    protected function getUnlocked(): bool
    {
        return $this->apiCall('/api/v1/configuration')['door']['unlock'] !== '';
    }

    /**
     * Load and cache dialplans from the API if they haven't been loaded already.
     *
     * @return void
     */
    protected function loadDialplans(): void
    {
        if ($this->dialplans === null) {
            $this->dialplans = $this->apiCall('/api/v1/apartments') ?? [];
        }
    }

    /**
     * Load and cache RFID codes from the API if they haven't been loaded already.
     *
     * @return void
     */
    protected function loadRfids(): void
    {
        if ($this->rfids === null) {
            $this->rfids = $this->apiCall('/api/v1/rfids') ?? [];
        }
    }

    /**
     * Set CMS range based on apartment numbers.
     *
     * @return void
     */
    protected function setCmsRange(): void
    {
        $apartmentNumbers = array_keys($this->getApartments());

        $minApartmentNumber = $apartmentNumbers ? min($apartmentNumbers) : 0;
        $maxApartmentNumber = $apartmentNumbers ? max($apartmentNumbers) : 0;

        $params = [
            'ap_min' => $minApartmentNumber,
            'ap_max' => $maxApartmentNumber,
        ];

        // Set cross numbering mode for CMS if device is not in gate mode
        if (empty($this->getGateConfig()) && $this->getCmsModel() !== 'BK-400') {
            $isCrossNumbering = $minApartmentNumber !== $maxApartmentNumber &&
                intdiv($minApartmentNumber, 100) !== intdiv($maxApartmentNumber - 1, 100);

            $params['mode'] = $isCrossNumbering ? 2 : 1;
        }

        $this->apiCall('/api/v1/configuration', 'PATCH', ['commutator' => $params]);
    }

    /**
     * Set the display text for service messages.
     *
     * @return void
     */
    protected function setDisplayLocalization(): void
    {
        $this->apiCall('/api/v1/configuration', 'PATCH', [
            'display' => [
                'localization' => [
                    'ENTER_APARTMENT' => 'НАБЕРИТЕ НОМЕР КВАРТИРЫ',
                    'ENTER_PREFIX' => 'НАБЕРИТЕ ПРЕФИКС',
                    'CALL' => 'ИДЁТ ВЫЗОВ',
                    'CALL_GATE' => 'ЗАНЯТО',
                    'CONNECT' => 'ГОВОРИТЕ',
                    'OPEN' => 'ОТКРЫТО',
                    'FAIL_NO_CLIENT' => 'НЕВЕРНЫЙ НОМЕР КВАРТИРЫ',
                    'FAIL_NO_APP_AND_FLAT' => 'АБОНЕНТ НЕДОСТУПЕН',
                    'FAIL_LONG_SPEAK' => 'ВРЕМЯ ВЫШЛО',
                    'FAIL_NO_ANSWER' => 'НЕ ОТВЕЧАЕТ',
                    'FAIL_UNKNOWN' => 'ОШИБКА',
                    'FAIL_BLACK_LIST' => 'АБОНЕНТ ЗАБЛОКИРОВАН',
                    'FAIL_LINE_BUSY' => 'ЛИНИЯ ЗАНЯТА',
                    'KEY_DUPLICATE_ERROR' => 'ДУБЛИКАТ КЛЮЧА ЗАБЛОКИРОВАН',
                    'KEY_READ_ERROR' => 'ОШИБКА ЧТЕНИЯ КЛЮЧА',
                    'KEY_BROKEN_ERROR' => 'КЛЮЧ ВЫШЕЛ ИЗ СТРОЯ',
                    'KEY_UNSUPPORTED_ERROR' => 'КЛЮЧ НЕ ПОДДЕРЖИВАЕТСЯ',
                    'ALWAYS_OPEN' => 'ДВЕРИ ОТКРЫТЫ',
                ],
            ],
        ]);
    }

    /**
     * Set network params.
     *
     * @return void
     */
    protected function setNetwork(): void
    {
        $this->apiCall('/cgi-bin/configManager.cgi', 'GET', [
            'action' => 'setConfig',
            'RTSP.Block' => 'false',
            'Agent.Enable' => 'false',
        ]);
    }

    /**
     * Set RFID reader mode.
     *
     * @return void
     */
    protected function setRfidMode(): void
    {
        $this->apiCall('/api/v1/configuration', 'PATCH', ['door' => ['rfid_pass_en' => false]]);
    }

    /**
     * Upload the dialplan from the cache into the intercom.
     *
     * @return void
     */
    protected function uploadDialplans(): void
    {
        if ($this->dialplans !== null) {
            $this->apiCall('/api/v1/apartments', 'PUT', $this->dialplans);
        }
    }

    /**
     * Upload RFID codes from the cache into the intercom.
     *
     * @return void
     */
    protected function uploadRfids(): void
    {
        if ($this->rfids !== null) {
            $this->apiCall('/api/v1/rfids', 'PUT', $this->rfids);
        }
    }

    protected function getMatrixEdge(): ?int
    {
        return self::CMS_PARAMS[$this->cmsModelName]['edge'] ?? null;
    }

    /** @return Generator<int, array> */
    protected function getApartmentsDialplans(bool $unmapped = false): Generator
    {
        foreach ($this->dialplans as $apartment => $dialplan) {
            if (ctype_digit((string) $apartment) && ($dialplan['map'] != 0 || $unmapped)) {
                yield (int)$apartment => $dialplan;
            }
        }
    }

    protected function remapMatrix(array $matrix, array $configApartments = []): array
    {
        $this->loadDialplans();

        $newMatrix = [];
        $edge = $this->getMatrixEdge();
        foreach ($matrix as $index => $cell) {
            $apartment = $cell['apartment'];
            if (!isset($this->dialplans[$apartment]) && !isset($configApartments[$apartment])) {
                continue;
            }

            $mapping = $cell['hundreds'] * 100 + $cell['tens'] * 10 + $cell['units'];
            if ($edge && $mapping % $edge !== 0) {
                $newMatrix[$index] = $cell;
                continue;
            }

            $newCell = self::getMatrixCell($mapping + $edge, $apartment);
            $newMatrix[$newCell['index']] = $newCell['value'];
        }

        return $newMatrix;
    }

    /** @return array{index:string,value:array} */
    protected static function getMatrixCell(int $mapping, int $apartment): array
    {
        $hundreds = floor($mapping / 100);
        $tens = floor(($mapping - $hundreds * 100) / 10);
        $units = $mapping - ($hundreds * 100 + $tens * 10);

        $index = "$hundreds$tens$units";

        return [
            'index' => $index,
            'value' => [
                'hundreds' => $hundreds,
                'tens' => $tens,
                'units' => $units,
                'apartment' => $apartment,
            ]
        ];
    }
}
