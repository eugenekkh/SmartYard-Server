<?php

    class PDO_EXT extends PDO {

        function trimParams($map) {
            $remap = [];

            foreach ($map as $key => $value) {
                $remap[$key] = trim($value);
            }

            return $remap;
        }

        function insert($query, $params = []) {
            try {
                $sth = $this->prepare($query);
                if ($sth->execute($this->trimParams($params))) {
                    return $this->lastInsertId();
                } else {
                    return false;
                }
            } catch (\Exception $e) {
                error_log(print_r($e, true));
                return false;
            }
        }

        function modify($query, $params = []) {
            try {
                $sth = $this->prepare($query);
                if ($sth->execute($this->trimParams($params))) {
                    return $sth->rowCount();
                } else {
                    return false;
                }
            } catch (\Exception $e) {
                error_log(print_r($e, true));
                return false;
            }
        }

        function get($query, $params = [], $map = [], $options = []) {
            try {
                if ($params) {
                    $sth = $this->prepare($query);
                    if ($sth->execute($params)) {
                        $a = $sth->fetchAll(\PDO::FETCH_ASSOC);
                    } else {
                        return false;
                    }
                } else {
                    $a = $this->query($query, \PDO::FETCH_ASSOC)->fetchAll();
                }

                $r = [];

                if ($map) {
                    foreach ($a as $f) {
                        $x = [];
                        foreach ($map as $k => $l) {
                            $x[$l] = $f[$k];
                        }
                        $r[] = $x;
                    }
                } else {
                    $r = $a;
                }

                if (in_array("singlify", $options)) {
                    if (count($r) === 1) {
                        return $r[0];
                    } else {
                        return false;
                    }
                }

                if (in_array("fieldlify", $options)) {
                    if (count($r) === 1) {
                        return $r[0][array_key_first($r[0])];
                    } else {
                        return false;
                    }
                }

                return $r;

            } catch (\Exception $e) {
                error_log(print_r($e, true));
                return false;
            }
        }
    }
