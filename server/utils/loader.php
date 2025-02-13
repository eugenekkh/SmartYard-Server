<?php

    /**
     * loads backend module by config, returns false if backend not found or can't be loaded
     *
     * @param string $backend module name
     * @return false|object
     */

    function loadBackend($backend, $login = false) {
        global $config, $db, $redis, $backends, $cli, $cli_errors;

        if (@$backends[$backend]) {
            if ($login) {
                $backends[$backend]->setLogin($login);
            }
            return $backends[$backend];
        } else {
            if (@$config["backends"][$backend]) {
                try {
                    if (file_exists(__DIR__ . "/../backends/$backend/$backend.php") && !class_exists("backends\\$backend\\$backend")) {
                        require_once __DIR__ . "/../backends/$backend/$backend.php";
                    }
                    if (file_exists(__DIR__ . "/../backends/$backend/" . $config["backends"][$backend]["backend"] . "/" . $config["backends"][$backend]["backend"] . ".php")) {
                        require_once __DIR__ . "/../backends/$backend/" . $config["backends"][$backend]["backend"] . "/" . $config["backends"][$backend]["backend"] . ".php";
                        $className = "backends\\$backend\\" . $config["backends"][$backend]["backend"];
                        $backends[$backend] = new $className($config, $db, $redis, $login);
                        $backends[$backend]->backend = $backend;
                        return $backends[$backend];
                    } else {
                        if (@$cli) {
                            $error = "file doesn't exists: " . __DIR__ . "/../backends/$backend/" . $config["backends"][$backend]["backend"] . "/" . $config["backends"][$backend]["backend"] . ".php" . "\n";
                            if (!@$cli_errors[$error]) {
                                echo $error;
                                $cli_errors[$error] = true;
                            }
                        }
                        return false;
                    }
                } catch (Exception $e) {
                    if (@$cli) {
                        print_r($e);
                    }
                    setLastError(i18n("cantLoadBackend", $backend));
                    return false;
                }
            } else {
                return false;
            }
        }
    }

    /**
    * Loads a device class and returns an instance of the class, or false if not found.
    *
    * This function is used to load and initialize hardware device classes dynamically
    * based on the device type and model specified in a JSON file.
    *
    * @param string $type Device type (e.g., "domophone" or "camera").
    * @param string $model The filename of the JSON model configuration.
    * @param string $url The URL for the device.
    * @param string $password The password for the device.
    * @param bool $firstTime Indicates if it's the first time using the device. Default is false.
    *
    * @return false|object Returns an object instance of the device class if found and loaded successfully,
    * or false if there was an error loading the class.
    * @throws Exception
    */
    function loadDevice(string $type, string $model, string $url, string $password, bool $firstTime = false) {
        require_once __DIR__ . '/parse_url_ext.php';
        require_once __DIR__ . '/../hw/autoload.php';

        $availableTypes = ['camera', 'domophone'];

        if (!in_array($type, $availableTypes)) {
            $availableTypesString = implode(', ', array_map(fn($type) => "'$type'", $availableTypes));
            throw new Exception("Invalid device type: '$type'. Available types: $availableTypesString");
        }

        $pathToModel = __DIR__ . "/../hw/ip/$type/models/$model";

        if (!file_exists($pathToModel)) {
            throw new Exception("Model '$model' not found for type '$type'");
        }

        $data = json_decode(file_get_contents($pathToModel), true);
        $class = $data['class'];
        $vendor = strtolower($data['vendor']);

        $className = "hw\\ip\\$type\\$vendor\\custom\\$class";
        if (!class_exists($className)) {
            // If custom class is not found, use standard class
            $className = "hw\\ip\\$type\\$vendor\\$class";
        }

        return new $className($url, $password, $firstTime);
    }

    /**
     * Loads configuration from JSON or YAML files.
     *
     * @return array The configuration array, or false if the configuration files are not found or invalid.
     * @throws Exception
     */
    function loadConfiguration(): array
    {
        try {
            $jsonConfigPath = __DIR__ . "/../config/config.json";

            if (!file_exists($jsonConfigPath)) {
                throw new Exception("Config file '$jsonConfigPath' not found");
            }

            if (function_exists("json5_decode")) {
                $config = @json5_decode(file_get_contents("config/config.json"), true);
            } else {
                $config = @json_decode(file_get_contents("config/config.json"), true, 512, JSON_THROW_ON_ERROR);
            }

            if (!is_array($config)) {
                throw new Exception('Configuration file invalid');
            }

            if (!isset($config['backends'])) {
                throw new Exception('Configuration file invalid (backends not found)');
            }

            return $config;
        } catch (Exception $e) {
            error_log($e->getMessage(), 0);
            throw $e;
        }
    }
