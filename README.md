```php
<?php
use \Granam\SmsBranaCz\SmsSender;

try {
    $smsSender = new SmsSender('your_smsbrana_cz_login', 'your_smsbrana_cz_password', new \GuzzleHttp\Client(['connection_timeout' => 30]));
    $smsSender->send(
        '+420123456789', // phone number
        'Your account has been activated, enjoy!' // message text
    );
} catch (\Granam\SmsBranaCz\Exceptions\Exception $smsException) {
    \trigger_error('Could not sent SMS: ' . $smsException->getMessage(), E_USER_WARNING);
}
```