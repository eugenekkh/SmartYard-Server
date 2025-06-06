<?php

    /**
     * backends authentication namespace
     */

    namespace backends\authentication {

        use backends\backend;

        /**
         * base authentication class
         */

        abstract class authentication extends backend {

            /**
             * @param string $login login
             * @param string $password plain-text password
             * @return mixed
             */

            abstract public function check_auth($login, $password);

            /**
             * @param string $login login
             * @param string $password plain-text password
             * @param boolean $rememberMe generate persistent (10 year ttl) token
             * @param string $ua user agent
             * @param string $did device id
             * @param string $ip client ip
             * @param string $oneCode totp code
             * @return array
             */

            public function login($login, $password, $rememberMe, $ua = "", $did = "", $ip = "", $oneCode = "") {
                $uid = $this->check_auth($login, $password);


                if ($uid !== false) {
                    $users = loadBackend("users");

                    $two_fa = $users->two_fa($uid);

                    if ($two_fa) {
                        require_once "lib/GoogleAuthenticator/GoogleAuthenticator.php";

                        $ga = new \PHPGangsta_GoogleAuthenticator();

                        if (!$ga->verifyCode($two_fa, $oneCode, 2)) {
                            return [
                                "otp" => true,
                            ];
                        }
                    }

                    $keys = $this->redis->keys("auth_*_" . $uid);
                    $first_key = "";
                    $first_key_time = time();

                    if (count($keys) > $this->config["redis"]["max_allowed_tokens"]) {
                        foreach ($keys as $key) {
                            try {
                                $auth = json_decode($this->redis->get($key), true);
                                if (@(int)$auth["updated"] < $first_key_time) {
                                    $first_key = $key;
                                }
                            } catch (\Exception $e) {
                                $this->redis->delete($key);
                            }
                        }
                        $this->redis->delete($first_key);
                    }

                    if ($rememberMe) {
                        $token = md5($uid . ":" . $login . ":" . $password . ":" . $did);
                    } else {
                        if ($did === "Base64") {
                            $token = md5($uid . ":" . $login . ":" . $password);
                        } else {
                            $token = md5(GUIDv4());
                        }
                    }

                    $this->redis->setex("auth_" . $token . "_" . $uid, $rememberMe ? (7 * 24 * 60 * 60) : $this->config["redis"]["token_idle_ttl"], json_encode([
                        "uid" => (string)$uid,
                        "login" => $login,
                        "persistent" => $rememberMe,
                        "ua" => $ua,
                        "did" => $did,
                        "ip" => $ip,
                        "started" => time(),
                        "updated" => time(),
                    ]));

                    $this->redis->set("last_login_" . md5($login), time());

                    return [
                        "result" => true,
                        "token" => $token,
                        "login" => $login,
                        "ua" => $ua,
                        "uid" => $uid,
                    ];
                } else {
                    error_log("\nFAIL2BAN: $ip login fail: " . $login . "\n");

                    return [
                        "result" => false,
                        "code" => 404,
                        "error" => "userNotFound",
                    ];
                }
            }

            /**
             * @param string $authorization authorization string
             * @param string $ua user agent
             * @return false|array
             */

            public function auth($authorization, $ua = "", $ip = "") {
                $authorization = explode(" ", $authorization);

                $users = loadBackend("users");

                if ($authorization[0] === "Bearer") {
                    $token = $authorization[1];

                    $keys = $this->redis->keys("persistent_" . $token . "_*");

                    foreach ($keys as $key) {
                        $auth = json_decode($this->redis->get($key), true);

                        if ($ua) {
                            $auth["ua"] = $ua;
                        }

                        if ($ip) {
                            $auth["ip"] = $ip;
                        }

                        $auth["updated"] = time();

                        $auth["token"] = $token;

                        $this->redis->set($key, json_encode($auth));

                        if ($users->getUidByLogin($auth["login"]) == $auth["uid"]) {
                            return $auth;
                        } else {
                            $this->redis->del($key);
                        }
                    }

                    $keys = $this->redis->keys("auth_" . $token . "_*");

                    foreach ($keys as $key) {
                        $auth = json_decode($this->redis->get($key), true);

                        if ($ua) {
                            $auth["ua"] = $ua;
                        }

                        if ($ip) {
                            $auth["ip"] = $ip;
                        }

                        $auth["updated"] = time();

                        $auth["token"] = $token;

                        $this->redis->setex($key, $auth["persistent"] ? (7 * 24 * 60 * 60) : $this->config["redis"]["token_idle_ttl"], json_encode($auth));

                        if ($users->getUidByLogin($auth["login"]) == $auth["uid"]) {
                            return $auth;
                        } else {
                            $this->redis->del($key);
                        }
                    }
                }

                if ($authorization[0] === "Base64") {
                    $login = base64_decode($authorization[1]);
                    $password = base64_decode($authorization[2]);

                    if (array_key_exists('X-Otp', apache_request_headers())) {
                        $otp = apache_request_headers()["X-Otp"];
                    } else {
                        $otp = false;
                    }

                    if ($otp) {
                        $auth = $this->login($login, $password, false, $ua, "Base64", $ip, $otp);
                    } else {
                        $auth = $this->login($login, $password, false, $ua, "Base64", $ip);
                    }

                    $auth["updated"] = time();

                    if ($auth["result"]) {
                        return $auth;
                    }
                }

                error_log("\nFAIL2BAN: $ip");

                return false;
            }

            /**
             * @param string $token authentication token
             * @param boolean $all logout only this token or all tokens by uid
             * @return void
             */

            public function logout($token, $all = false) {
                $keys = $this->redis->keys("auth_" . $token . "_*");

                if ($all) {
                    foreach ($keys as $key) {
                        $uid = (string)@explode("_", $key)[2];
                        $_keys = $this->redis->keys("auth_*_" . $uid);

                        foreach ($_keys as $_key) {
                            $this->redis->del($_key);
                        }

                        break;
                    }
                } else {
                    $keys = $this->redis->keys("auth_" . $token . "_*");

                    foreach ($keys as $key) {
                        $this->redis->del($key);
                    }
                }
            }

            /**
             * @param string $token token
             * @param string $oneCode one time password
             *
             * @return boolean
             */

            public function two_fa($token, $oneCode) {
                require_once "lib/GoogleAuthenticator/GoogleAuthenticator.php";

                $ga = new \PHPGangsta_GoogleAuthenticator();

                $users = loadBackend("users");

                $keys = $this->redis->keys("auth_" . $token . "_*");

                foreach ($keys as $key) {
                    $auth = json_decode($this->redis->get($key), true);

                    if ($oneCode) {
                        $secret = $auth["secret"];

                        if ($secret && $ga->verifyCode($secret, $oneCode, 2)) {
                            unset($auth["secret"]);
                            $this->redis->setex($key, $auth["persistent"] ? (7 * 24 * 60 * 60) : $this->config["redis"]["token_idle_ttl"], json_encode($auth));
                            return $users->two_fa($auth["uid"], $secret);
                        } else {
                            setLastError("invalid2FACredentials");
                            return false;
                        }
                    } else {
                        $secret = $ga->createSecret();
                        $auth["secret"] = $secret;

                        $this->redis->setex($key, $auth["persistent"] ? (7 * 24 * 60 * 60) : $this->config["redis"]["token_idle_ttl"], json_encode($auth));

                        return $ga->getQRCodeText(@$this->config["backends"]["authentication"]["2faName"] ?: i18n("2faName"), $secret, @$this->config["backends"]["authentication"]["2faTitle"] ?: i18n("2faTitle"));
                    }
                }
            }
        }
    }
