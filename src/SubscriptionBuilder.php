<?php

namespace Potelo\GuPayment;

use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class SubscriptionBuilder
{
    /**
     * The user model that is subscribing.
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $user;

    /**
     * The name of the subscription.
     *
     * @var string
     */
    protected $name;

    /**
     * The name of the plan being subscribed to.
     *
     * @var string
     */
    protected $plan;

    /**
     * The number of trial days to apply to the subscription.
     *
     * @var int|null
     */
    protected $trialDays;

    /**
     * Indicates that the trial should end immediately.
     *
     * @var bool
     */
    protected $skipTrial = false;

    /**
     * The coupon code being applied to the customer.
     *
     * @var string|null
     */
    protected $coupon;

    /**
     * @var bool
     */
    private $chargeOnSuccess = false;

    protected $lastError = null;

    /**
     * @var string
     */
    private $payableWith = 'all';

    /**
     * Create a new subscription builder instance.
     *
     * @param  mixed  $user
     * @param  string  $name
     * @param  string  $plan
     */
    public function __construct($user, $name, $plan, $additionalData)
    {
        $this->user = $user;
        $this->name = $name;
        $this->plan = $plan;
        $this->additionalData = $additionalData;
    }

    /**
     * Specify the ending date of the trial.
     *
     * @param  int  $trialDays
     * @return $this
     */
    public function trialDays($trialDays)
    {
        $this->trialDays = $trialDays;

        return $this;
    }

    /**
     * Force the trial to end immediately.
     *
     * @return $this
     */
    public function skipTrial()
    {
        $this->skipTrial = true;

        return $this;
    }

    /**
     * Add a new Iugu subscription to the user.
     *
     * @param  array  $options
     * @return \Potelo\GuPayment\Subscription
     */
    public function add(array $options = [])
    {
        return $this->create(null, $options);
    }

    /**
     * Create a new Iugu subscription.
     *
     * @param  string|null $token
     * @param  array $options
     * @return \Potelo\GuPayment\Subscription|boolean
     */
    public function create($token = null, array $options = [])
    {
        $iuguSubscriptionModelIdColumn = getenv('IUGU_SUBSCRIPTION_MODEL_ID_COLUMN') ?: config('services.iugu.subscription_model_id_column', 'iugu_id');
        $iuguSubscriptionModelPlanColumn = getenv('IUGU_SUBSCRIPTION_MODEL_PLAN_COLUMN') ?: config('services.iugu.subscription_model_plan_column', 'iugu_plan');

        $customer = $this->getIuguCustomer($token, $options);

        if (isset($customer->errors)) {
            $this->lastError = $customer->errors;
            return false;
        }

        $subscriptionIugu = $this->user->createIuguSubscription($this->buildPayload($customer->id));

        if (isset($subscriptionIugu->errors)) {
            $this->lastError = $subscriptionIugu->errors;
            return false;
        }

        if ($this->skipTrial) {
            $trialEndsAt = null;
        } else {
            $trialEndsAt = $this->trialDays ? Carbon::now()->addDays($this->trialDays) : null;
        }

        $subscription = new Subscription();
        $subscription->name = $this->name;
        $subscription->{$iuguSubscriptionModelIdColumn} =  $subscriptionIugu->id;
        $subscription->{$iuguSubscriptionModelPlanColumn} =  $this->plan;
        $subscription->trial_ends_at = $trialEndsAt;
        $subscription->ends_at = null;

        foreach ($this->additionalData as $k => $v) {
            // If column exists at database
            if (Schema::hasColumn($subscription->getTable(), $k)) {
                $subscription->{$k} = $v;
            }
        }

        return $this->user->subscriptions()->save($subscription);
    }

    /**
     * Get the Iugu customer instance for the current user and token.
     *
     * @param  string|null  $token
     * @param  array  $options
     * @return \Iugu_Customer
     */
    protected function getIuguCustomer($token = null, array $options = [])
    {
        if (! $this->user->getIuguUserId()) {
            $customer = $this->user->createAsIuguCustomer(
                $token,
                array_merge($options, array_filter(['coupon' => $this->coupon]))
            );
        } else {
            $customer = $this->user->asIuguCustomer();

            if ($token) {
                $this->user->updateCard($token);
            }
        }

        return $customer;
    }

    /**
     * Build the payload for subscription creation.
     *
     * @param $customerId
     * @return array
     */
    protected function buildPayload($customerId)
    {
        $customVariables = [];
        foreach ($this->additionalData as $k => $v) {
            $additionalData = [];
            $additionalData['name'] = $k;
            $additionalData['value'] = $v;

            $customVariables[] = $additionalData;
        }

        return array_filter([
            'plan_identifier' => $this->plan,
            'expires_at' => $this->getTrialEndForPayload(),
            'customer_id' => $customerId,
            'only_on_charge_success' => $this->chargeOnSuccess,
            'custom_variables' => $customVariables,
            'payable_with' => $this->payableWith
        ]);
    }

    /**
     * Get the trial ending date for the Iugu payload.
     *
     * @return int|null
     */
    protected function getTrialEndForPayload()
    {
        if ($this->skipTrial) {
            return Carbon::now();
        }

        if ($this->trialDays) {
            return Carbon::now()->addDays($this->trialDays);
        }
    }

    public function chargeOnSuccess()
    {
        $this->chargeOnSuccess = true;

        return $this;
    }

    /**
     * Choose the payable method
     *
     * @param string $method
     * @return $this
     */
    public function payWith($method = 'all')
    {
        $this->payableWith = $method;

        return $this;
    }

    /**
     * Get last error
     *
     * @return null
     */
    public function getLastError()
    {
        return $this->lastError;
    }
}
