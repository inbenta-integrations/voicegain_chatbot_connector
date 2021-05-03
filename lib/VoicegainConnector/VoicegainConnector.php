<?php

namespace Inbenta\VoicegainConnector;

use \Exception;
use Inbenta\ChatbotConnector\ChatbotConnector;
use Inbenta\ChatbotConnector\Utils\SessionManager;
use Inbenta\ChatbotConnector\ChatbotAPI\ChatbotAPIClient;
use Inbenta\VoicegainConnector\ExternalAPI\VoicegainAPIClient;
use Inbenta\VoicegainConnector\ExternalDigester\VoicegainDigester;
use \Firebase\JWT\JWT;
use Ramsey\Uuid\Uuid;

class VoicegainConnector extends ChatbotConnector
{
    private $messages = '';
    private $response = [];
    private $csid = '';
    private $sequence = 1;

    public function __construct($appPath)
    {
        // Initialize and configure specific components for Voicegain
        try {

            parent::__construct($appPath);

            $this->securityCheck();

            // Initialize base components
            $request = file_get_contents('php://input');
            $externalId = $this->getExternalIdFromRequest();

            //
            $conversationConf = array(
                'configuration' => $this->conf->get('conversation.default'),
                'userType'      => $this->conf->get('conversation.user_type'),
                'environment'   => $this->environment,
                'source'        => $this->conf->get('conversation.source')
            );

            $this->session = new SessionManager($externalId);

            //
            $this->botClient = new ChatbotAPIClient(
                $this->conf->get('api.key'),
                $this->conf->get('api.secret'),
                $this->session,
                $conversationConf
            );

            //
            $externalClient = new VoicegainAPIClient(); // Instance Voicegain client

            // Instance Voicegain digester
            $externalDigester = new VoicegainDigester(
                $this->lang,
                $this->conf->get('conversation.digester'),
                $this->session
            );

            $this->initComponents($externalClient, null, $externalDigester);
        } catch (Exception $e) {
            echo json_encode(["error" => $e->getMessage()]);
            die();
        }
    }

    /**
     * Check if the request matches the security needs
     *
     * @return boolean
     * @throws Exception
     */
    protected function securityCheck()
    {
        $headers = getallheaders();

        if (!isset($headers['Authorization'])) {
            throw new Exception('Invalid request, no auth');
        }

        $auth = explode(' ', $headers['Authorization']);
        if (isset($auth[0]) && isset($auth[1]) && $auth[0] === 'Bearer') {
            $jwt = $auth[1];
            $elements = explode('.', $jwt);
            $bodyb64 = isset($elements[1]) ? $elements[1] : '';
            if ($bodyb64 !== '') {
                $payload = JWT::jsonDecode(JWT::urlsafeB64Decode($bodyb64));
                $token = $this->conf->get('voicegain.token');

                if (isset($payload->token) && $payload->token === $token) {
                    return true;
                }
            }
        }
        throw new Exception('Invalid request, wrong JWT');
    }

    /**
     * Get the external id from request
     *
     * @return String 
     */
    protected function getExternalIdFromRequest()
    {
        // Try to get user_id from a Voicegain message request
        $externalId = VoicegainAPIClient::buildExternalIdFromRequest();
        if (is_null($externalId)) {
            session_write_close();
            throw new Exception('Invalid request!');
        }
        return $externalId;
    }

    /**
     * Set the valud of the CSID
     */
    public function setCsid(string $csid)
    {
        $this->csid = $csid;
    }

    /**
     * Set the current sequence
     */
    public function setSequence($sequence = 1)
    {
        $this->sequence = $sequence;
    }

    /**
     * Set the response structure
     */
    public function setResponseStructure()
    {
        $this->response = [
            "csid" => $this->csid,
            "sid" => '',
            "sequence" => $this->sequence,
            "question" => [
                "name" => 'answer' . $this->sequence,
                "text" => "What can I do for you?",
                "audioProperties" => [
                    "voice" => $this->session->get('defaultVoice'),
                ],
                "audioResponse" => [
                    'noInputTimeout' => 8000,
                    'completeTimeout' => 4000
                ]
            ]
        ];
    }

    /**
     * Creates the session
     */
    public function startConversation()
    {
        $request = json_decode(file_get_contents('php://input'));

        $this->session->set('defaultVoice', $request->defaultVoice);

        $response = [
            "csid" => Uuid::uuid4()->toString(),
            "sid" => $request->sid,
            "sequence" => $request->sequence,
            "prompt" => [
                "text" => 'Connecting to the Bot',
                "audioProperties" => [
                    "voice" => $request->defaultVoice
                ]
            ]
        ];
        return $response;
    }

    /**
     * Refresh message - to keep the conversation alive
     *
     * @return void
     */
    public function handleRequest()
    {
        $request = json_decode(file_get_contents('php://input'));
        $this->response['sid'] = $request->sid;

        // Translate the request into a ChatbotAPI request
        $externalRequest = $this->digester->digestToApi($request);

        if ($externalRequest['isUserMessage']) {
            // Check if it's needed to perform any action other than a standard user-bot interaction
            $nonBotResponse = $this->handleNonBotActions($externalRequest['message']);
            if (!is_null($nonBotResponse)) {
                return $nonBotResponse;
            }

            // Handle standard bot actions
            $this->handleBotActions([$externalRequest]);
        } else {
            if ($externalRequest['message'] === 'TRANSFER') {
                unset($this->response['question']);
                $this->messages = '';
            } else {
                if ($externalRequest['message'] === 'FIRSTMESSAGE') {
                    $botResponse = $this->sendMessageToBot(['directCall' => 'sys-welcome']);
                    $welcomeMessage = 'Welcome, what can I do for you?';
                    if (isset($botResponse->answers[0]->message)) {
                        $welcomeMessage = $botResponse->answers[0]->message;
                        $welcomeMessage = str_replace(' !', '', $welcomeMessage);
                    }
                    $this->messages = $welcomeMessage;
                } else {
                    $this->response['question']['audioResponse']['questionPrompt'] = $externalRequest['message'] === 'NOINPUT' ? 'I cant hear you' : 'Can you say it again?';
                }
            }
        }
        // Send all messages
        return $this->sendMessages();
    }

    /**
     * Disconnect
     *
     * @return void
     * @throws Exception
     */
    public function disconnect()
    {
        $request = json_decode(file_get_contents('php://input'));

        $response = [
            "csid" => $this->csid,
            "sid" => $request->sid,
            "sequence" => $this->sequence,
            "termination" => "normal"
        ];
        return $response;
    }

    /**
     * Overwritten
     * Handle the non bot action
     */
    protected function handleNonBotActions($digestedRequest)
    {
        // If user answered to an ask-to-escalate question, handle it
        if ($this->session->get('askingForEscalation', false)) {
            return $this->handleEscalation($digestedRequest);
        }
        return null;
    }

    /**
     * Print the message that Voicegain can process
     */
    public function sendMessages()
    {
        if (trim($this->messages) !== '') {
            $this->response['question']['text'] = $this->messages;
        }
        return $this->response;
    }

    /**
     * Overwritten
     * Store the response into the global variable $this->messages
     */
    protected function sendMessagesToExternal($messages)
    {
        // Digest the bot response into the external service format
        $response = $this->digester->digestFromApi($messages, $this->session->get('lastUserQuestion'));
        $this->messages .= $response;
    }

    /**
     * Overwritten
     * Handle the escalation if exists
     */
    protected function handleEscalation($userAnswer = null)
    {
        $transferPhoneExists = true;
        if ($this->conf->get('chat.chat.address') === '') {
            $transferPhoneExists = false;
        }

        if (!$this->session->get('askingForEscalation', false)) {
            if ($this->session->get('escalationType') == static::ESCALATION_DIRECT) {
                if ($transferPhoneExists) {
                    return $this->escalateToAgent();
                } else {
                    $this->messages .= ' ' . $this->digester->buildNoEscalationConfigMessage();
                    return $this->sendMessages();
                }
            } else {
                if ($transferPhoneExists) {
                    // Ask the user if wants to escalate
                    $this->session->set('askingForEscalation', true);
                    $this->messages .= ' ' . $this->digester->buildEscalationMessage();
                    return $this->sendMessages();
                } else {
                    $this->messages = '';
                    $this->messages = $this->digester->buildNoEscalationConfigMessage(true);
                    return $this->sendMessages();
                }
            }
        } else {
            // Handle user response to an escalation question
            $this->session->set('askingForEscalation', false);
            // Reset escalation counters
            $this->session->set('noResultsCount', 0);
            $this->session->set('negativeRatingCount', 0);

            $yesResponses = [
                strtolower($this->lang->translate('yes')),
                strtolower($this->lang->translate('yes1')),
                strtolower($this->lang->translate('yes2')),
                strtolower($this->lang->translate('yes3')),
                strtolower($this->lang->translate('yes4')),
                strtolower($this->lang->translate('yes5'))
            ];
            $match = implode("|", $yesResponses);

            //Confirm the escalation
            if (preg_match('/' . $match . '/', strtolower($userAnswer))) {
                return $this->escalateToAgent();
            } else {
                //Any other response that is no "yes" (or similar) it's going to be considered as "no"
                $message = ["option" => strtolower($this->lang->translate('no'))];
                $botResponse = $this->sendMessageToBot($message);
                $this->sendMessagesToExternal($botResponse);
                return $this->sendMessages();
            }
        }
    }

    /**
     * Overwritten
     * Make the structure for Voicegain transfer
     */
    protected function escalateToAgent()
    {
        $this->trackContactEvent("CHAT_ATTENDED");
        $this->session->delete('escalationType');
        $this->session->delete('escalationV2');

        $this->messages .= $this->digester->buildEscalatedMessage();
        $transfer = $this->externalClient->escalate($this->messages, $this->conf->get('chat.chat.address'));

        $transfer['prompt']['audioProperties']['voice'] = $this->response['question']['audioProperties']['voice'];

        $this->response['transfer'] = $transfer;
        unset($this->response['question']);
        $this->messages = '';

        return $this->sendMessages();
    }
}
