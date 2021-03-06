<?php
namespace app\components;

use Yii;
use yii\base\ErrorException;

class PayParts
{

    private $prefix = 'ORDER';
    private $OrderID;              //Уникальный номер платежа
    private $StoreId;              //Идентификатор магазина
    private $Password;             //Пароль вашего магазина
    private $PartsCount;           //Количество частей на которые делится сумма транзакции ( >1)
    private $merchantType;         //Тип кредита
    private $ResponseUrl;          //URL, на который Банк отправит результат сделки
    private $RedirectUrl;          //URL, на который Банк сделает редирект клиента
    private $Amount;               //Окончательная сумма покупки, без плавающей точки
    private $ProductsList;         //
    private $RecipientId;
    private $Currency;
    private $Products_String;
    private $PayURL = 'https://payparts2.privatbank.ua/ipp/v2/payment/create';
    private $HoldURL = 'https://payparts2.privatbank.ua/ipp/v2/payment/hold';
    private $StateURL = 'https://payparts2.privatbank.ua/ipp/v2/payment/state';
    private $ConfirmHoldURL = 'https://payparts2.privatbank.ua/ipp/v2/payment/confirm';
    private $CancelHoldUrl = 'https://payparts2.privatbank.ua/ipp/v2/payment/cancel';

    private $LOG = array();


    private $Keys_Prods = ['name', 'count', 'price'];

    private $options = ['PartsCount', 'merchantType', 'ProductsList'];

    /**
     * PayParts constructor.
     * создаём идентификаторы магазина
     * @param string $StoreId - Идентификатор магазина
     * @param string $Password - Пароль вашего магазина
     */
    public function __construct($StoreId, $Password)
    {
        $this->setStoreId($StoreId);
        $this->setPassword($Password);

    }

    /**
     * PayParts SetOptions.
     * @param array $options
     *
     * ResponseUrl - URL, на который Банк отправит результат сделки (НЕ ОБЯЗАТЕЛЬНО)<br>
     * RedirectUrl - URL, на который Банк сделает редирект клиента (НЕ ОБЯЗАТЕЛЬНО)<br>
     *PartsCount - Количество частей на которые делится сумма транзакции ( >1)<br>
     *Prefix - параметр не обязательный если Prefix указан с пустотой или не указа вовсе префикс будет ORDER<br>
     *OrderID' - если OrderID задан с пустотой или не укан вовсе OrderID сгенерится автоматически<br>
     *merchantType - II - Мгновенная рассрочка; PP - Оплата частями<br>
     *Currency' - можна указать другую валюту 980 – Украинская гривна; 840 – Доллар США; 643 – Российский рубль. Значения в соответствии с ISO<br>
     *ProductsList - Список продуктов, каждый продукт содержит поля: name - Наименование товара price - Цена за еденицу товара (Пример: 100.00) count - Количество товаров данного вида<br>
     *recipientId - Идентификатор получателя, по умолчанию берется основной получатель. Установка основного получателя происходит в профиле магазина.
     *
     */

    public function SetOptions($options = [])
    {
        if (empty($options) or !is_array($options)) {
            throw new ErrorException("Options must by set as array");
        } else {

            foreach ($options as $PPOptions => $value) {
                if (method_exists('app\components\PayParts', "set$PPOptions")) {
                    call_user_func_array(array($this, "set$PPOptions"), array($value));
                } else {
                    throw new ErrorException($PPOptions . ' cannot be set by this setter');
                }
            }
        }

        $flag = 0;

        foreach ($this->options as $variable) {
            if (isset($this->{$variable})) {
                $flag += 1;
            } else {
                throw new ErrorException($variable . ' is necessary');
            }
        }
        if ($flag == count($this->options)) {
            $this->options['SUCCESS'] = true;
        } else {
            $this->options['SUCCESS'] = false;
        }
    }

    /**
     * PayParts Create.
     * Создание платежа
     * @param string $method
     * <a href="https://bw.gitbooks.io/api-oc/content/pay.html">'hold'</a> - Создание платежа без списания<br>
     * <a href="https://bw.gitbooks.io/api-oc/content/hold.html">'pay'</a> - Создание платежа со списания
     *
     *
     * @return mixed|string
     *
     */
    public function Create($method = 'pay')
    {
        if ($this->options['SUCCESS']) {

            //проверка метода
            if ($method == 'hold') {
                $Url = $this->HoldURL;
                $this->LOG['Type'] = 'Hold';
            } else {
                $Url = $this->PayURL;
                $this->LOG['Type'] = 'Pay';
            }

            $SignatureForCall = [
                $this->Password,
                $this->StoreId,
                $this->OrderID,
                (string)($this->Amount * 100),
                $this->PartsCount,
                $this->merchantType,
                $this->ResponseUrl,
                $this->RedirectUrl,
                $this->Products_String,
                $this->Password
            ];

            $param['storeId'] = $this->StoreId;
            $param['orderId'] = $this->OrderID;
            $param['amount'] = $this->Amount;
            $param['partsCount'] = $this->PartsCount;
            $param['merchantType'] = $this->merchantType;
            $param['products'] = $this->ProductsList;
            $param['responseUrl'] = $this->ResponseUrl;
            $param['redirectUrl'] = $this->RedirectUrl;
            $param['signature'] = $this->CalcSignature($SignatureForCall);

            if (!empty($this->Currency)) {
                $param['currency'] = $this->Currency;
            }

            if (!empty($this->RecipientId)) {
                $param['recipient'] = array('recipientId' => $this->RecipientId);
            }


            $this->LOG['CreateData'] = json_encode($param);

            $CreateResult = json_decode($this->sendPost($param, $Url), true);
            $checkSignature = [
                $this->Password,
                @$CreateResult['state'],
                @$CreateResult['storeId'],
                @$CreateResult['orderId'],
                @$CreateResult['message'],
                @$CreateResult['token'],
                $this->Password
            ];

            $this->LOG['CreateResult'] = json_encode($CreateResult);

            if ($this->CalcSignature($checkSignature) == $CreateResult['signature']) {
                return $CreateResult;
            } else {
                return 'error';
            }


        } else {
            throw new ErrorException("No options");
        }

    }


    /**
     * PayParts getState.
     * <a href="https://bw.gitbooks.io/api-oc/content/state.html">Получение результата сделки</a>
     * @param string $orderId -Уникальный номер платежа
     * @param bool $showRefund true - получить детали возвратов по платежу,<br> false - получить статус платежа без дополнительных деталей о возвратах
     * @return mixed|string
     */
    public function getState($orderId, $showRefund = true)
    {
        $SignatureForCall = [$this->Password, $this->StoreId, $orderId, $this->Password];

        $data = array(
            "storeId" => $this->StoreId,
            "orderId" => $orderId,
            "showRefund" => var_export($showRefund, true), //($showRefund) ? 'true' : 'false'
            "signature" => $this->CalcSignature($SignatureForCall)
        );

        $res = json_decode($this->sendPost($data, $this->StateURL), true);

        $ResSignature = [
            $this->Password,
            $res['state'],
            $res['storeId'],
            $res['orderId'],
            $res['paymentState'],
            $res['message'],
            $this->Password
        ];

        if ($this->CalcSignature($ResSignature) == $res['signature']) {
            return $res;
        } else {
            return 'error';
        }
    }


    /**
     * PayParts checkCallBack.
     * Получение результата сделки (асинхронный коллбэк)
     * @param string $string результат post запроса
     * @return mixed|string валидирует и отдаёт ответ
     */
    public function checkCallBack($string)
    {

        $sa = json_decode($string, true);

        $srt = [$this->Password, $this->StoreId, $sa['orderId'], $sa['paymentState'], $sa['message'], $this->Password];

        if ($this->CalcSignature($srt) == $sa['signature']) {
            return $sa;
        } else {
            return ('error');
        }

    }

    /**
     * PayParts ConfirmHold.
     * <a href="https://bw.gitbooks.io/api-oc/content/confirm.html">Подтверждение платежа</a>
     * @param string $orderId Уникальный номер платежа
     * @return mixed|string
     */
    public function ConfirmHold($orderId)
    {
        $signatureForConfirmHold = [$this->Password, $this->StoreId, $orderId, $this->Password];

        $data = array(
            "storeIdentifier" => $this->StoreId,
            "orderId" => $orderId,
            "signature" => $this->CalcSignature($signatureForConfirmHold)
        );

        $res = json_decode($this->sendPost($data, $this->ConfirmHoldURL), true);

        return $res;

        /** @noinspection PhpUnreachableStatementInspection */
        $ResSignature = array($this->Password, $res['storeIdentifier'], $res['orderId'], $this->Password);

        if ($this->CalcSignature($ResSignature) == $res['signature']) {
            return $res;
        } else {
            return 'error';
        }
    }

    /**
     * PayParts CancelHold.
     * <a href="https://bw.gitbooks.io/api-oc/content/cancel.html">Отмена платежа</a>
     * @param string $orderId Уникальный номер платежа
     * @param string $recipientId Идентификатор получателя, по умолчанию берется основной получатель. Установка основного получателя происходит в профиле магазина.
     * @return mixed|string
     */
    public function CancelHold($orderId, $recipientId = '')
    {

        $signatureForCancelHold = [$this->Password, $this->StoreId, $orderId, $this->Password];


        $data = array(
            "storeId" => $this->StoreId,
            "orderId" => $orderId,
            "signature" => $this->CalcSignature($signatureForCancelHold)
        );
        if (!empty($recipientId)) {
            $data['recipientId'] = $recipientId;
        }


        $res = json_decode($this->sendPost($data, $this->CancelHoldUrl), true);

        return $res;

        /** @noinspection PhpUnreachableStatementInspection */
        $ResSignature = array($this->Password, $res['storeIdentifier'], $res['orderId'], $this->Password);

        if ($this->CalcSignature($ResSignature) == $res['signature']) {
            return $res;
        } else {
            return 'error';
        }
    }

    /**
     * PayParts getLOG. частичный лог
     * @return array
     */
    public function getLOG()
    {
        return $this->LOG;
    }

    /**
     * @param $array
     * @return string
     */
    private function CalcSignature($array)
    {
        $signature = '';
        foreach ($array as $item) {
            $signature .= $item;
        }
        return (base64_encode(sha1($signature, true)));

    }

    /**
     * @param $param
     * @param $url
     * @return mixed
     */
    private function sendPost($param, $url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER,
            ["Content-Type: application/json", "Accept: application/json; charset=utf-8"]);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($param));
        $result = curl_exec($ch);
        return $result;
    }

    /**
     * @param $argument
     */
    private function setStoreId($argument)
    {
        if (empty($argument)) {
            throw new ErrorException('StoreId is empty');
        }
        $this->StoreId = $argument;
    }

    /**
     * @param $argument
     */
    private function setPassword($argument)
    {
        if (empty($argument)) {
            throw new ErrorException('Password is empty');
        }
        $this->Password = $argument;
    }


    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function setResponseUrl(
        /** @noinspection PhpDocSignatureInspection */
        $argument
    ) {
        if (!empty($argument)) {
            $this->ResponseUrl = $argument;
        }
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function setRedirectUrl(
        /** @noinspection PhpDocSignatureInspection */
        $argument
    ) {
        if (!empty($argument)) {
            $this->RedirectUrl = $argument;
        }
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function setPartsCount(
        /** @noinspection PhpDocSignatureInspection */
        $argument
    ) {
        if ($argument < 1) {
            throw new ErrorException('PartsCount cannot be <1 ');
        }
        $this->PartsCount = $argument;
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function setPrefix(
        /** @noinspection PhpDocSignatureInspection */
        $argument = ''
    ) {
        if (!empty($argument)) {
            $this->prefix = $argument;
        }
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function setOrderID(
        /** @noinspection PhpDocSignatureInspection */
        $argument = ''
    ) {
        if (empty($argument)) {
            $this->OrderID = $this->prefix . '-' . strtoupper(sha1(time() . rand(1, 99999)));
        } else {
            $this->OrderID = $this->prefix . '-' . strtoupper($argument);
        }

        $this->LOG['OrderID'] = $this->OrderID;
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function setRecipientId(
        /** @noinspection PhpDocSignatureInspection */
        $argument = ''
    ) {
        if (!empty($argument)) {
            $this->RecipientId = $argument;
        }
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function setMerchantType(
        /** @noinspection PhpDocSignatureInspection */
        $argument
    ) {
        if (in_array($argument, array('II', 'PP'))) {
            $this->merchantType = $argument;
        } else {
            throw new ErrorException('merchantType must be in array(\'II\', \'PP\')');
        }
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function setCurrency(
        /** @noinspection PhpDocSignatureInspection */
        $argument = ''
    ) {
        if (!empty($argument)) {
            if (in_array($argument, array('980', '840', '643'))) {
                $this->Currency = $argument;
            } else {
                throw new ErrorException('something is wrong with Currency');
            }
        }
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function setProductsList(
        /** @noinspection PhpDocSignatureInspection */
        $argument
    ) {
        if (!empty($argument) and is_array($argument)) {
            foreach ($argument as $arr) {
                foreach ($this->Keys_Prods as $item) {
                    if (!array_key_exists($item, $arr)) {
                        throw new ErrorException("$item key does not exist");
                    }
                    if (empty($arr[$item])) {
                        throw new ErrorException("$item value cannot be empty");
                    }
                }
                $this->Products_String .= $arr['name'] . $arr['count'] . number_format($arr['price'], 2, '', '');
            }
            $this->ProductsList = $argument;
        } else {
            throw new ErrorException('something is wrong');
        }
    }
    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function setAmount(
        /** @noinspection PhpDocSignatureInspection */
        $argument = ''
    ) {
        if (!empty($argument)) {
            if (!empty($argument)) {
                $this->Amount = $argument;
            } else {
                throw new ErrorException('something is wrong with Currency');
            }
        }
    }
}
