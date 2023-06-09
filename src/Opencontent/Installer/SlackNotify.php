<?php

namespace Opencontent\Installer;

class SlackNotify
{
    public static function notify($endpoint, $message)
    {
        $ch = curl_init($endpoint);
        $data = json_encode([
            "text" => $message,
        ]);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_exec($ch);
        curl_close($ch);
    }
}