<?php

namespace Damms005\LaravelCashier\Services\PaymentHandlers;

use Carbon\Carbon;
use Damms005\LaravelCashier\Contracts\PaymentHandlerInterface;
use Damms005\LaravelCashier\Models\Payment;
use Illuminate\Http\Request;
use KingFlamez\Rave\Facades\Rave as FlutterwaveRave;

class Flutterwave extends BasePaymentHandler implements PaymentHandlerInterface
{
    public function __construct()
    {
    }

    public function renderAutoSubmittedPaymentForm(Payment $payment, $redirect_or_callback_url, $getFormForTesting = true)
    {
        $transaction_reference = $payment->transaction_reference;
        $this->sendUserToPaymentGateway($redirect_or_callback_url, $this->getPayment($transaction_reference));
    }

    public function getHumanReadableTransactionResponse(Payment $payment): string
    {
        return '';
    }

    public function convertResponseCodeToHumanReadable($responseCode): string
    {
        return "";
    }

    protected function sendUserToPaymentGateway(string $redirect_or_callback_url, Payment $payment)
    {
        $flutterwaveReference = FlutterwaveRave::generateReference();

        // Enter the details of the payment
        $data = [
            'payment_options' => 'card',
            'amount' => $payment->original_amount_displayed_to_user,
            'email' => $payment->user->email,
            'tx_ref' => $flutterwaveReference,
            'currency' => "USD",
            'redirect_url' => $redirect_or_callback_url,
            'customer' => [
                'email' => $payment->user->email,
                "phone_number" => null,
                "name" => $payment->user->fullname,
            ],

            "customizations" => [
                "title" => 'Application fee payment',
                "description" => "Application fee payment",
            ],
        ];

        $paymentInitialization = FlutterwaveRave::initializePayment($data);

        throw_if($paymentInitialization['status'] !== 'success', "Cannot initialize Flutterwave payment");

        $url = $paymentInitialization['data']['link'];

        $payment->processor_transaction_reference = $flutterwaveReference;
        $payment->save();

        header('Location: ' . $url);

        exit;
    }

    public function confirmResponseCanBeHandledAndUpdateDatabaseWithTransactionOutcome(Request $paymentGatewayServerResponse): ?Payment
    {
        if (! $paymentGatewayServerResponse->has('tx_ref')) {
            return null;
        }

        $flutterwaveReference = $paymentGatewayServerResponse->get('tx_ref');
        $payment = Payment::where('processor_transaction_reference', $flutterwaveReference)->firstOrFail();

        $status = $paymentGatewayServerResponse->get('status');

        if ($status != 'successful') {
            $payment->processor_returned_response_description = $status;
            $payment->save();

            return $payment;
        }

        $transactionId = $paymentGatewayServerResponse->get('transaction_id');
        $flutterwavePaymentDetails = FlutterwaveRave::verifyTransaction($transactionId);

        if (! $this->isValidTransaction($flutterwavePaymentDetails, $payment)) {
            $payment->processor_returned_response_description = "Invalid transaction";
            $payment->save();

            return $payment;
        }

        $payment = $this->giveValue($flutterwaveReference, $flutterwavePaymentDetails);

        return $payment;
    }

    public function isValidTransaction(array $flutterwavePaymentDetails, Payment $payment)
    {
        return
        // $flutterwavePaymentDetails->currency &&  $payment->currency;
        $flutterwavePaymentDetails['data']['amount'] == $payment->original_amount_displayed_to_user;
    }

    protected function giveValue($flutterwaveReference, array $flutterwavePaymentDetails): Payment
    {
        /**
         * @var Payment
         */
        $payment = Payment::where('processor_transaction_reference', $flutterwaveReference)
            ->firstOrFail();

        $payment->update([
            "is_success" => 1,
            "processor_returned_amount" => $flutterwavePaymentDetails['data']['amount'],
            "processor_returned_transaction_date" => new Carbon($flutterwavePaymentDetails['data']['created_at']),
            'processor_returned_response_description' => $flutterwavePaymentDetails['data']['processor_response'],
        ]);

        return $payment->fresh();
    }

    protected function performSuccess($flutterwaveReference)
    {
        return true;
    }
}
