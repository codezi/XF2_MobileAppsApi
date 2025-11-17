<?php

namespace Truonglv\Api\Payment;

class GooglePlaySubscription
{
    const ANDROID_PUBLISHER_API = 'https://androidpublisher.googleapis.com/androidpublisher/v3/applications';
    private string $serviceAccountEmail;
    private string $privateKey;
    private string $packageName;

    public function __construct(array $serviceAccountJson, string $packageName) {
        $this->serviceAccountEmail = $serviceAccountJson['client_email'];
        $this->privateKey = $serviceAccountJson['private_key'];
        $this->packageName = $packageName;
    }

    private function createJWT(): string
    {
        $now = time();
        $exp = $now + 3600;

        // Header
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT'
        ];

        // Payload
        $payload = [
            'iss' => $this->serviceAccountEmail,
            'scope' => 'https://www.googleapis.com/auth/androidpublisher',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $exp
        ];

        // Encode
        $base64UrlHeader = $this->base64UrlEncode(\GuzzleHttp\Utils::jsonEncode($header));
        $base64UrlPayload = $this->base64UrlEncode(\GuzzleHttp\Utils::jsonEncode($payload));

        // Signature
        $signatureInput = $base64UrlHeader . '.' . $base64UrlPayload;
        $signature = '';
        \openssl_sign($signatureInput, $signature, $this->privateKey, \OPENSSL_ALGO_SHA256);
        $base64UrlSignature = $this->base64UrlEncode($signature);

        return $signatureInput . '.' . $base64UrlSignature;
    }

    private function getAccessToken(): string
    {
        $cacheKey = \sprintf('google_play_subscription_access_token_%s', $this->serviceAccountEmail);
        $simpleCache = \XF::app()->simpleCache();
        $value = $simpleCache->getValue('Truonglv/Api', $cacheKey);
        if ($value && $value['expires_at'] > \time()) {
            return $value['access_token'];
        }

        $jwt = $this->createJWT();
        $client = \XF::app()->http()->client();

        $resp = $client->post('https://oauth2.googleapis.com/token', [
            'form_params' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt
            ],
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ]
        ]);

        if ($resp->getStatusCode() !== 200) {
            throw new \Exception('Failed to get access token: ' . $resp->getStatusCode());
        }

        $data = \GuzzleHttp\Utils::jsonDecode($resp->getBody()->getContents(), true);
        $data['expires_at'] = \time() + $data['expires_in'];

        $simpleCache->setValue('Truonglv/Api', $cacheKey, $data);

        return $data['access_token'];
    }

    public function getSubscription(string $purchaseToken): AndroidSubscription
    {
        $accessToken = $this->getAccessToken();

        $url = sprintf(
            '%s/%s/purchases/subscriptionsv2/tokens/%s',
            static::ANDROID_PUBLISHER_API,
            $this->packageName,
            $purchaseToken
        );

        $client = \XF::app()->http()->client();
        $resp = $client->get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ]
        ]);

        if ($resp->getStatusCode() !== 200) {
            throw new \Exception('Failed to verify subscription: ' . $resp->getStatusCode());
        }

        $data = \GuzzleHttp\Utils::jsonDecode($resp->getBody()->getContents(), true);

        return $this->parseSubscriptionDataV2($data);
    }

    /**
     * Parse subscription data v2 and return an AndroidSubscription object.
     *
     * @param array $data
     * @return AndroidSubscription
     * @throws \Exception
     */
    public static function parseSubscriptionDataV2(array $data): AndroidSubscription
    {
        $lineItem = $data['lineItems'][0] ?? null;
        if (!$lineItem) {
            throw new \Exception('No line items found in subscription data');
        }

        $expiryTimeMillis = null;
        if (isset($lineItem['expiryTime'])) {
            $ts = strtotime($lineItem['expiryTime']);
            $expiryTimeMillis = $ts !== false ? (int)($ts * 1000) : null;
        }

        // Subscription state
        $subscriptionState = $data['subscriptionState'] ?? null;
        $isActive = ($subscriptionState === 'SUBSCRIPTION_STATE_ACTIVE');
        $isCancelled = ($subscriptionState === 'SUBSCRIPTION_STATE_CANCELLED');

        // Auto renewing info
        $autoRenewingPlan = $lineItem['autoRenewingPlan'] ?? null;
        $autoRenewing = (bool)$autoRenewingPlan;

        $productId = $lineItem['productId'] ?? null;
        $basePlanId = null;
        $offerId = null;

        if (isset($lineItem['offerDetails'])) {
            $basePlanId = $lineItem['offerDetails']['basePlanId'] ?? null;
            $offerId = $lineItem['offerDetails']['offerId'] ?? null;
        }

        $startTimeMillis = null;
        if (isset($data['startTime'])) {
            $ts = strtotime($data['startTime']);
            $startTimeMillis = $ts !== false ? (int)($ts * 1000) : null;
        }

        $now = (int)(time() * 1000);

        $subscription = (new AndroidSubscription())
            ->setSubscriptionState($subscriptionState)
            ->setProductId($productId)
            ->setBasePlanId($basePlanId)
            ->setOfferId($offerId)
            ->setStartTimeMillis($startTimeMillis)
            ->setExpiryTimeMillis($expiryTimeMillis)
            ->setAutoRenewing($autoRenewing)
            ->setRegionCode($data['regionCode'] ?? null)
            ->setAcknowledgementState($data['acknowledgementState'] ?? null)
            ->setKind($data['kind'] ?? null)
            ->setTestPurchase($data['testPurchase'] ?? null)
            ->setLatestOrderId($lineItem['latestSuccessfulOrderId'] ?? null)
            ->setIsCancelled($isCancelled);

        // Determine validity
        if ($isActive && $expiryTimeMillis && $expiryTimeMillis > $now) {
            $subscription->setIsValid(true);
        } else {
            $subscription->setIsValid(false);
        }

        // Canceled state context
        if (isset($data['canceledStateContext'])) {
            $subscription->setCanceledStateContext($data['canceledStateContext']);

            if (isset($data['canceledStateContext']['userInitiatedCancellation'])) {
                $cancelInfo = $data['canceledStateContext']['userInitiatedCancellation'];
                if (isset($cancelInfo['cancelTime'])) {
                    $ts = strtotime($cancelInfo['cancelTime']);
                    $subscription->setUserCancellationTimeMillis($ts !== false ? (int)($ts * 1000) : null);
                }
            }
        }

        // Paused state context
        if (isset($data['pausedStateContext'])) {
            $subscription->setPausedStateContext($data['pausedStateContext']);
        }

        $subscription->setExpiryDate($expiryTimeMillis ? date('Y-m-d H:i:s', (int)($expiryTimeMillis / 1000)) : null);
        $subscription->setStartDate($startTimeMillis ? date('Y-m-d H:i:s', (int)($startTimeMillis / 1000)) : null);

        return $subscription;
    }

    public function acknowledgeSubscription(string $subscriptionId, string $purchaseToken, array $developerPayload = []): bool
    {
        $accessToken = $this->getAccessToken();

        $url = sprintf(
            '%s/%s/purchases/subscriptions/%s/tokens/%s:acknowledge',
            static::ANDROID_PUBLISHER_API,
            $this->packageName,
            $subscriptionId,
            $purchaseToken
        );

        $client = \XF::app()->http()->client();
        $resp = $client->post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
            ],
            'json' => [
                'developerPayload' => \GuzzleHttp\Utils::jsonEncode($developerPayload),
            ]
        ]);

        return $resp->getStatusCode() === 204; // 204 = Success
    }

    private function base64UrlEncode($data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}