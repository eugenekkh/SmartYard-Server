<?php

    /**
     * @api {post} /mobile/user/requestCode запросить код подтверждения
     * @apiVersion 1.0.0
     * @apiDescription **метод готов**
     *
     * @apiGroup User
     *
     * @apiBody {String{11}} userPhone номер телефона
     * @apiBody {String="sms","outgoingCall"} method номер телефона способ авторизации
     *
     * @apiSuccess {string="sms","outgoingCall"} [method="sms"] способ авторизации
     * @apiSuccess {string[]} [confirmationNumbers] список номеров для авторизации исходящим звонком (outgoingCall)
     *
     * @apiErrorExample Ошибки
     * 422 неверный формат данных
     * 429 код уже запрошен
     */

    $user_phone = @$postdata['userPhone'];

    if ($user_phone[0] == '8') {
        $user_phone[0] = '7';
    }

    $isdn = loadBackend("isdn");

    if (ctype_digit($user_phone)) {

        $confirmMethod = @$postdata['method'] ?: @$config["backends"]["isdn"]["confirm_method"] ?: "outgoingCall";

        // fake accounts - always confirmation by pin
        if ($user_phone == '79123456781') { // fake account №1
            $pin = '1001';
            $redis->setex("userpin_".$user_phone, 60, $pin);
            response(200, [ "method" => "sms"]);
        } else
        if ($user_phone == '79123456782') { // fake account №2
            $pin = '1002';
            $redis->setex("userpin_".$user_phone, 60, $pin);
            response(200, [ "method" => "sms"]);
        } else
        if ($user_phone == '79123456783') { // fake account №3
            $pin = '1003';
            $redis->setex("userpin_".$user_phone, 60, $pin);
            response(200, [ "method" => "sms"]);
        } else
        if ($user_phone == '79123456784') { // fake account №4
            $pin = '1004';
            $redis->setex("userpin_".$user_phone, 60, $pin);
            response(200, [ "method" => "sms"]);
        } else
        if ($user_phone == '79123456785') { // fake account №5
            $pin = '1005';
            $redis->setex("userpin_".$user_phone, 60, $pin);
            response(200, [ "method" => "sms"]);
        } 

        // real accounts
        switch ($confirmMethod) {
            case 'outgoingCall':
                response(200, [ "method" => "outgoingCall", "confirmationNumbers" => $isdn->confirmNumbers()]);
                break;

            default:
                // smsCode - default
                $already = $redis->get("userpin_" . $user_phone);
                if ($already){
                    response(429);
                } else {
                    $pin = explode(":", $isdn->sendCode($user_phone))[0];
                    $redis->setex("userpin_".$user_phone, 60, $pin);
                    response(200, [ "method" => $confirmMethod]);
                }
                break;
        }
    } else {
        response(422);
    }
