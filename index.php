<?php
$client = new Redis();
$client->connect(YOUR_REDIS_SERVER, 6379, 2);

// 產品的 Redis Key
$productKey = 'product:ABC';

// 取的存取 SQL 的 Redis Key
$ticketKey = 'creator:product:ABC';

// PUB/SUB 的 channelId
$channelId = 'channel:product:ABC';

// Redis 找不到資料
$data = $client->get($productKey);
if (!$data) {
    // 成功 setnx 表示拿到 ticket，有權利存取資料庫
    $getTicket = $client->setnx($ticketKey, 1);
    if ($getTicket) {
        // 將 ticket 設定為 60 秒後過期
        $client->expireAt($ticketKey, time() + 60);

        // 連線資料庫，取得資料庫資料
        $db = new PDO('mysql:host=localhost;dbname=pdo_example;charset=utf8', 'root', '');
        $sqlData = $db->query('select * from products where id = 1');

        // 利用 MessagePack 將資料 sqlData 包起來
        $data = msgpack_pack($sqlData);

        // 存到 Redis
        $client->set($productKey, $data);

        // 將資料 publish 給正在 subscribe $channelId 的人
        $client->publish($channelId, $data);
    } else {

        // 切換 subscribe 模式要設定 timeout 時間，不然會不知道等多久
        $client->setOption(Redis::OPT_READ_TIMEOUT, 1);

        // 由於 Redis 在切換成 PUB/SUB 模式之後，沒有一個功能可以讓它再切回來
        // 所以只要收到訊息就讓它斷線
        $exptMsg = false;
        try {
            // subscribe 可以一次監聽多個 channelId
            $client->subscribe([$channelId], function ($instance, $channel_id, $message) {
                $data = $message;
                $instance->disconnect();
            });
        } catch (Exception $e) {
            $exptMsg = $e->getMessage();
        }
        
        // 收到訊息正常斷線的字串 Connection closed
        if ($exptMsg != 'Connection closed') {
            return false;
        }
    }
}

$data = msgpack_unpack($data);
return $data;