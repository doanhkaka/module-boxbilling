<?php
if (!class_exists('Paymentwall_Config'))
    include('lib/paymentwall-php/lib/paymentwall.php');

class Payment_Adapter_Paymentwall
{
    private $config = array();

    public function __construct($config)
    {
        $this->config = $config;
        $this->initPaymentwallConfig();
    }

    private function initPaymentwallConfig()
    {
        Paymentwall_Config::getInstance()->set(array(
            'api_type' => Paymentwall_Config::API_GOODS,
            'public_key' => $this->config['project_key'], // available in your Paymentwall merchant area
            'private_key' => $this->config['secret_key'] // available in your Paymentwall merchant area
        ));
    }

    public static function getConfig()
    {
        return array(
            'supports_one_time_payments' => true,
            'supports_subscriptions' => true,
            'description' => 'Pay via Paymentwall',
            'form' => array(
                'merchant' => array('text', array(
                    'label' => 'Merchant name:',
                    'description' => '',
                    'validators' => array('noempty')
                )
                ),
                'project_key' => array('text', array(
                    'label' => 'Project key',
                    'description' => 'Can be found in General Settings of the Project inside of your Merchant Account at Paymentwall',
                    'validators' => array('noempty')
                )
                ),
                'secret_key' => array('text', array(
                    'label' => 'Secret key',
                    'description' => 'Can be found in General Settings of the Project inside of your Merchant Account at Paymentwall',
                    'validators' => array('noempty')
                )
                ),
                'widget' => array('text', array(
                    'label' => 'Widget code',
                    'description' => 'Widget code of Paymentwall',
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
        $client = $invoice['client'];

        $widget = new Paymentwall_Widget(
            $invoice['client']['id'], // id of the end-user who's making the payment
            $this->config['widget'], // widget code, e.g. p1; can be picked inside of your merchant account
            array( // product details for Non-Stored Product Widget Call. To let users select the product on Paymentwall's end, leave this array empty
                $this->getProduct($invoice, $subscription)
            ),
            // additional parameters
            array_merge(
                array(
                    'integration_module' => 'boxbilling',
                    'test_mode' => $this->config['test_mode']
                ),
                $this->getUserProfileData($client)
            )
        );

        return $widget->getHtmlCode();
    }

    /*
     * @params: $api_admin, $id, $data, $gateway_id
     */
    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        $pingback = new Paymentwall_Pingback($data['get'], $_SERVER['REMOTE_ADDR']);
        $invoiceId = $pingback->getProductId();

        if ($pingback->validate()) {
            if ($pingback->isDeliverable()) {
                $api_admin->invoice_mark_as_paid(array('id' => $invoiceId));
            } elseif ($pingback->isCancelable()) {
                $api_admin->invoice_update(array('id' => $invoiceId, 'status' => 'cancel'));
            }
            echo "OK";
        } else {
            echo $pingback->getErrorSummary();
        }
        die;
    }

    private function getUserProfileData($client)
    {
        return array(
            'customer[city]' => $client['city'],
            'customer[state]' => $client['state'],
            'customer[address]' => $client['address_1'],
            'customer[country]' => $client['country'],
            'customer[zip]' => $client['postcode'],
            'customer[firstname]' => $client['first_name'],
            'customer[lastname]' => $client['last_name'],
            'email' => $client['email']
        );
    }

    private function getProduct($invoice, $subscription)
    {
        $type_product = Paymentwall_Product::TYPE_SUBSCRIPTION;
        $duration = 0;
        $period_type = null;

        if (count($invoice['lines']) > 1 || !$subscription) {
            $type_product = Paymentwall_Product::TYPE_FIXED;
        }

        if ($type_product == Paymentwall_Product::TYPE_SUBSCRIPTION) {
            $cycleUnits = strtoupper(substr($invoice['lines'][0]['period'], 1, 1));

            switch ($cycleUnits) {
                case 'Y':
                    $period_type = Paymentwall_Product::PERIOD_TYPE_YEAR;
                    break;
                case 'M':
                    $period_type = Paymentwall_Product::PERIOD_TYPE_MONTH;
                    break;
                case 'W':
                    $period_type = Paymentwall_Product::PERIOD_TYPE_WEEK;
                    break;
                case 'D':
                    $period_type = Paymentwall_Product::PERIOD_TYPE_DAY;
                    break;
            }
            $duration = $invoice['lines'][0]['quantity'];
        }

        return new Paymentwall_Product(
            $invoice['id'], // id of the product in your system
            $invoice['total'], // price
            $invoice['currency'], // currency code
            $invoice['serie_nr'], // product name
            $type_product,
            $duration,
            $period_type,
            ($type_product == Paymentwall_Product::TYPE_SUBSCRIPTION) ? true : false
        );
    }
}