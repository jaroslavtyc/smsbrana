<?php
declare(strict_types=1); // on PHP 7+ are standard PHP methods strict to types of given parameters

namespace Granam\SmsBranaCz;

use Granam\Strict\Object\StrictObject;
use GuzzleHttp\Client as GuzzleHttpClient;

class SmsSender extends StrictObject
{
    /** @var \GuzzleHttp\Client */
    private $httpClient;
    private $login;
    private $password;
    /** @var null|\SimpleXMLElement */
    private $smsQueue;
    private const API_URL = 'https://api.smsbrana.cz/smsconnect/http.php';

    /**
     * @param string $login
     * @param string $password
     * @param \GuzzleHttp\Client $client
     * @throws \Granam\SmsBranaCz\Exceptions\MissingCredentials
     */
    public function __construct(string $login, string $password, GuzzleHttpClient $client)
    {
        $login = \trim($login);
        if ($login === '') {
            throw new Exceptions\MissingCredentials('Given login is empty');
        }
        $password = \trim($password);
        if ($password === '') {
            throw new Exceptions\MissingCredentials('Given password is empty');
        }
        $this->login = $login;
        $this->password = $password;
        $this->httpClient = $client;
        $this->smsQueue = new \SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><queue></queue>');
    }

    /**
     * Get inbox SMSes
     *
     * @param bool $delete = true
     * @return ReceivedSmsMessage[]
     * @throws \Granam\SmsBranaCz\Exceptions\RequestToSmsBranaCzFailed
     */
    public function inbox(bool $delete = true): array
    {
        $response = $this->makeRequest('inbox', ['delete' => (int)$delete]);
        if ($response->err > 0) {
            throw new Exceptions\RequestToSmsBranaCzFailed(
                'Requesting \'inbox\' failed with error '
                . $response->err . ': ' . $this->getErrorMessage((int)$response->err),
                (int)$response->err
            );
        }
        $receivedMessages = [];
        foreach ($response->inbox->delivery_sms->item as $item) {
            if (!$item || \trim($item->number) === '') {
                continue;
            }
            $receivedMessage = new ReceivedSmsMessage(
                (string)$item->message,
                (string)$item->number,
                \DateTime::createFromFormat('Ymd\THis', $item->time)
            );
            $receivedMessages[] = $receivedMessage;
        }

        return $receivedMessages;
    }

    /**
     * @param string $action
     * @param array $parameters
     * @param string $xml = null
     * @return \SimpleXMLElement
     * @throws \Granam\SmsBranaCz\Exceptions\RequestToSmsBranaCzFailed
     */
    private function makeRequest(string $action, array $parameters, string $xml = null): \SimpleXMLElement
    {
        $query = \array_merge($parameters, $this->getAuthData());
        $query['action'] = $action;
        $payload = ['query' => $query];
        if ($xml !== null) {
            $payload['xml'] = $xml;
        }
        $response = new \SimpleXMLElement(
            $this->getAnswer((string)$this->httpClient->get(self::API_URL, $payload)->getBody())
        );
        if ((int)$response->err > 0) {
            throw new Exceptions\RequestToSmsBranaCzFailed(
                "Failed request '$action' with parameters " . \var_export($parameters, true) . ': '
                . $this->getErrorMessage((int)$response->err) . " ({$response->err})",
                (int)$response->err
            );
        }

        return $response;
    }

    /**
     * Generate salt for access
     *
     * @param int $length the length of salt to be returned
     * @return String
     */
    private function salt(int $length): string
    {
        $salt = '';
        $source = \array_merge(\range('a', 'z'), \range('A', 'Z'), \range(0, 9), [':']);

        for ($counter = 0; $counter < $length; $counter++) {
            try {
                $salt .= $source[\random_int(0, \count($source) - 1)];
            } catch (\Exception $exception) {
                $salt .= $source[\rand(0, \count($source) - 1)];
            }
        }

        return $salt;
    }

    /**
     * Gives credentials for authentication
     *
     * @return array of login attributes
     */
    private function getAuthData(): array
    {
        $authData = [];
        $salt = $this->salt(10);
        $at = \date('Ymd\THis');
        $authData['login'] = $this->login;
        $authData['sul'] = $salt;
        $authData['time'] = $at;
        $authData['hash'] = \md5($this->password . $at . $salt);

        return $authData;
    }

    /**
     * Try to output xml if $data in xml format, or else output raw $data
     *
     * @param string $data content of some URL
     * @return String in xml format | String content of some URL
     */
    private function getAnswer(string $data): ?string
    {
        $xmlSolid = \simplexml_load_string($data); // Tries to create valid XML object
        if (!$xmlSolid || !($xmlSolid instanceof \SimpleXMLElement)) {
            return $data;
        }

        return $xmlSolid->asXML() ?: null;
    }

    /**
     * Send a single SMS, does NOT touch SMS queue (use @see sendAllSms to send the queue)
     *
     * @param string $number phone number of receiver
     * @param string $message message for receiver
     * @param \DateTime $time sending time
     * @param string $sender phone number of sender
     * @param string $delivery report?
     * @return array
     */
    public function send(string $number, string $message, \DateTime $time = null, string $sender = '', string $delivery = ''): array
    {
        $response = $this->makeRequest(
            'send_sms',
            [
                'number' => $number,
                'message' => $message,
                'when' => $time ? $time->format('Ymd\THis') : '',
                'sender_id' => $sender,
                'delivery_report' => $delivery
            ]
        );

        return [
            'id' => (string)$response->sms_id,
            'count' => (int)$response->sms_count,
        ];
    }

    private function getErrorMessage(int $id): string
    {
        switch ($id) {
            case 1 :
                return 'neznámá chyba';
            case 2 :
                return 'neplatný login';
            case 3 :
                return 'neplatný hash nebo password (podle varianty zabezpečení přihlášení)';
            case 4 :
                return 'neplatný time, větší odchylka času mezi servery než maximální akceptovaná v nastavení služby SMS Connect';
            case 5 :
                return 'nepovolená IP, viz nastavení služby SMS Connect';
            case 6:
                return 'neplatný název akce';
            case 7 :
                return 'tato sul byla již jednou za daný den použita';
            case 8 :
                return 'nebylo navázáno spojení s databází';
            case 9 :
                return 'nedostatečný kredit';
            case 10 :
                return 'neplatné číslo příjemce SMS';
            case 11 :
                return 'prázdný text zprávy';
            case 12 :
                return 'SMS je delší než povolených 459 znaků';
            default :
                return 'unknown error';
        }
    }

    /**
     * Prepares an SMS into queue for later sending via @see sendAllSms
     *
     * @param string $number phone number of receiver
     * @param string $message message for receiver
     * @param \DateTime|null $time sending time
     * @param string $sender phone number of sender
     * @param string $delivery report?
     */
    public function addSms(string $number, string $message, \DateTime $time = null, string $sender = '', string $delivery = '')
    {
        $sms = $this->smsQueue->addChild('sms');
        $sms->addChild('number', $this->xmlEncode($number));
        $sms->addChild('message', $this->xmlEncode($message));
        $sms->addChild('when', $this->xmlEncode($time ? $time->format('Ymd\THis') : ''));
        $sms->addChild('sender_id', $this->xmlEncode($sender));
        $sms->addChild('delivery_report', $this->xmlEncode($delivery));
    }

    /**
     * Sends all SMS added before to queue via @see addSms
     *
     * @return string response body of the target page or null if SMS queue is empty
     * @throws \Granam\SmsBranaCz\Exceptions\RequestToSmsBranaCzFailed
     */
    public function sendAllSms(): ?string
    {
        if (!$this->smsQueue->count()) {
            return null;
        }

        return $this->makeRequest('xml_queue', [], $this->smsQueue->asXML())->asXML();
    }

    private function xmlEncode(string $string): string
    {
        return \htmlspecialchars(\preg_replace('#[\x00-\x08\x0B\x0C\x0E-\x1F]+#', '', $string), ENT_QUOTES);
    }
}