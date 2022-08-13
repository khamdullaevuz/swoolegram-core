#!/usr/bin/env php
<?php

declare(strict_types=1);

use Swoole\Http\Server;
use Swoole\Http\Response;
use Swoole\Http\Request;
use Swoole\Table;
use Swoole\Timer;
use Swoole\Event;

use function Swoole\Coroutine\go;
use function Swoole\Coroutine\Http\post;

$path = "http://telegram-bot-api:8081/bot5423596018:AAEtnFvuFMLj9tef5s01yQpjbM6ViPGU7Gg/";



$table = new Table(1024);
$table->column('requests', Table::TYPE_INT, 4);
$table->column('last_request', Table::TYPE_INT, 4);
$table->create();

// create function of delete key with filter last_request > 3 seconds
function deleteKey()
{
    global $table;
    foreach ($table as $key => $value) {
        if (time() - $value['last_request'] >= 2) {
            $table->del($key);
        }
    }
}


$http = new Server("app", 9501);

$http->on("WorkerStart", function ($server, $workerId) {
    Timer::tick(1, "deleteKey");
});


$http->on("Shutdown", function ($server) {
    global $table;
    $table->destroy();
});

$http->on("WorkerExit", function ($server, $workerId) {
    Timer::clearAll();
    Event::Exit();
});

$http->on(
    "request",
    function (Request $request, Response $response) use ($path, $table) {
        $response->end("<h1>Hello word</h1>");

        $update = json_decode($request->rawContent());
        if(!isset($update->message)){
            return;
        }

        $message = $update->message;
        $chat_id = $message->chat->id;
        $message_date = $message->date;
        if ($table->exists(strval($chat_id))) {
            // get requests count
            $requests = $table->get(strval($chat_id), 'requests');
            $table->set(strval($chat_id), array('requests' => $requests + 1, 'last_request' => $message_date));
            if ($requests + 1 > 1) {
                if ($requests == 2) {
                    $method = $path . "sendmessage";
                    $data = [
                        'chat_id' => $chat_id,
                        'text' => "Вы не можете отправлять больше одного сообщения в секунду",
                    ];
                    post($method, $data);

                }
                return;
            }
        } else {
            $table->set(strval($chat_id), ['requests' => 1, 'last_request' => $message_date]);
        }

        go(function () use ($chat_id, $path) {
            $method = $path . "sendmessage";
            $start_time = round(microtime(true) * 1000);
            $data = post($method, ['chat_id' => $chat_id, 'text' => "Tezlik: "]);
            $body = json_decode($data->getBody());
            $message_id = $body->result->message_id;
            $end_time = round(microtime(true) * 1000);
            $time_taken = $end_time - $start_time;
            $method = $path . "editMessagetext";
            post($method, [
                "chat_id" => $chat_id,
                "message_id" => $message_id,
                "text" => "Tezlik: " . $time_taken . "ms",
            ]);
        });
    }
);

$http->start();
