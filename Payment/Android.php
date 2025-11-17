<?php

namespace Truonglv\Api\Payment;

use XF;
use Throwable;
use function ceil;
use function time;
use function trim;
use LogicException;
use XF\Mvc\Controller;
use function preg_match;
use function array_replace;
use function base64_decode;
use XF\Purchasable\Purchase;
use XF\Entity\PaymentProfile;
use XF\Payment\CallbackState;
use XF\Entity\PurchaseRequest;
use XF\Payment\AbstractProvider;
use Truonglv\Api\Entity\IAPProduct;
use Truonglv\Api\Finder\IAPProductFinder;

class Android extends AbstractProvider implements IAPInterface
{
    const KEY_STATE_ANDROID_PURCHASE = 'androidPurchase';
    const KEY_STATE_DATA_RAW = 'dataRaw';
    const KEY_INPUT_FILTERED = 'inputFiltered';
    const KEY_INPUT_RAW = 'inputRaw';

    /**
     * @return string
     */
    public function getTitle()
    {
        return '[tl] Api: In-app purchase Android';
    }

    /**
     * @param Controller $controller
     * @param PurchaseRequest $purchaseRequest
     * @param Purchase $purchase
     * @return mixed
     */
    public function initiatePayment(Controller $controller, PurchaseRequest $purchaseRequest, Purchase $purchase)
    {
        throw new LogicException('Not supported');
    }

    /**
     * @param array $options
     * @param mixed $errors
     * @return bool
     */
    public function verifyConfig(array & $options, & $errors = [])
    {
        $options = array_replace([
            'app_bundle_id' => '',
            'service_account_json' => '',
            'expires_extra_seconds' => 120,
        ], $options);

        if (strlen($options['app_bundle_id']) === 0) {
            $errors[] = XF::phrase('tapi_iap_ios_please_enter_valid_app_bundle_id');

            return false;
        }

        try {
            \GuzzleHttp\Utils::jsonDecode($options['service_account_json']);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();

            return false;
        }

        return true;
    }

    /**
     * @param Controller $controller
     * @param PurchaseRequest $purchaseRequest
     * @param PaymentProfile $paymentProfile
     * @param Purchase $purchase
     * @return mixed
     */
    public function processPayment(Controller $controller, PurchaseRequest $purchaseRequest, PaymentProfile $paymentProfile, Purchase $purchase)
    {
        throw new LogicException('Not supported');
    }

    public function setupCallback(\XF\Http\Request $request)
    {
        $inputRaw = trim($request->getInputRaw());
        $state = new CallbackState();
        $state->{static::KEY_INPUT_RAW} = $inputRaw;

        $json = (array) \GuzzleHttp\Utils::jsonDecode($inputRaw, true);
        if (!isset($json['message'])) {
            $state->logType = 'error';
            $state->logMessage = 'Invalid payload. No `message`';

            return $state;
        }

        $data = (array) \GuzzleHttp\Utils::jsonDecode(base64_decode($json['message']['data'], true), true);

        $filtered = $request->getInputFilterer()->filterArray($data, [
            'version' => 'str',
            'packageName' => 'str',
            'eventTimeMillis' => 'uint',
            'subscriptionNotification' => [
                'version' => 'str',
                'notificationType' => 'int',
                'purchaseToken' => 'str',
                'subscriptionId' => 'str'
            ],
        ]);

        $state->{static::KEY_INPUT_FILTERED} = $filtered;
        $state->{static::KEY_STATE_DATA_RAW} = $json;

        /** @var IAPProduct|null $product */
        $product = XF::finder(IAPProductFinder::class)
            ->where('platform', 'android')
            ->where('store_product_id', $filtered['subscriptionNotification']['subscriptionId'])
            ->fetchOne();
        if ($product === null) {
            $state->logType = 'info';
            $state->logMessage = 'No iap product';

            return $state;
        }

        if ($filtered['packageName'] !== $product->PaymentProfile->options['app_bundle_id']) {
            $state->logType = 'info';
            $state->logMessage = 'Invalid app bundle ID';

            return $state;
        }

        $service = $this->getGooglePlaySubscriptionHelper($product->PaymentProfile);

        try {
            $purchase = $service->getSubscription(
                $filtered['subscriptionNotification']['purchaseToken']
            );
        } catch (Throwable $e) {
            \XF::app()->logException($e);
            $state->logType = 'error';
            $state->logMessage = 'Get purchase subscription error: ' . $e->getMessage();

            return $state;
        }

        $state->{static::KEY_STATE_ANDROID_PURCHASE} = $purchase;
        $transInfo = $this->getIAPTransactionInfo($purchase);
        if ($transInfo === null) {
            $state->logType = 'error';
            $state->logMessage = 'No orderId';

            return $state;
        }

        $state->subscriberId = $transInfo['subscriber_id'];
        $state->transactionId = $transInfo['transaction_id'];

        $purchaseRequest = XF::em()->findOne(
            PurchaseRequest::class,
            ['provider_metadata' => $transInfo['subscriber_id']]
        );

        if ($purchaseRequest !== null) {
            $state->purchaseRequest = $purchaseRequest; // sets requestKey too
        } else {
            $logFinder = XF::finder(XF\Finder\PaymentProviderLogFinder::class)
                ->where('subscriber_id', $transInfo['subscriber_id'])
                ->where('provider_id', $this->providerId)
                ->order('log_date', 'desc');

            foreach ($logFinder->fetch() as $log) {
                if ($log->purchase_request_key) {
                    $state->requestKey = $log->purchase_request_key;

                    break;
                }
            }
        }

        if (!$purchase->isAcknowledged()) {
            try {
                $this->ackPurchase(
                    $service,
                    $filtered['subscriptionNotification']['subscriptionId'],
                    $filtered['subscriptionNotification']['purchaseToken'],
                    [
                        'request_key' => $state->requestKey,
                    ]
                );
            } catch (Throwable $e) {
                $state->logType = 'error';
                $state->logMessage = 'failed to ack purchase';

                return $state;
            }
        }

        $state->ip = $request->getIp();
        $state->_POST = $_POST;

        return $state;
    }

    /**
     * @param CallbackState $state
     * @return bool
     */
    public function validateCallback(CallbackState $state)
    {
        if ($this->isEventSkippable($state)) {
            $state->httpCode = 200;

            return false;
        }

        return parent::validateCallback($state);
    }

    protected function isEventSkippable(CallbackState $state): bool
    {
        $dataRaw = $state->{self::KEY_STATE_DATA_RAW} ?? [];
        if (isset($dataRaw['deliveryAttempt']) && $dataRaw['deliveryAttempt'] >= 5) {
            $state->logType = 'info';
            $state->logMessage = 'Too many delivery attempts';

            return true;
        }

        return false;
    }

    /**
     * @param CallbackState $state
     * @return bool
     */
    public function validateTransaction(CallbackState $state)
    {
        $paymentRepo = XF::repository(XF\Repository\PaymentRepository::class);
        $purchase = $this->getSubscriptionPurchase($state);
        if ($purchase !== null) {
            $total = null;

            if ($this->isPurchaseCancelled($purchase)) {
                $total = $paymentRepo->findLogsByTransactionIdForProvider(
                    $state->transactionId,
                    $this->providerId,
                    ['cancel']
                )->total();
            } elseif ($this->isPurchaseReceived($purchase)) {
                $total = $paymentRepo->findLogsByTransactionIdForProvider(
                    $state->transactionId,
                    $this->providerId,
                    ['payment']
                )->total();
            }

            if ($total !== null) {
                if ($total > 0) {
                    $state->logType = 'info';
                    $state->logMessage = 'Transaction already processed. Skipping.';

                    return false;
                }

                return true;
            }
        }

        return parent::validateTransaction($state);
    }

    protected function getSubscriptionPurchase(CallbackState $state): ?AndroidSubscription
    {
        return $state->{static::KEY_STATE_ANDROID_PURCHASE};
    }

    /**
     * @param CallbackState $state
     * @link https://developers.google.com/android-publisher/api-ref/rest/v3/purchases.subscriptions#SubscriptionPurchase
     * @return void
     */
    public function getPaymentResult(CallbackState $state)
    {
        $purchase = $this->getSubscriptionPurchase($state);
        if ($purchase !== null) {
            if ($this->isPurchaseCancelled($purchase)) {
                $state->logType = 'cancel';
            } elseif ($this->isPurchaseReceived($purchase)) {
                $state->paymentResult = CallbackState::PAYMENT_RECEIVED;
            }
        }
    }

    protected function isPurchaseCancelled(AndroidSubscription $purchase): bool
    {
        return $purchase->getIsCancelled();
    }

    protected function isPurchaseReceived(AndroidSubscription $purchase): bool
    {
        return $purchase->getIsValid();
    }

    /**
     * @param CallbackState $state
     * @return void
     */
    public function prepareLogData(CallbackState $state)
    {
        $logDetails = [];

        $logDetails[static::KEY_INPUT_RAW] = $state->{static::KEY_INPUT_RAW};
        $logDetails[static::KEY_INPUT_FILTERED] = $state->{static::KEY_INPUT_FILTERED};
        $logDetails[static::KEY_STATE_DATA_RAW] = $state->{static::KEY_STATE_DATA_RAW};

        $purchase = $this->getSubscriptionPurchase($state);
        if ($purchase !== null) {
            $logDetails[static::KEY_STATE_ANDROID_PURCHASE] = $this->getPurchaseForLogging($purchase);
        }

        $state->logDetails = $logDetails;
    }

    protected function getPurchaseForLogging(AndroidSubscription $purchase)
    {
        return $purchase->toArray();
    }

    protected function getClient(): \Google\Client
    {
        $client = new \Google\Client();
        $client->setHttpClient(\XF::app()->http()->client());

        return $client;
    }

    public function getGooglePlaySubscriptionHelper(PaymentProfile $paymentProfile): GooglePlaySubscription
    {
        $serviceAccount = \GuzzleHttp\Utils::jsonDecode($paymentProfile->options['service_account_json'], true);

        return new GooglePlaySubscription($serviceAccount, $paymentProfile->options['app_bundle_id']);
    }

    protected function getIAPTransactionInfo(AndroidSubscription $purchase): ?array
    {
        $transactionId = $purchase->getLatestOrderId();
        if (\strlen($transactionId) === 0) {
            return null;
        }

        return $this->getTransactionInfoFromId($transactionId);
    }

    public function getTransactionInfoFromId(string $transactionId): array
    {
        if (preg_match('#(.*)\.{2}(\d+)$#', $transactionId, $matches) === 1) {
            $subscriberId = $matches[1];
        } else {
            $subscriberId = $transactionId;
        }

        return [
            'transaction_id' => $transactionId,
            'subscriber_id' => $subscriberId,
        ];
    }

    public function verifyIAPTransaction(PurchaseRequest $purchaseRequest, array $payload): array
    {
        $knownTransactionId = $payload['purchase']['transactionId'] ?? null;
        if ($knownTransactionId !== null) {
            $info = $this->getTransactionInfoFromId($knownTransactionId);
            // quickly update request sub info to prevent get many errors of
            // unknown purchase request key
            $purchaseRequest->fastUpdate('provider_metadata', $info['subscriber_id']);
        }

        $paymentProfile = $purchaseRequest->PaymentProfile;

        $service = $this->getGooglePlaySubscriptionHelper($paymentProfile);
        $purchase = $service->getSubscription($payload['purchase_token']);

        $paymentLog = XF::em()->create(XF\Entity\PaymentProviderLog::class);
        $paymentLog->log_type = 'info';
        $paymentLog->log_message = '[Android] Verify receipt response';
        $paymentLog->log_details = [
            'payload' => $payload,
            'response' => $this->getPurchaseForLogging($purchase),
            '_POST' => $_POST,
            'store_product_id' => $purchaseRequest->extra_data['store_product_id'],
        ];
        $paymentLog->purchase_request_key = $purchaseRequest->request_key;
        $paymentLog->provider_id = $this->getProviderId();
        $paymentLog->save();

        $expires = ceil($purchase->getExpiryTimeMillis() / 1000) + $this->getPurchaseExpiresExtraSeconds($purchaseRequest->PaymentProfile);
        if ($expires <= time()) {
            throw new PurchaseExpiredException();
        }

        // https://developers.google.com/android-publisher/api-ref/rest/v3/purchases.subscriptions#SubscriptionPurchase
        $transInfo = $this->getIAPTransactionInfo($purchase);
        if ($transInfo !== null && $this->isPurchaseReceived($purchase)) {
            $paymentLog->fastUpdate([
                'transaction_id' => $transInfo['transaction_id'],
                'subscriber_id' => $transInfo['subscriber_id'],
            ]);

            // ack
            if (!$purchase->isAcknowledged()) {
                $this->ackPurchase($service, $payload['subscription_id'], $payload['purchase_token'], [
                    'user_id' => $purchaseRequest->user_id,
                    'request_key' => $purchaseRequest->request_key,
                ]);
            }

            return $transInfo;
        }

        $_POST['android_purchase'] = $this->getPurchaseForLogging($purchase);

        throw new LogicException('Cannot verify transaction');
    }

    protected function getPurchaseExpiresExtraSeconds(PaymentProfile $paymentProfile): int
    {
        return $paymentProfile->options['expires_extra_seconds'] ?? 120;
    }

    protected function ackPurchase(GooglePlaySubscription $publisher, string $subId, string $token, array $devPayload = []): void
    {
        $publisher->acknowledgeSubscription($subId, $token, $devPayload);
    }
}
