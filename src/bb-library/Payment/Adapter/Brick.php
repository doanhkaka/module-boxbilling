<?php
if (!class_exists('Paymentwall_Config'))
    include('lib/paymentwall-php/lib/paymentwall.php');

class Payment_Adapter_Brick
{
    private $config = array();

    public function __construct($config)
    {
        $this->config = $config;
        $this->initPaymentwallConfig();
    }

    private function initPaymentwallConfig($type = '')
    {
        Paymentwall_Config::getInstance()->set(array(
            'api_type' => Paymentwall_Config::API_GOODS,
            'public_key' => $this->config['public_key'], // available in your Paymentwall merchant area
            'private_key' => $this->config['private_key'], // available in your Paymentwall merchant area
        ));
        if ($type == 'pingback') {
            Paymentwall_Base::setSecretKey($this->config['secret_key']);
        }
    }

    public static function getConfig()
    {
        return array(
            'supports_one_time_payments' => true,
            'supports_subscriptions' => true,
            'desciption' => 'Pay via Credit Card',
            'form' => array(
                'merchant' => array(
                    'text',
                    array(
                        'label' => 'Merchant name:',
                        'description' => '',
                        'validators' => array('noempty')
                    )
                ),
                'project_key' => array(
                    'text',
                    array(
                        'label' => 'Project key',
                        'description' => 'Can be found in General Settings of the Project inside of your Merchant Account at Paymentwall',
                        'validators' => array('noempty')
                    )
                ),
                'secret_key' => array(
                    'text',
                    array(
                        'label' => 'Secret key',
                        'description' => 'Can be found in General Settings of the Project inside of your Merchant Account at Paymentwall',
                        'validators' => array('noempty')
                    )
                ),
                'public_key' => array(
                    'text',
                    array(
                        'label' => 'Public key',
                        'description' => 'Can be found in General Settings of the Project inside of your Merchant Account at Paymentwall',
                        'validators' => array('noempty')
                    )
                ),
                'private_key' => array(
                    'text',
                    array(
                        'label' => 'Private key',
                        'description' => 'Can be found in General Settings of the Project inside of your Merchant Account at Paymentwall',
                        'validators' => array('noempty')
                    )
                )
            )
        );
    }

    /*
    * @params: $api_admin, $invoice_id, $subscription
    */
    public function getHtml($api_admin, $invoice_id, $subscription)
    {
        $invoice = $api_admin->invoice_get(array('id' => $invoice_id));

        $form = '<script src="https://api.paymentwall.com/brick/brick.1.4.js"> </script>
				 <div id="payment-form-container"></div>
				 <script>
				  var brick = new Brick({
				    public_key: "' . $this->config['public_key'] . '",
				    amount: ' . $invoice['total'] . ',
				    currency: "' . $invoice['currency'] . '",
				    container: "payment-form-container",
				    action: "' . $this->config['notify_url'] . '&pay=creditcard&amount=' . $invoice['total'] . '&currency=' . $invoice['currency'] . '&subscription=' . $subscription . '&lines=' . count($invoice['lines']) . '&period=' . $invoice['lines'][0]['period'] . '&quantity=' . $invoice['lines'][0]['quantity'] . '",
				    form: {
				      	merchant: "' . $this->config['merchant'] . '",
				      	product: "' . $invoice['lines'][0]['title'] . '",
				      	pay_button: "Pay",
				      	zip: true
				    }
				  });

				  brick.showPaymentForm(function(data) {
                    if (data.success != 1) {
                        $("#err-container").html(data.error.message);
                    } else {
                        $("#err-container").css("color", "#6B9B20");
                        $("#err-container").html("Order has been paid successfully !");
                    }
                        $("#err-container").show();
				    }, function(errors) {
				    }
				  );
			</script>
            <style>
                .brick-input-l, .brick-input-s {
                    height: 30px !important;
                    padding-left: 28px !important;
                }
                .brick-iw-email:before, .brick-iw-cvv:before, .brick-iw-exp:before, .brick-iw-cc:before {
                    margin: 4px 0 0 9px;
                }
                .brick-cvv-icon {
                    top:2px;
                }
            </style>
            ';
        $html = array(
            '_tpl' => $form
        );
        return $api_admin->system_string_render($html);
    }

    private function prepareCardInfo($postData, $getData)
    {
        $cardInfo = array(
            'email' => $postData['email'],
            'amount' => $getData['amount'],
            'currency' => $getData['currency'],
            'token' => $postData['brick_token'],
            'fingerprint' => $postData['brick_fingerprint'],
            'description' => 'Order ' . $getData['bb_invoice_id']
        );

        if ($getData['subscription'] && $getData['lines'] == 1) {
            $cardInfo['period'] = $getData['period'];
            $cardInfo['period_duration'] = $getData['duration'];
        }

        return $cardInfo;
    }

    /*
     * @params: $api_admin, $id, $data, $gateway_id
     */
    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        if (!empty($data['get']['pay']) && 'creditcard' == $data['get']['pay']) {
            $charge = new Paymentwall_Charge();
            $invoiceId = (int) $data['get']['bb_invoice_id'];

            $cardInfo = $this->prepareCardInfo($data['post'], $data['get']);
            $charge->create($cardInfo);
            $response = $charge->getPublicData();

            if ($charge->isSuccessful()) {
                if ($charge->isCaptured()) {
                    $api_admin->invoice_mark_as_paid(array('id' => $invoiceId));
                } elseif ($charge->isUnderReview()) {
                    $api_admin->invoice_update(array('id' => $invoiceId, 'status' => 'pending'));
                }
            } else {
                $api_admin->invoice_update(array('id' => $invoiceId, 'status' => 'unpaid'));
            }
            die($response);
        } else {
            echo $this->handlePingback($api_admin, $data);
            die;
        }
    }

    public function handlePingback($api_admin, $data)
    {
        $this->initPaymentwallConfig('pingback');

        $pingback = new Paymentwall_Pingback($data['get'], $_SERVER['REMOTE_ADDR']);
        $invoiceId = $pingback->getProductId();

        if ($pingback->validate()) {
            if ($pingback->isDeliverable()) {
                $api_admin->invoice_mark_as_paid(array('id' => $invoiceId));
            } elseif ($pingback->isCancelable()) {
                $api_admin->invoice_update(array('id' => $invoiceId, 'status' => 'cancel'));
            }
            return "OK";
        } else {
            $api_admin->invoice_update(array('id' => $invoiceId, 'status' => 'unpaid'));
            return $pingback->getErrorSummary();
        }
    }
}