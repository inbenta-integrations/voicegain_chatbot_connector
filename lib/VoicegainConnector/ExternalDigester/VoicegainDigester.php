<?php

namespace Inbenta\VoicegainConnector\ExternalDigester;

use \Exception;
use Inbenta\ChatbotConnector\ExternalDigester\Channels\DigesterInterface;
use Inbenta\VoicegainConnector\Helpers\Helper;

class VoicegainDigester extends DigesterInterface
{
    protected $conf;
    protected $channel;
    protected $langManager;
    protected $session;

    public function __construct($langManager, $conf, $session)
    {
        $this->langManager = $langManager;
        $this->channel = 'Voicegain';
        $this->conf = $conf;
        $this->session = $session;
    }

    /**
     *	Returns the name of the channel
     */
    public function getChannel()
    {
        return $this->channel;
    }

    /**
     *	Checks if a request belongs to the digester channel
     */
    public static function checkRequest($request)
    {
        $request = json_decode($request);
        if (isset($request->sid)) {
            return true;
        }
        return true;
    }

    /**
     *	Formats a channel request into an Inbenta Chatbot API request
     */
    public function digestToApi($request)
    {
        $output = [
            'message' => 'FIRSTMESSAGE',
            'isUserMessage' => false
        ];
        if (isset($request->events)) {
            foreach ($request->events as $event) {
                if ($event->type === 'input') {
                    $output['message'] = $event->vuiResult;
                    if ($event->vuiResult === 'MATCH') {
                        if (isset($event->vuiAlternatives[0]->utterance)) {
                            $output = $this->checkOptions($event->vuiAlternatives[0]->utterance);
                            $output['isUserMessage'] = true;
                        } else {
                            $output['message'] = 'NOMATCH';
                        }
                        break;
                    }
                } else if ($event->type === 'transfer') {
                    $output['message'] = 'TRANSFER';
                }
            }
        }
        return $output;
    }

    /**
     * Check if the response has options
     * @param string $userMessage
     * @return array $output
     */
    protected function checkOptions(string $userMessage)
    {
        $output['message'] = $userMessage;
        if ($this->session->has('options')) {

            $lastUserQuestion = $this->session->get('lastUserQuestion');
            $options = $this->session->get('options');
            $this->session->delete('options');
            $this->session->delete('lastUserQuestion');

            $selectedOption = false;
            $selectedOptionText = "";
            $selectedEscalation = "";
            $isListValues = false;
            $isPolar = false;
            $isEscalation = false;
            $optionSelected = false;
            foreach ($options as $option) {
                if (isset($option->list_values)) {
                    $isListValues = true;
                } else if (isset($option->is_polar)) {
                    $isPolar = true;
                } else if (isset($option->escalate)) {
                    $isEscalation = true;
                }
                if (Helper::removeAccentsToLower($userMessage) === Helper::removeAccentsToLower($this->langManager->translate($option->label))) {
                    if ($isListValues || (isset($option->attributes) && isset($option->attributes->DYNAMIC_REDIRECT) && $option->attributes->DYNAMIC_REDIRECT == 'escalationStart')) {
                        $selectedOptionText = $option->label;
                    } else if ($isEscalation) {
                        $selectedEscalation = $option->escalate;
                    } else {
                        $selectedOption = $option;
                        $lastUserQuestion = isset($option->title) && !$isPolar ? $option->title : $lastUserQuestion;
                    }
                    $optionSelected = true;
                    break;
                }
            }

            if (!$optionSelected) {
                if ($isListValues) { //Set again options for variable
                    if ($this->session->get('optionListValues', 0) < 1) { //Make sure only enters here just once
                        $this->session->set('options', $options);
                        $this->session->set('lastUserQuestion', $lastUserQuestion);
                        $this->session->set('optionListValues', 1);
                    } else {
                        $this->session->delete('options');
                        $this->session->delete('lastUserQuestion');
                        $this->session->delete('optionListValues');
                    }
                } else if ($isPolar) { //For polar, on wrong answer, goes for NO
                    $output['message'] = $this->langManager->translate('no');
                }
            }

            if ($selectedOption) {
                $output['option'] = $selectedOption->value;
            } else if ($selectedOptionText !== "") {
                $output['message'] = $selectedOptionText;
            } else if ($isEscalation && $selectedEscalation !== "") {
                if ($selectedEscalation === false) {
                    $output['message'] = $this->langManager->translate('no');
                } else {
                    $output['escalateOption'] = $selectedEscalation;
                }
            }
        }
        return $output;
    }

    /**
     *	Formats an Inbenta Chatbot API response into a channel request
     */
    public function digestFromApi($request, $lastUserQuestion = '')
    {
        $messages = [];
        //Parse request messages
        if (isset($request->answers) && is_array($request->answers)) {
            $messages = $request->answers;
        } elseif ($this->checkApiMessageType($request) !== null) {
            $messages = array('answers' => $request);
        } elseif (isset($request->messages) && count($request->messages) > 0 && $this->hasTextMessage($messages[0])) {
            // If the first message contains text although it's an unknown message type, send the text to the user
            $output = $this->digestFromApiAnswer($messages[0], $lastUserQuestion);
            return $output;
        } else {
            throw new Exception("Unknown ChatbotAPI response: " . json_encode($request, true));
        }

        $output = '';
        foreach ($messages as $msg) {
            $msgType = $this->checkApiMessageType($msg);
            $digester = 'digestFromApi' . ucfirst($msgType);
            $digestedMessage = $this->$digester($msg, $lastUserQuestion);

            if ($digestedMessage === "__empty__") {
                continue;
            }

            $output .= '. ' . $digestedMessage;
        }
        if (strpos($output, '.') === 0) {
            $output = substr($output, 1, strlen($output));
        }

        return trim($output);
    }

    /**
     *	Classifies the API message into one of the defined $apiMessageTypes
     */
    protected function checkApiMessageType($message)
    {
        foreach ($this->apiMessageTypes as $type) {
            $checker = 'isApi' . ucfirst($type);

            if ($this->$checker($message)) {
                return $type;
            }
        }
        return null;
    }

    /********************** API MESSAGE TYPE CHECKERS **********************/

    protected function isApiAnswer($message)
    {
        return $message->type == 'answer';
    }

    protected function isApiPolarQuestion($message)
    {
        return $message->type == "polarQuestion";
    }

    protected function isApiMultipleChoiceQuestion($message)
    {
        return $message->type == "multipleChoiceQuestion";
    }

    protected function isApiExtendedContentsAnswer($message)
    {
        return $message->type == "extendedContentsAnswer";
    }

    protected function hasTextMessage($message)
    {
        return isset($message->text->body) && is_string($message->text->body);
    }


    /********************** CHATBOT API MESSAGE DIGESTERS **********************/

    protected function digestFromApiAnswer($message, $lastUserQuestion)
    {
        $messageResponse = $this->cleanMessage($message->message);

        $exit = false;
        if (isset($message->attributes->DIRECT_CALL) && $message->attributes->DIRECT_CALL == "sys-goodbye") {
            $messageResponse .= '. ' . $this->buildHangoutMessage();
            $exit = true;
        } else if (trim($messageResponse) === "") {
            //Prevent emtpy messages
            $messageResponse = '__empty__';
        }

        if (isset($message->attributes->SIDEBUBBLE_TEXT) && trim($message->attributes->SIDEBUBBLE_TEXT) !== "" && !$exit) {
            $sidebubble = trim($this->cleanMessage($message->attributes->SIDEBUBBLE_TEXT));
            if ($sidebubble !== '') {
                $messageResponse .= '. ' . $sidebubble;
            }
        }

        if (isset($message->actionField) && !empty($message->actionField) && $message->actionField->fieldType !== 'default' && !$exit) {
            $actionField = $this->handleMessageWithActionField($message, $lastUserQuestion);
            if ($actionField !== '') {
                $messageResponse .= '. ' . $actionField;
            }
        }

        return $messageResponse;
    }

    protected function digestFromApiMultipleChoiceQuestion($message, $lastUserQuestion, $isPolar = false)
    {
        $output = $this->cleanMessage($message->message);

        $options = $message->options;
        foreach ($options as &$option) {
            if (isset($option->attributes->title) && !$isPolar) {
                $option->title = $option->attributes->title;
            } elseif ($isPolar) {
                $option->is_polar = true;
            }
            $output .= '. ' . $this->cleanMessage($option->label);
        }
        $this->session->set('options', $options);
        $this->session->set('lastUserQuestion', $lastUserQuestion);
        return $output;
    }

    protected function digestFromApiPolarQuestion($message, $lastUserQuestion)
    {
        return $this->digestFromApiMultipleChoiceQuestion($message, $lastUserQuestion, true);
    }

    protected function digestFromApiExtendedContentsAnswer($message, $lastUserQuestion)
    {
        return $this->cleanMessage($message->message);
    }

    /********************** MISC **********************/
    public function buildEscalationMessage()
    {
        $escalateOptions = [
            (object) [
                "label" => 'yes',
                "escalate" => true,
            ],
            (object) [
                "label" => 'no',
                "escalate" => false,
            ]
        ];

        $this->session->set('options', (object) $escalateOptions);
        $output = '. ' . $this->langManager->translate('ask-to-escalate');
        $output .= '. ' . $this->langManager->translate('yes');
        $output .= '. ' . $this->langManager->translate('no');
        return $output;
    }

    public function buildEscalatedMessage()
    {
        return $this->langManager->translate('creating_chat');
    }

    /**
     * Create the message to show when there is any escalation phone
     * @param bool $fromNoResults = false
     */
    public function buildNoEscalationConfigMessage(bool $fromNoResults = false)
    {
        $message = $this->langManager->translate('no_escalation_supported');
        if ($fromNoResults) {
            $message = $this->langManager->translate('no_escalation_no_result_message') . ' ' . $message;
        }
        return $message;
    }

    public function buildInformationMessage()
    {
        return $this->langManager->translate('ask-information');
    }

    public function buildHangoutMessage()
    {
        return $this->langManager->translate('good_bye');
    }

    public function buildContentRatingsMessage($ratingOptions, $rateCode)
    {
        return '';
    }
    public function buildUrlButtonMessage($message, $urlButton)
    {
        return '';
    }
    public function handleMessageWithImages($messages)
    {
        return '';
    }

    /**
     * Validate if the message has action fields
     * @param object $message
     * @param array $output
     * @return array $output
     */
    protected function handleMessageWithActionField(object $message, $lastUserQuestion)
    {
        $output = '';
        if (isset($message->actionField) && !empty($message->actionField)) {
            if ($message->actionField->fieldType === 'list') {
                $options = $this->handleMessageWithListValues($message->actionField->listValues, $lastUserQuestion);
                if ($options !== "") {
                    $output .= '. ' . $options;
                }
            }
        }
        return $output;
    }

    /**
     * Set the options for message with list values
     * @param object $listValues
     * @return array $output
     */
    protected function handleMessageWithListValues(object $listValues, $lastUserQuestion)
    {
        $optionList = "";
        $options = $listValues->values;
        foreach ($options as $index => &$option) {
            $option->list_values = true;
            $option->label = $option->option;
            $optionList .= ". " . $option->label;
            if ($index == 5) break;
        }
        if ($optionList !== "") {
            $this->session->set('options', $options);
            $this->session->set('lastUserQuestion', $lastUserQuestion);
        }
        return $optionList;
    }

    /**
     * Clean the message from html and other characters
     * @param string $message
     */
    public function cleanMessage(string $message)
    {
        $message = strip_tags($message);
        $message = str_replace("&nbsp;", " ", $message);
        $message = str_replace("\t", " ", $message);
        $message = str_replace("\n", " ", $message);
        return trim($message);
    }
}
