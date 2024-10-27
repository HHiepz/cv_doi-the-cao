<?php
require('../../core/database.php');
require('../../core/function.php');
require('../../core/apiSend.php');
require('../../plugins/HTMLPurifier/HTMLPurifier.auto.php');

// Config HTMLPurifier
$config = HTMLPurifier_Config::createDefault();
$purifier = new HTMLPurifier($config);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (empty($_GET['command'])) {
        jsonReturn(100, "Không được để trống thông tin");
    }

    $command = $purifier->purify(trim($_GET['command']));

    if ($command == 'charging') {
        // Kiểm tra rỗng
        if (empty($_GET['telco']) || empty($_GET['code']) || empty($_GET['serial']) || empty($_GET['amount']) || empty($_GET['request_id']) || empty($_GET['partner_id']) || empty($_GET['sign'])) {
            jsonReturn(100, "Không được để trống thông tin");
        }

        $telco                = $purifier->purify(trim(strtoupper($_GET['telco'])));       // Nhà mạng  
        $code                 = $purifier->purify(trim($_GET['code']));                    // Mã thẻ
        $seri                 = $purifier->purify(trim($_GET['serial']));                  // Seri thẻ
        $amount               = $purifier->purify(trim($_GET['amount']));                  // Mệnh giá thẻ
        $request_id_partner   = $purifier->purify(trim($_GET['request_id']));              // Mã giao dịch của đối tác
        $partner_id           = $purifier->purify(trim($_GET['partner_id']));              // Mã đối tác
        $sign                 = $purifier->purify(trim($_GET['sign']));                    // Chữ ký bảo mật của khách hàng

        $partner_key  = pdo_query_value("SELECT `partner_key` FROM `partner` WHERE `partner_id` = ? AND `partner_type` = 'Charging'", [$partner_id]);

        // Kiểm tra chữ ký
        if ($sign !== md5($partner_key . $code . $seri)) {
            jsonReturn(100, 'Sai chữ ký');
        }

        // Kiểm tra partner có được kích hoạt không
        $partner = pdo_query_one("SELECT * FROM `partner` WHERE `partner_id` = ? AND `partner_status` = 'active'", [$partner_id]);
        if (empty($partner)) {
            jsonReturn(100, 'Đối tác chưa được kích hoạt, vui lòng liên hệ ADMIN');
        }

        // Kiểm tra trạng thá có bảo trì không
        $checkStatusExchangeCard = pdo_query_value("SELECT `value` FROM `setting` WHERE `name` = 'status_exchange_card'");
        $checkStatusServer = pdo_query_value("SELECT `value` FROM `setting` WHERE `name` = 'status_server'");
        if ($checkStatusExchangeCard != 1 || $checkStatusServer != 1) {
            jsonReturn(4, 'Hệ thống đang bảo trì, vui lòng quay lại sau');
        }

        // Kiểm tra telco có trong hệ thống không
        $telco_list = list_telco();
        if (!array_key_exists($telco, $telco_list)) {
            jsonReturn(100, 'Nhà mạng không hợp lệ');
        }

        // Kiểm tra mệnh giá 
        $checkAmount = list_fee_exchange();
        if (!isset($checkAmount[$telco]['member'][$amount])) {
            jsonReturn(100, "Mệnh giá không hợp lệ");
        }

        // Kiểm tra đúng định dạng thẻ
        if (format_card($telco, $seri, $code) === false) {
            jsonReturn(100, "Định dạng thẻ không hợp lệ");
        }

        // Kiểm tra nhà mạng không hoạt động
        if (!telco_status($telco)) {
            jsonReturn(100, "Nhà mạng $telco đang bảo trì!");
        }

        // Kiểm tra thẻ đã tồn tại trong hệ thống chưa
        $check_card_sql = "SELECT * FROM `card-data` WHERE `card-data_code` = ? AND `card-data_seri` = ?";
        if (pdo_query($check_card_sql, [$code, $seri])) {
            jsonReturn(100, 'Thẻ đã tồn tại trong hệ thống');
        }

        // Request_id có tồn tại trong hệ thống chưa
        $check_request_id = "SELECT * FROM `card-data` WHERE `card-data_partner_request_id` = ?";
        if (pdo_query($check_request_id, [$request_id_partner])) {
            jsonReturn(100, 'Mã giao dịch đã tồn tại trong hệ thống, vui lòng thử lại');
        }

        $user_id        = $partner['user_id'];                              // ID người dùng
        $amount_recieve = getAmountRecieveUser($user_id, $telco, $amount);  // Thực nhận của người dùng (Mệnh giá - Phí chiết khấu theo rank của user)
        $fee            = getFeeExchange($user_id, $telco, $amount);        // Phí đổi thẻ của người dùng (Phí chiết khấu theo rank của user)
        $partner_server_name = pdo_query_value("SELECT `value` FROM `setting` WHERE `name` = 'partner_server_name'"); // Tên server web mẹ
        $partner_callback    = $partner['partner_callback'];                // Link callback của đối tác
        $partner_sign        = md5($partner_key . $code . $seri);           // Chữ ký bảo mật của đối tác
        $request_id          = rand_string(11);                             // Mã giao dịch

        // Gửi card tới web mẹ
        $sendCard = sendCard($telco, $code, $seri, $amount, $request_id);

        // Lưu thông tin thẻ vào database
        $sql = "INSERT INTO `card-data` SET
                `card-data_telco`              = ?,
                `card-data_code`               = ?,
                `card-data_seri`               = ?,
                `card-data_amount`             = ?,
                `card-data_fee`                = ?,
                `card-data_amount_recieve`     = ?,
                `user_id`                      = ?,
                `card-data_server`             = ?,
                `card-data_request_id`         = ?,
                `card-data_partner_key`        = ?,
                `card-data_callback`           = ?,
                `card-data_partner_sign`       = ?,
                `card-data_partner_request_id` = ?,
                `card-data_status`             = 'wait'
            ";
        pdo_execute($sql, [$telco, $code, $seri, $amount, $fee, $amount_recieve, $user_id, $partner_server_name, $request_id, $partner_key, $partner_callback, $partner_sign, $request_id_partner]);

        // Cập nhật thời gian lần cuối partner
        pdo_execute("UPDATE `partner` SET `updated_at` = ? WHERE `partner_id` = ?", [getDateTimeNow(), $partner_id]);

        $jsonReturn = json_encode([
            'trans_id'         => $request_id,
            'request_id'       => $request_id_partner,
            'amount'           => $amount_recieve,
            'value'            => null,
            'declared_value'   => $amount,
            'telco'            => $telco,
            'serial'           => $seri,
            'code'             => $code,
            'status'           => 99,
            'message'          => 'Thẻ đã được gửi thành công.'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        echo $jsonReturn;
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['command'])) {
        jsonReturn(100, "Không được để trống thông tin");
    }

    $command = $purifier->purify(trim($_POST['command']));

    if ($command == 'charging') {
        // Kiểm tra rỗng
        if (empty($_POST['telco']) || empty($_POST['code']) || empty($_POST['serial']) || empty($_POST['amount']) || empty($_POST['request_id']) || empty($_POST['partner_id']) || empty($_POST['sign'])) {
            jsonReturn(100, "Không được để trống thông tin");
        }

        $telco                = $purifier->purify(trim(strtoupper($_POST['telco'])));       // Nhà mạng  
        $code                 = $purifier->purify(trim($_POST['code']));                    // Mã thẻ
        $seri                 = $purifier->purify(trim($_POST['serial']));                  // Seri thẻ
        $amount               = $purifier->purify(trim($_POST['amount']));                  // Mệnh giá thẻ
        $request_id_partner   = $purifier->purify(trim($_POST['request_id']));              // Mã giao dịch của đối tác
        $partner_id           = $purifier->purify(trim($_POST['partner_id']));              // Mã đối tác
        $sign                 = $purifier->purify(trim($_POST['sign']));                    // Chữ ký bảo mật của khách hàng

        $partner_key  = pdo_query_value("SELECT `partner_key` FROM `partner` WHERE `partner_id` = ? AND `partner_type` = 'Charging'", [$partner_id]);

        // Kiểm tra chữ ký
        if ($sign !== md5($partner_key . $code . $seri)) {
            jsonReturn(100, 'Sai chữ ký');
        }

        // Kiểm tra partner có được kích hoạt không
        $partner = pdo_query_one("SELECT * FROM `partner` WHERE `partner_id` = ? AND `partner_status` = 'active'", [$partner_id]);
        if (empty($partner)) {
            jsonReturn(100, 'Đối tác chưa được kích hoạt, vui lòng liên hệ ADMIN');
        }

        // Kiểm tra trạng thá có bảo trì không
        $checkStatusExchangeCard = pdo_query_value("SELECT `value` FROM `setting` WHERE `name` = 'status_exchange_card'");
        $checkStatusServer = pdo_query_value("SELECT `value` FROM `setting` WHERE `name` = 'status_server'");
        if ($checkStatusExchangeCard != 1 || $checkStatusServer != 1) {
            jsonReturn(4, 'Hệ thống đang bảo trì, vui lòng quay lại sau');
        }

        // Kiểm tra telco có trong hệ thống không
        $telco_list = list_telco();
        if (!array_key_exists($telco, $telco_list)) {
            jsonReturn(100, 'Nhà mạng không hợp lệ');
        }

        // Kiểm tra mệnh giá
        $checkAmount = list_fee_exchange();
        if (!isset($checkAmount[$telco]['member'][$amount])) {
            jsonReturn(100, "Mệnh giá không hợp lệ");
        }

        // Kiểm tra đúng định dạng thẻ
        if (format_card($telco, $seri, $code) === false) {
            jsonReturn(100, "Định dạng thẻ không hợp lệ");
        }

        // Kiểm tra thẻ đã tồn tại trong hệ thống chưa
        $check_card_sql = "SELECT * FROM `card-data` WHERE `card-data_code` = ? AND `card-data_seri` = ?";
        if (pdo_query($check_card_sql, [$code, $seri])) {
            jsonReturn(100, 'Thẻ đã tồn tại trong hệ thống');
        }

        // Request_id có tồn tại trong hệ thống chưa
        $check_request_id = "SELECT * FROM `card-data` WHERE `card-data_partner_request_id` = ?";
        if (pdo_query($check_request_id, [$request_id_partner])) {
            jsonReturn(100, 'Mã giao dịch đã tồn tại trong hệ thống, vui lòng thử lại');
        }

        $user_id        = $partner['user_id'];                              // ID người dùng
        $amount_recieve = getAmountRecieveUser($user_id, $telco, $amount);  // Thực nhận của người dùng (Mệnh giá - Phí chiết khấu theo rank của user)
        $fee            = getFeeExchange($user_id, $telco, $amount);        // Phí đổi thẻ của người dùng (Phí chiết khấu theo rank của user)
        $partner_server_name = pdo_query_value("SELECT `value` FROM `setting` WHERE `name` = 'partner_server_name'"); // Tên server web mẹ
        $partner_callback    = $partner['partner_callback'];                // Link callback của đối tác
        $partner_sign        = md5($partner_key . $code . $seri);           // Chữ ký bảo mật của đối tác
        $request_id          = rand_string(11);                             // Mã giao dịch

        // Gửi card tới web mẹ
        $sendCard = sendCard($telco, $code, $seri, $amount, $request_id);

        // Lưu thông tin thẻ vào database
        $sql = "INSERT INTO `card-data` SET
                `card-data_telco`              = ?,
                `card-data_code`               = ?,
                `card-data_seri`               = ?,
                `card-data_amount`             = ?,
                `card-data_fee`                = ?,
                `card-data_amount_recieve`     = ?,
                `user_id`                      = ?,
                `card-data_server`             = ?,
                `card-data_request_id`         = ?,
                `card-data_partner_key`        = ?,
                `card-data_callback`           = ?,
                `card-data_partner_sign`       = ?,
                `card-data_partner_request_id` = ?,
                `card-data_status`             = 'wait'
            ";
        pdo_execute($sql, [$telco, $code, $seri, $amount, $fee, $amount_recieve, $user_id, $partner_server_name, $request_id, $partner_key, $partner_callback, $partner_sign, $request_id_partner]);

        // Cập nhật thời gian lần cuối partner
        pdo_execute("UPDATE `partner` SET `updated_at` = ? WHERE `partner_id` = ?", [getDateTimeNow(), $partner_id]);

        $jsonReturn = json_encode([
            'trans_id'         => $request_id,
            'request_id'       => $request_id_partner,
            'amount'           => $amount_recieve,
            'value'            => null,
            'declared_value'   => $amount,
            'telco'            => $telco,
            'serial'           => $seri,
            'code'             => $code,
            'status'           => 99,
            'message'          => 'Thẻ đã được gửi thành công.'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        echo $jsonReturn;
        exit();
    }
}


if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonReturn(100, 'Hiện tại không hỗ trợ phương thức này');
}
