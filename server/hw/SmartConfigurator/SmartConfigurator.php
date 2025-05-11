<?php

namespace hw\SmartConfigurator;

use hw\Interfaces\DbConfigUpdaterInterface;
use hw\SmartConfigurator\DbConfigCollector\IDbConfigCollector;

class SmartConfigurator
{
    private object $device;
    private IDbConfigCollector $dbConfigCollector;
    private array $dbConfig;
    private array $deviceConfig;

    public function __construct(object $device, IDbConfigCollector $dbConfigCollector)
    {
        $this->device = $device;
        $this->dbConfigCollector = $dbConfigCollector;

        $this->loadDbConfig();
        $this->loadDeviceConfig();
    }

    public function getDifference(): array
    {
        $transformedDbConfig = $this->device->transformDbConfig($this->dbConfig);

        $difference = array_replace_recursive(
            array_diff_assoc_recursive($transformedDbConfig, $this->deviceConfig, strict: false),
            array_diff_assoc_recursive($this->deviceConfig, $transformedDbConfig, strict: false),
        );

        $this->removeEmptySections($difference);
        return $difference;
    }

    public function makeConfiguration($retryCount = 0): void
    {
        $maxRetries = 0;
        $difference = $this->getDifference();

        if (!$difference) {
            echo 'Nothing to reconfigure' . PHP_EOL;
            return;
        }

        $sectionMethodMapping = [
            'cmsLevels' => 'setCmsLevels',
            'cmsModel' => 'setCmsModel',
            'tickerText' => 'setTickerText',
            'osdText' => 'setOsdText',
            'unlocked' => 'setUnlocked',
            'dtmf' => 'setDtmfCodes',
            'motionDetection' => 'configureMotionDetection',
            'ntp' => 'configureNtp',
            'sip' => 'configureSip',
            'eventServer' => 'configureEventServer',
        ];

        foreach ($difference as $sectionName => $items) {
            echo "Reconfiguring $sectionName... ";

            if (isset($sectionMethodMapping[$sectionName])) {
                $this->configureSection($sectionName, $sectionMethodMapping[$sectionName]);
            } elseif ($sectionName === 'apartments') {
                $this->configureApartments($items);
            } elseif ($sectionName === 'matrix') {
                $this->configureMatrix();
            } elseif ($sectionName === 'rfids') {
                $this->configureRfids($items);
            } elseif ($sectionName === 'gateLinks') {
                $this->device->configureGate($this->dbConfig['gateLinks']);
            }

            echo 'Done!' . PHP_EOL;
        }

        $this->device->syncData();

        $this->loadDeviceConfig();
        $nowDifference = $this->getDifference();

        if ($nowDifference) {
            echo 'DIFFERENCE DETECTED!' . PHP_EOL;

            if ($retryCount < $maxRetries) {
                echo '-------------------------------------------------------' . PHP_EOL;
                $this->makeConfiguration($retryCount + 1);
            }
        }
    }

    private function configureApartments($apartments): void
    {
        foreach ($apartments as $apartmentNumber => $apartmentSettings) {
            echo "$apartmentNumber... ";

            $dbApartment = $this->dbConfig['apartments'][$apartmentNumber] ?? false;

            if ($dbApartment) {
                $this->device->configureApartment(...$dbApartment);
            } else {
                $this->device->deleteApartment($apartmentNumber);
            }
        }
    }

    private function configureMatrix(): void
    {
        $this->device->configureMatrix($this->dbConfig['matrix']);
    }

    private function configureRfids($rfids): void
    {
        $rfidsToBeAdded = [];

        foreach ($rfids as $rfidCode) {
            echo "$rfidCode... ";

            if (!in_array($rfidCode, $this->dbConfig['rfids'])) {
                $this->device->deleteRfid($rfidCode);
            } else {
                $rfidsToBeAdded[] = $rfidCode;
            }
        }

        $this->device->addRfids($rfidsToBeAdded);
    }

    private function configureSection($sectionName, $method): void
    {
        if (is_callable([$this->device, $method])) {
            $args = $this->dbConfig[$sectionName];

            if (is_iterable($args) && $this->hasStringKeys($args)) {
                call_user_func_array([$this->device, $method], $args);
            } else {
                call_user_func([$this->device, $method], $args);
            }
        }
    }

    private function hasStringKeys($array): bool
    {
        return count(array_filter(array_keys($array), 'is_string')) > 0;
    }

    private function loadDbConfig(): void
    {
        $this->dbConfig = $this->dbConfigCollector->collectConfig();

        if ($this->device instanceof DbConfigUpdaterInterface) {
            $this->dbConfig = $this->device->updateDbConfig($this->dbConfig);
        }
    }

    private function loadDeviceConfig(): void
    {
        $this->deviceConfig = $this->device->getCurrentConfig();
    }

    private function removeEmptySections(&$difference): void
    {
        // Remove CMS levels from diff if empty in database
        if (empty($this->dbConfig['cmsLevels'])) {
            unset($difference['cmsLevels']); // Global CMS levels

            // And apartment CMS levels
            foreach ($difference['apartments'] ?? [] as $apartmentNumber => &$apartment) {
                unset($apartment['cmsLevels']);

                // Remove apartment if there are no more different fields for it
                if (empty($apartment)) {
                    unset($difference['apartments'][$apartmentNumber]);
                }
            }

            // Remove apartments section if empty after all
            if (empty($difference['apartments'])) {
                unset($difference['apartments']);
            }
        }

        // Remove CMS model from diff if empty in database
        if (empty($this->dbConfig['cmsModel'])) {
            unset($difference['cmsModel']);
        }
    }
}
