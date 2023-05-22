<?php

namespace Opencontent\Installer;

class SlackNotify
{
    public static function notify($token, $channel, $message)
    {
        if (!empty($token) && !empty($channel)) {
            $ch = curl_init("https://slack.com/api/chat.postMessage");
            $data = http_build_query([
                "token" => $token,
                "channel" => $channel,
                "text" => $message,
                "username" => "PusherBot",
            ]);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_exec($ch);
            curl_close($ch);
        }
    }
}