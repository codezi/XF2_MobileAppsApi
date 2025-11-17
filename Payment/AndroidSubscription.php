<?php

namespace Truonglv\Api\Payment;

class AndroidSubscription
{
    /** @var bool */
    private $isValid = false;

    /** @var string|null */
    private $subscriptionState;

    /** @var string|null */
    private $productId;

    /** @var string|null */
    private $basePlanId;

    /** @var string|null */
    private $offerId;

    /** @var int|null Milliseconds since epoch */
    private $startTimeMillis;

    /** @var int|null Milliseconds since epoch */
    private $expiryTimeMillis;

    /** @var bool */
    private $autoRenewing = false;

    /** @var string|null */
    private $regionCode;

    /** @var mixed */
    private $acknowledgementState;

    /** @var string|null */
    private $kind;

    /** @var mixed */
    private $testPurchase;

    /** @var string|null */
    private $latestOrderId;

    /** @var bool */
    private $isCancelled = false;

    /** @var array|null */
    private $canceledStateContext;

    /** @var int|null Milliseconds since epoch */
    private $userCancellationTimeMillis;

    /** @var array|null */
    private $pausedStateContext;

    /** @var string|null human readable date Y-m-d H:i:s */
    private $expiryDate;

    /** @var string|null human readable date Y-m-d H:i:s */
    private $startDate;

    // -- Getters and setters (fluent) --

    public function getIsValid(): bool
    {
        return $this->isValid;
    }

    public function setIsValid(bool $isValid)
    {
        $this->isValid = $isValid;
        return $this;
    }

    public function getSubscriptionState(): ?string
    {
        return $this->subscriptionState;
    }

    public function setSubscriptionState(?string $subscriptionState)
    {
        $this->subscriptionState = $subscriptionState;
        return $this;
    }

    public function getProductId(): ?string
    {
        return $this->productId;
    }

    public function setProductId(?string $productId)
    {
        $this->productId = $productId;
        return $this;
    }

    public function getBasePlanId(): ?string
    {
        return $this->basePlanId;
    }

    public function setBasePlanId(?string $basePlanId)
    {
        $this->basePlanId = $basePlanId;
        return $this;
    }

    public function getOfferId(): ?string
    {
        return $this->offerId;
    }

    public function setOfferId(?string $offerId)
    {
        $this->offerId = $offerId;
        return $this;
    }

    public function getStartTimeMillis(): ?int
    {
        return $this->startTimeMillis;
    }

    public function setStartTimeMillis(?int $startTimeMillis)
    {
        $this->startTimeMillis = $startTimeMillis;
        return $this;
    }

    public function getExpiryTimeMillis(): ?int
    {
        return $this->expiryTimeMillis;
    }

    public function setExpiryTimeMillis(?int $expiryTimeMillis)
    {
        $this->expiryTimeMillis = $expiryTimeMillis;
        return $this;
    }

    public function getAutoRenewing(): bool
    {
        return $this->autoRenewing;
    }

    public function setAutoRenewing(bool $autoRenewing)
    {
        $this->autoRenewing = $autoRenewing;
        return $this;
    }

    public function getRegionCode(): ?string
    {
        return $this->regionCode;
    }

    public function setRegionCode(?string $regionCode)
    {
        $this->regionCode = $regionCode;
        return $this;
    }

    /**
     * ACKNOWLEDGEMENT_STATE_UNSPECIFIED|ACKNOWLEDGEMENT_STATE_PENDING|ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED
     * @return string
     */
    public function getAcknowledgementState()
    {
        return $this->acknowledgementState;
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledgementState === 'ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED';
    }

    public function setAcknowledgementState($acknowledgementState)
    {
        $this->acknowledgementState = $acknowledgementState;
        return $this;
    }

    public function getKind(): ?string
    {
        return $this->kind;
    }

    public function setKind(?string $kind)
    {
        $this->kind = $kind;
        return $this;
    }

    public function getTestPurchase()
    {
        return $this->testPurchase;
    }

    public function setTestPurchase($testPurchase)
    {
        $this->testPurchase = $testPurchase;
        return $this;
    }

    public function getLatestOrderId(): ?string
    {
        return $this->latestOrderId;
    }

    public function setLatestOrderId(?string $latestOrderId)
    {
        $this->latestOrderId = $latestOrderId;
        return $this;
    }

    public function getIsCancelled(): bool
    {
        return $this->isCancelled;
    }

    public function setIsCancelled(bool $isCancelled)
    {
        $this->isCancelled = $isCancelled;
        return $this;
    }

    public function getCanceledStateContext(): ?array
    {
        return $this->canceledStateContext;
    }

    public function setCanceledStateContext(?array $canceledStateContext)
    {
        $this->canceledStateContext = $canceledStateContext;
        return $this;
    }

    public function getUserCancellationTimeMillis(): ?int
    {
        return $this->userCancellationTimeMillis;
    }

    public function setUserCancellationTimeMillis(?int $userCancellationTimeMillis)
    {
        $this->userCancellationTimeMillis = $userCancellationTimeMillis;
        return $this;
    }

    public function getPausedStateContext(): ?array
    {
        return $this->pausedStateContext;
    }

    public function setPausedStateContext(?array $pausedStateContext)
    {
        $this->pausedStateContext = $pausedStateContext;
        return $this;
    }

    public function getExpiryDate(): ?string
    {
        return $this->expiryDate;
    }

    public function setExpiryDate(?string $expiryDate)
    {
        $this->expiryDate = $expiryDate;
        return $this;
    }

    public function getStartDate(): ?string
    {
        return $this->startDate;
    }

    public function setStartDate(?string $startDate)
    {
        $this->startDate = $startDate;
        return $this;
    }

    public function isExpired(): bool
    {
        return $this->subscriptionState === 'SUBSCRIPTION_STATE_EXPIRED';
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        return [
            'isValid' => $this->isValid,
            'subscriptionState' => $this->subscriptionState,
            'productId' => $this->productId,
            'basePlanId' => $this->basePlanId,
            'offerId' => $this->offerId,
            'startTimeMillis' => $this->startTimeMillis,
            'expiryTimeMillis' => $this->expiryTimeMillis,
            'autoRenewing' => $this->autoRenewing,
            'regionCode' => $this->regionCode,
            'acknowledgementState' => $this->acknowledgementState,
            'kind' => $this->kind,
            'testPurchase' => $this->testPurchase,
            'latestOrderId' => $this->latestOrderId,
            'isCancelled' => $this->isCancelled,
            'canceledStateContext' => $this->canceledStateContext,
            'userCancellationTimeMillis' => $this->userCancellationTimeMillis,
            'pausedStateContext' => $this->pausedStateContext,
            'expiryDate' => $this->expiryDate,
            'startDate' => $this->startDate,
        ];
    }
}
