<?php

namespace Inbenta\VoicegainConnector\ExternalAPI;

use Ramsey\Uuid\Uuid;

class VoicegainAPIClient
{
    protected $apiUrl = 'https://api.voicegain.ai/v1/asr/transcribe/async';

    /**
     * Create the external id
     */
    public static function buildExternalIdFromRequest()
    {
        $request = json_decode(file_get_contents('php://input'));

        if (!$request) {
            $request = (object) $_GET;
        }
        return isset($request->sid) ? $request->sid : null;
    }

    /**
     * Overwritten, not necessary with Voicegain
     */
    public function showBotTyping($show = true)
    {
        return true;
    }

    /**
     * Makes a message formatted with the Voicegain notation
     */
    /*public function sendMessage($messages)
    {
        return $messages;
    }*/

    /**
     * Create the array needed for escalation
     * @param string $message
     * @param string $address
     * @return array
     */
    public function escalate(string $message, string $address)
    {
        return [
            "prompt" => [
                "text" => $message, // . " Dialing: <say-as interpret-as=\"telephone\" format=\"1\">".$address."</say-as>",
                "audioProperties" => [
                    "voice" => "catherine"
                ]
            ],
            "phone" => [
                "phoneNumber" => $address
            ]
        ];
    }
}
