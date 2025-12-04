<?php
namespace Opencart\Catalog\Controller\Extension\PaycellPaymentGateway\Payment;

class PaycellPaymentGateway extends \Opencart\System\Engine\Controller 
{

    public function index()
    {
        $this->load->language('extension/paycell_payment_gateway/payment/paycell_payment_gateway');
        if (isset($this->session->data['paycell_error'])) {
            $data['paycell_error'] = $this->session->data['paycell_error'];
            unset($this->session->data['paycell_error']);
        } else {
            $data['paycell_error'] = '';
        }
        
        $data['card_token_url'] = $this->getCardTokenUrl();
        if (!isset($this->session->data['csrf_token'])) {
            $this->session->data['csrf_token'] = bin2hex(random_bytes(32));
        }
        $data['csrf_token'] = $this->session->data['csrf_token'];

        
        return $this->load->view('extension/paycell_payment_gateway/payment/paycell_payment_gateway', $data);
    }

    private function getApplicationName() {
        return $this->config->get('payment_paycell_payment_gateway_application_name') ?: 'OCTOHAUS';
    }

    private function getApplicationPassword() {
        return $this->config->get('payment_paycell_payment_gateway_application_password') ?: '90RU173BN0NN29LS';
    }

    private function getSecureCode() {
        return $this->config->get('payment_paycell_payment_gateway_secure_code') ?: '90RU173BN0NN29LS';
    }

    private function getMerchantCode() {
        return $this->config->get('payment_paycell_payment_gateway_merchant_code') ?: '206554';
    }

    private function generateSecurityDataHash() {
        $securityData = strtoupper($this->getApplicationPassword() . $this->getApplicationName());
        return base64_encode(hash('sha256', $securityData, true));
    }
    
    /**
     * Generate hash data for requests
     */
    public function generateHashData($transactionId, $transactionDateTime) {
        $securityDataHash = $this->generateSecurityDataHash();
        $hashData = $this->getApplicationName() . $transactionId . $transactionDateTime . $this->getSecureCode() . $securityDataHash;
        return base64_encode(hash('sha256', strtoupper($hashData), true));
    }

    private function validateCsrfToken()
    {
        $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!isset($this->session->data['csrf_token']) || $this->session->data['csrf_token'] !== $csrfToken) {
            throw new \Exception('Invalid CSRF token');
        }
    }

    public function getHashData()
    {
        $this->response->addHeader('Content-Type: application/json');
        try {
            $this->validateCsrfToken();
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input || !isset($input['transaction_data'])) {
                throw new \Exception('Invalid request data');
            }
            
            $hashData = $this->generateHashData($input['transaction_data']['transactionId'], $input['transaction_data']['transactionDateTime']);
            
            $this->response->setOutput(json_encode([
                'success' => true,
                'hashData' => $hashData,
                'applicationName' => $this->getApplicationName(),
            ]), true);
            
        } catch (\Exception $exception) {
            $errorResponse = $this->buildErrorResponse(-1, $exception->getMessage());
            $this->response->setOutput(json_encode($errorResponse), true);
        }
    }

    public function checkBinInfo()
    {
        $this->response->addHeader('Content-Type: application/json');
        try {
            $this->validateCsrfToken();
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input || !isset($input['binNumber'])) {
                throw new \Exception('BIN number is required');
            }
            
            if (!isset($input['transactionId']) || !isset($input['transactionDateTime'])) {
                throw new \Exception('Transaction ID and DateTime are required');
            }
            
            $sessionData = [
                'transactionId' => $input['transactionId'],
                'transactionDateTime' => $input['transactionDateTime'],
                'clientIPAddress' => $this->getClientIPAddress(),
                'binNumber' => $input['binNumber']
            ];
            
            $response = $this->makeBinCheckRequest($sessionData);
            if ($response && isset($response['responseHeader']['responseCode']) && $response['responseHeader']['responseCode'] == '0') {
                if (isset($response['cardBinInformations']) && count($response['cardBinInformations']) > 0) {
                    $cardInfo = $response['cardBinInformations'][0];
                    $isCreditCard = $cardInfo['cardType'] === 'Credit Card';
                    
                    $this->response->setOutput(json_encode([
                        'success' => true,
                        'cardType' => $cardInfo['cardType'],
                        'cardBrand' => $cardInfo['cardBrand'],
                        'cardOrganization' => $cardInfo['cardOrganization'],
                        'bankName' => $cardInfo['bankName'],
                        'isCreditCard' => $isCreditCard,
                        'canInstallment' => $isCreditCard,
                    ]), true);
                } else {
                    throw new \Exception('No card information found for this BIN');
                }
            } else {
                throw new \Exception($response['responseHeader']['responseDescription'] ?? 'BIN check failed');
            }
            
        } catch (\Exception $exception) {
            $errorResponse = $this->buildErrorResponse(-1, $exception->getMessage());
            $this->response->setOutput(json_encode($errorResponse), true);
        }
    }

    public function generate3DSSession()
    {
        $this->response->addHeader('Content-Type: application/json');
        try {
            $this->validateCsrfToken();
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input || !isset($input['cardToken']) && !isset($input['cardId'])) {
                throw new \Exception('Invalid request data - card token required');
            }
            
            $this->load->model('checkout/order');
            $this->load->model('checkout/cart');
            $order_id = $this->session->data['order_id'];
            $order_info = $this->getOrder($order_id);
            $totals = [];
            $taxes = $this->cart->getTaxes();
            $total = 0;
            ($this->model_checkout_cart->getTotals)($totals, $taxes, $total);
            $total = $total * $order_info['currency_value'];
            $total = round(round((float)$total, 2) * 100, 0);
            
            $sessionData = [
                'cardToken' => $input['cardToken'],
                'orderId' => $order_id,
                'amount' => $total,
                'installmentCount' => $input['installment'] ?? 1,
                'transactionType' => 'AUTH',
                'target' => 'MERCHANT',
                'msisdn' => $order_info['telephone'] ?? null,
                'transactionId' => $input['transactionId'],
                'transactionDateTime' => $input['transactionDateTime'],
                'transactionNumber' => $input['transactionNumber'],
                'clientIPAddress' => $this->getClientIPAddress(),
                'merchantCode' => $this->getMerchantCode(),
                'applicationName' => $this->getApplicationName()
            ];
            
            if (isset($input['cardId'])) {
                $sessionData['cardId'] = $input['cardId'];
            }

            $transactionHash = $this->generateHashData($sessionData['transactionId'], $sessionData['transactionDateTime']);
            $orderHash = $this->generateHashData($sessionData['orderId'], $sessionData['amount']);

            $sessionData['transactionHash'] = $transactionHash;
            $sessionData['orderHash'] = $orderHash;
            
            
            $response = $this->make3DSSessionRequest($sessionData);
            
            if ($response && isset($response['responseHeader']['responseCode']) && $response['responseHeader']['responseCode'] == '0') {
                $threeDSessionId = $response['threeDSessionId'] ?? null;
                if ($this->config->get('payment_paycell_payment_gateway_sandbox_mode')) {
                    $threeDSecureUrl = 'https://omccstb.turkcell.com.tr/paymentmanagement/rest/threeDSecure';
                } else {
                    $threeDSecureUrl = 'https://epayment.turkcell.com.tr/paymentmanagement/rest/threeDSecure';
                }
                
                if ($threeDSessionId && $threeDSecureUrl) {
                    $sessionData['threeDSessionId'] = $threeDSessionId;
                    $serializedTransactionData = json_encode($sessionData);
                    $compressedTransactionData = gzcompress($serializedTransactionData, 9);
                    $encodedTransactionData = $this->base64url_encode($compressedTransactionData);

                    $callbackUrl = $this->url->link('extension/paycell_payment_gateway/payment/paycell_payment_gateway.threeDSCallback', '&transaction=' . $encodedTransactionData, true);
                    
                    $this->response->setOutput(json_encode([
                        'success' => true,
                        'sessionData' => [
                            'threeDSessionId' => $threeDSessionId,
                            'threeDSecureUrl' => $threeDSecureUrl,
                            'callbackUrl' => $callbackUrl,
                            'redirectUrl' => $this->url->link('extension/paycell_payment_gateway/payment/paycell_payment_gateway.threeDSRedirect', 'session_id=' . $threeDSessionId . '&secure_url=' . urlencode($threeDSecureUrl) . '&callback_url=' . urlencode($callbackUrl), true)
                        ]
                    ]), true);
                } else {
                    throw new \Exception('Invalid 3DS session response - missing session ID or secure URL');
                }
            } else {
                throw new \Exception($response['errorMessage'] ?? 'Failed to generate 3DS session');
            }
            
        } catch (\Exception $exception) {
            $errorResponse = $this->buildErrorResponse(-1, $exception->getMessage());
            $this->response->setOutput(json_encode($errorResponse), true);
        }
    }

    private function base64url_decode($data) {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    private function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }


    public function threeDSRedirect()
    {
        $sessionId = $this->request->get['session_id'] ?? null;
        $threeDSecureUrl = $this->request->get['secure_url'] ?? null;
        $callbackUrl = $this->request->get['callback_url'] ?? null;
        
        if (!$sessionId || !$threeDSecureUrl || !$callbackUrl) {
            $this->response->redirect($this->url->link('checkout/checkout'));
            return;
        }
        
        $threeDSecureUrl = urldecode($threeDSecureUrl);
        $callbackUrl = urldecode($callbackUrl);
        
        $html = $this->generate3DSRedirectPage($sessionId, $threeDSecureUrl, $callbackUrl);
        
        $this->response->setOutput($html);
    }

    public function threeDSCallback()
    {
        $this->load->model('checkout/order');
        $order_id = $this->session->data['order_id'];
        $transaction = $this->request->get['transaction'] ?? null;
        $transaction = $this->base64url_decode($transaction);
        $transaction = gzuncompress($transaction);
        $transaction = json_decode($transaction, true);
        $transactionHash = $this->generateHashData($transaction['transactionId'], $transaction['transactionDateTime']);
        $orderHash = $this->generateHashData($transaction['orderId'], $transaction['amount']);

        if ($transactionHash !== $transaction['transactionHash'] || $orderHash !== $transaction['orderHash']) {
            $this->session->data['paycell_error'] = 'Invalid transaction hash';
            $this->response->redirect($this->url->link('checkout/checkout'));
            return;
        }

        $result = $this->make3DsSessionResultRequest($transaction);
        if (($result['mdStatus'] == 1 || $result['mdStatus'] == 'Y') && $result['threeDOperationResult']['threeDResult'] == 0) {
            $provisionResult = $this->makeProvisionAllRequest($transaction);
            if (($provisionResult['responseHeader']['responseCode'] ?? false)  == 0 && $provisionResult['approvalCode'] ?? false && $provisionResult['orderId'] ?? false ) {
                $this->complete_order($order_id);
                $successLink = $this->url->link('checkout/success');
                $this->response->redirect($successLink);
            } else if (($provisionResult['responseHeader']['responseCode'] ?? false) == 2012) {
                $inquireAllResult = $this->makeInquireAllRequest([
                    'transactionId' => $transaction['transactionId'],
                    'transactionDateTime' => $transaction['transactionDateTime'],
                    'clientIPAddress' => $transaction['clientIPAddress'],
                    'msisdn' => $transaction['msisdn'],
                    'referenceNumber' => $transaction['transactionNumber'],
                    'paymentMethodType' => 'CREDIT_CARD',
                ]);
                if (($inquireAllResult['responseHeader']['responseCode'] ?? false) == 0 && $inquireAllResult['orderId'] ?? false) {
                    $this->complete_order($order_id);
                    $successLink = $this->url->link('checkout/success');
                    $this->response->redirect($successLink);
                    return;
                } else {
                    $this->response->redirect($this->url->link('checkout/checkout'));
                    return;
                }
            } else {
                $this->session->data['paycell_error'] = $provisionResult['responseHeader']['responseDescription'];
                $this->response->redirect($this->url->link('checkout/checkout'));
                return;
            }
        } else {
            $this->session->data['paycell_error'] = $result['mdErrorMessage'];
            $this->response->redirect($this->url->link('checkout/checkout'));
            return;
        }
    
    }

    private function generate3DSRedirectPage($sessionId, $threeDSecureUrl, $callbackUrl)
    {
        return '<html>
            <head>
                <title>Paycell Ödeme Adımı</title>
                <script src="https://cdnjs.cloudflare.com/ajax/libs/bodymovin/5.13.0/lottie.min.js" integrity="sha512-uOtp2vx2X/5+tLBEf5UoQyqwAkFZJBM5XwGa7BfXDnWR+wdpRvlSVzaIVcRe3tGNsStu6UMDCeXKEnr4IBT8gA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
                <style>
                    body {
                        font-family: Arial, sans-serif;
                        background: #f5f5f5;
                        margin: 0;
                        padding: 20px;
                        text-align: center;
                    }
                    .container {
                        background: white;
                        border: 1px solid #ddd;
                        padding: 30px;
                        max-width: 600px;
                        margin: 50px auto;
                    }
                    .saatAnim {
                        width: 250px;
                        height: 250px;
                        margin: 40px auto;
                    }
                    h1 {
                        color: #333;
                        margin-bottom: 20px;
                    }
                    .loading {
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        margin: 10px 0;
                    }
                    .spinner {
                        width: 20px;
                        height: 20px;
                        border: 2px solid #ddd;
                        border-top: 2px solid #333;
                        border-radius: 50%;
                        animation: spin 1s linear infinite;
                        margin-right: 10px;
                    }
                    @keyframes spin {
                        0% { transform: rotate(0deg); }
                        100% { transform: rotate(360deg); }
                    }
                    .info {
                        background: #f9f9f9;
                        border: 1px solid #ddd;
                        padding: 15px;
                        margin: 20px 0;
                        font-size: 12px;
                    }
                    .submit-button {
                        background: #007cba;
                        color: white;
                        border: none;
                        padding: 10px 20px;
                        cursor: pointer;
                        display: none;
                        margin-top: 15px;
                    }
                    .manual-instructions {
                        display: none;
                        margin-top: 15px;
                        padding: 10px;
                        background: #fff3cd;
                        border: 1px solid #ffeaa7;
                        color: #856404;
                        font-size: 14px;
                    }
                </style>
            </head>
            <script>
                var isSubmitted = false;
                
                document.addEventListener("DOMContentLoaded", function() {
                    
                document.getElementById("threeDSecureForm").addEventListener("submit", function(e) {
                    isSubmitted = true;
                });
                    
                document.forms["threeDSecureForm"].submit();
                isSubmitted = true;
                    
                setTimeout(function() {
                    console.log("isSubmitted", isSubmitted);
                    if (!isSubmitted) {
                        document.forms["threeDSecureForm"].submit();
                    }
                }, 10000);
                lottie.loadAnimation({
                    container: document.getElementById("saatAnim"),
                    renderer: "svg",
                    loop: true,
                    autoplay: true,
                    animationData: {"v":"5.9.0","fr":29.9700012207031,"ip":0,"op":180.00000733155,"w":208,"h":178,"nm":"Comp 1","ddd":0,"assets":[],"layers":[{"ddd":0,"ind":1,"ty":4,"nm":"orta/saat Outlines","sr":1,"ks":{"o":{"a":0,"k":100,"ix":11},"r":{"a":0,"k":0,"ix":10},"p":{"a":0,"k":[104,89,0],"ix":2,"l":2},"a":{"a":0,"k":[9.5,9.5,0],"ix":1,"l":2},"s":{"a":0,"k":[100,100,100],"ix":6,"l":2}},"ao":0,"shapes":[{"ty":"gr","it":[{"ind":0,"ty":"sh","ix":1,"ks":{"a":0,"k":{"i":[[-2.343,2.343],[2.343,2.343],[2.343,-2.344],[-2.343,-2.343]],"o":[[2.343,-2.343],[-2.343,-2.344],[-2.343,2.343],[2.343,2.343]],"v":[[4.243,4.243],[4.243,-4.242],[-4.243,-4.242],[-4.243,4.243]],"c":true},"ix":2},"nm":"Path 1","mn":"ADBE Vector Shape - Group","hd":false},{"ty":"fl","c":{"a":0,"k":[0.011764706817,0.305882352941,0.635294117647,1],"ix":4},"o":{"a":0,"k":100,"ix":5},"r":1,"bm":0,"nm":"Fill 1","mn":"ADBE Vector Graphic - Fill","hd":false},{"ty":"tr","p":{"a":0,"k":[9.031,9.071],"ix":2},"a":{"a":0,"k":[0,0],"ix":1},"s":{"a":0,"k":[100,100],"ix":3},"r":{"a":0,"k":0,"ix":6},"o":{"a":0,"k":100,"ix":7},"sk":{"a":0,"k":0,"ix":4},"sa":{"a":0,"k":0,"ix":5},"nm":"Transform"}],"nm":"Group 1","np":2,"cix":2,"bm":0,"ix":1,"mn":"ADBE Vector Group","hd":false},{"ty":"gr","it":[{"ind":0,"ty":"sh","ix":1,"ks":{"a":0,"k":{"i":[[-3.124,3.139],[3.124,3.138],[3.124,-3.139],[-3.125,-3.138]],"o":[[3.124,-3.138],[-3.124,-3.139],[-3.125,3.138],[3.124,3.139]],"v":[[5.657,5.682],[5.657,-5.682],[-5.656,-5.682],[-5.656,5.682]],"c":true},"ix":2},"nm":"Path 1","mn":"ADBE Vector Shape - Group","hd":false},{"ty":"fl","c":{"a":0,"k":[0.964705942191,0.968627510819,0.972549079446,1],"ix":4},"o":{"a":0,"k":100,"ix":5},"r":1,"bm":0,"nm":"Fill 1","mn":"ADBE Vector Graphic - Fill","hd":false},{"ty":"tr","p":{"a":0,"k":[9.031,9.072],"ix":2},"a":{"a":0,"k":[0,0],"ix":1},"s":{"a":0,"k":[100,100],"ix":3},"r":{"a":0,"k":0,"ix":6},"o":{"a":0,"k":100,"ix":7},"sk":{"a":0,"k":0,"ix":4},"sa":{"a":0,"k":0,"ix":5},"nm":"Transform"}],"nm":"Group 2","np":2,"cix":2,"bm":0,"ix":2,"mn":"ADBE Vector Group","hd":false}],"ip":0,"op":900.000036657751,"st":0,"bm":0},{"ddd":0,"ind":3,"ty":4,"nm":"yelkovan/saat Outlines","sr":1,"ks":{"o":{"a":0,"k":100,"ix":11},"r":{"a":1,"k":[{"i":{"x":[0.833],"y":[0.833]},"o":{"x":[0.167],"y":[0.167]},"t":0,"s":[0]},{"t":180.00000733155,"s":[360]}],"ix":10},"p":{"a":0,"k":[103.391,88.672,0],"ix":2,"l":2},"a":{"a":0,"k":[0.359,2.5,0],"ix":1,"l":2},"s":{"a":0,"k":[100,100,100],"ix":6,"l":2}},"ao":0,"shapes":[{"ty":"gr","it":[{"ind":0,"ty":"sh","ix":1,"ks":{"a":0,"k":{"i":[[1.139,0],[0,0],[0,-1.144],[-1.138,0],[0,0],[0,1.143]],"o":[[0,0],[-1.138,0],[0,1.143],[0,0],[1.139,0],[0,-1.144]],"v":[[17.614,-2.07],[-17.615,-2.07],[-19.676,0.001],[-17.615,2.07],[17.614,2.07],[19.676,0.001]],"c":true},"ix":2},"nm":"Path 1","mn":"ADBE Vector Shape - Group","hd":false},{"ty":"fl","c":{"a":0,"k":[0.003921568627,0.121568634931,0.254901960784,1],"ix":4},"o":{"a":0,"k":100,"ix":5},"r":1,"bm":0,"nm":"Fill 1","mn":"ADBE Vector Graphic - Fill","hd":false},{"ty":"tr","p":{"a":0,"k":[19.926,2.32],"ix":2},"a":{"a":0,"k":[0,0],"ix":1},"s":{"a":0,"k":[100,100],"ix":3},"r":{"a":0,"k":0,"ix":6},"o":{"a":0,"k":100,"ix":7},"sk":{"a":0,"k":0,"ix":4},"sa":{"a":0,"k":0,"ix":5},"nm":"Transform"}],"nm":"Group 1","np":2,"cix":2,"bm":0,"ix":1,"mn":"ADBE Vector Group","hd":false}],"ip":0,"op":900.000036657751,"st":0,"bm":0},{"ddd":0,"ind":5,"ty":4,"nm":"akrep/saat Outlines","sr":1,"ks":{"o":{"a":0,"k":100,"ix":11},"r":{"a":0,"k":0,"ix":10},"p":{"a":0,"k":[97,98.75,0],"ix":2,"l":2},"a":{"a":0,"k":[8.5,12,0],"ix":1,"l":2},"s":{"a":0,"k":[100,100,100],"ix":6,"l":2}},"ao":0,"shapes":[{"ty":"gr","it":[{"ind":0,"ty":"sh","ix":1,"ks":{"a":0,"k":{"i":[[-0.792,1.377],[0,0],[1.371,0.795],[0.792,-1.377],[0,0],[-1.371,-0.795]],"o":[[0,0],[0.792,-1.377],[-1.371,-0.795],[0,0],[-0.791,1.378],[1.37,0.795]],"v":[[-2.182,9.555],[7.146,-6.676],[6.097,-10.609],[2.181,-9.555],[-7.147,6.674],[-6.097,10.609]],"c":true},"ix":2},"nm":"Path 1","mn":"ADBE Vector Shape - Group","hd":false},{"ty":"fl","c":{"a":0,"k":[0.003921568627,0.121568634931,0.254901960784,1],"ix":4},"o":{"a":0,"k":100,"ix":5},"r":1,"bm":0,"nm":"Fill 1","mn":"ADBE Vector Graphic - Fill","hd":false},{"ty":"tr","p":{"a":0,"k":[8.188,11.653],"ix":2},"a":{"a":0,"k":[0,0],"ix":1},"s":{"a":0,"k":[100,100],"ix":3},"r":{"a":0,"k":0,"ix":6},"o":{"a":0,"k":100,"ix":7},"sk":{"a":0,"k":0,"ix":4},"sa":{"a":0,"k":0,"ix":5},"nm":"Transform"}],"nm":"Group 1","np":2,"cix":2,"bm":0,"ix":1,"mn":"ADBE Vector Group","hd":false}],"ip":0,"op":900.000036657751,"st":0,"bm":0},{"ddd":0,"ind":7,"ty":4,"nm":"star1/saat Outlines","sr":1,"ks":{"o":{"a":0,"k":100,"ix":11},"r":{"a":0,"k":0,"ix":10},"p":{"a":0,"k":[16,133,0],"ix":2,"l":2},"a":{"a":0,"k":[4,5,0],"ix":1,"l":2},"s":{"a":1,"k":[{"i":{"x":[0.667,0.667,0.667],"y":[1,1,1]},"o":{"x":[0.167,0.167,0.167],"y":[0.167,0.167,16.667]},"t":0,"s":[100,100,100]},{"i":{"x":[0.667,0.667,0.667],"y":[1,1,1]},"o":{"x":[0.167,0.167,0.167],"y":[0.167,0.167,-16.667]},"t":30,"s":[200,200,100]},{"i":{"x":[0.667,0.667,0.667],"y":[1,1,1]},"o":{"x":[0.167,0.167,0.167],"y":[0.167,0.167,0]},"t":60,"s":[100,100,100]},{"i":{"x":[0.667,0.667,0.667],"y":[1,1,1]},"o":{"x":[0.167,0.167,0.167],"y":[0.167,0.167,0]},"t":90,"s":[200,200,100]},{"i":{"x":[0.667,0.667,0.667],"y":[1,1,1]},"o":{"x":[0.167,0.167,0.167],"y":[0.167,0.167,0]},"t":120,"s":[100,100,100]},{"i":{"x":[0.667,0.667,0.667],"y":[1,1,1]},"o":{"x":[0.167,0.167,0.167],"y":[0.167,0.167,0]},"t":150,"s":[200,200,100]},{"t":180.00000733155,"s":[100,100,100]}],"ix":6,"l":2}},"ao":0,"shapes":[{"ty":"gr","it":[{"ind":0,"ty":"sh","ix":1,"ks":{"a":0,"k":{"i":[[0.29,4.132],[0,0],[0,0],[0,-4.775],[0,0]],"o":[[0,0],[-0.29,4.132],[0,0],[0,-4.775],[0,0]],"v":[[0.025,-4.518],[-0.024,-4.518],[-3.373,-0.323],[0,4.518],[3.373,-0.323]],"c":true},"ix":2},"nm":"Path 1","mn":"ADBE Vector Shape - Group","hd":false},{"ty":"fl","c":{"a":0,"k":[1,0.792156922583,0.156862745098,1],"ix":4},"o":{"a":0,"k":100,"ix":5},"r":1,"bm":0,"nm":"Fill 1","mn":"ADBE Vector Graphic - Fill","hd":false},{"ty":"tr","p":{"a":0,"k":[3.623,4.768],"ix":2},"a":{"a":0,"k":[0,0],"ix":1},"s":{"a":0,"k":[100,100],"ix":3},"r":{"a":0,"k":0,"ix":6},"o":{"a":0,"k":100,"ix":7},"sk":{"a":0,"k":0,"ix":4},"sa":{"a":0,"k":0,"ix":5},"nm":"Transform"}],"nm":"Group 1","np":2,"cix":2,"bm":0,"ix":1,"mn":"ADBE Vector Group","hd":false}],"ip":0,"op":900.000036657751,"st":0,"bm":0},{"ddd":0,"ind":9,"ty":4,"nm":"star2/saat Outlines","sr":1,"ks":{"o":{"a":0,"k":100,"ix":11},"r":{"a":0,"k":0,"ix":10},"p":{"a":0,"k":[187.75,37.5,0],"ix":2,"l":2},"a":{"a":0,"k":[6.5,8.5,0],"ix":1,"l":2},"s":{"a":1,"k":[{"i":{"x":[0.667,0.667,0.667],"y":[1,1,1]},"o":{"x":[0.167,0.167,0.167],"y":[0.167,0.167,4.517]},"t":0,"s":[100,100,100]},{"i":{"x":[0.667,0.667,0.667],"y":[1,1,1]},"o":{"x":[0.167,0.167,0.167],"y":[0.167,0.167,-4.517]},"t":30,"s":[200,200,100]},{"i":{"x":[0.667,0.667,0.667],"y":[1,1,1]},"o":{"x":[0.167,0.167,0.167],"y":[0.167,0.167,0]},"t":60,"s":[100,100,100]},{"i":{"x":[0.667,0.667,0.667],"y":[1,1,1]},"o":{"x":[0.167,0.167,0.167],"y":[0.167,0.167,0]},"t":90,"s":[200,200,100]},{"i":{"x":[0.667,0.667,0.667],"y":[1,1,1]},"o":{"x":[0.167,0.167,0.167],"y":[0.167,0.167,0]},"t":120,"s":[100,100,100]},{"i":{"x":[0.667,0.667,0.667],"y":[1,1,1]},"o":{"x":[0.167,0.167,0.167],"y":[0.167,0.167,0]},"t":150,"s":[200,200,100]},{"t":180.00000733155,"s":[100,100,100]}],"ix":6,"l":2}},"ao":0,"shapes":[{"ty":"gr","it":[{"ind":0,"ty":"sh","ix":1,"ks":{"a":0,"k":{"i":[[0.5,7.23],[0,0],[0,0],[0,-8.354],[0,0]],"o":[[0,0],[-0.5,7.23],[0,0],[0,-8.354],[0,0]],"v":[[0.044,-7.906],[-0.041,-7.906],[-5.809,-0.564],[0,7.906],[5.809,-0.564]],"c":true},"ix":2},"nm":"Path 1","mn":"ADBE Vector Shape - Group","hd":false},{"ty":"fl","c":{"a":0,"k":[1,0.792156922583,0.156862745098,1],"ix":4},"o":{"a":0,"k":100,"ix":5},"r":1,"bm":0,"nm":"Fill 1","mn":"ADBE Vector Graphic - Fill","hd":false},{"ty":"tr","p":{"a":0,"k":[6.059,8.156],"ix":2},"a":{"a":0,"k":[0,0],"ix":1},"s":{"a":0,"k":[100,100],"ix":3},"r":{"a":0,"k":0,"ix":6},"o":{"a":0,"k":100,"ix":7},"sk":{"a":0,"k":0,"ix":4},"sa":{"a":0,"k":0,"ix":5},"nm":"Transform"}],"nm":"Group 1","np":2,"cix":2,"bm":0,"ix":1,"mn":"ADBE Vector Group","hd":false}],"ip":0,"op":900.000036657751,"st":0,"bm":0},{"ddd":0,"ind":11,"ty":4,"nm":"saat/saat Outlines","sr":1,"ks":{"o":{"a":0,"k":100,"ix":11},"r":{"a":0,"k":0,"ix":10},"p":{"a":0,"k":[104,89,0],"ix":2,"l":2},"a":{"a":0,"k":[58.5,59,0],"ix":1,"l":2},"s":{"a":0,"k":[100,100,100],"ix":6,"l":2}},"ao":0,"shapes":[{"ty":"gr","it":[{"ind":0,"ty":"sh","ix":1,"ks":{"a":0,"k":{"i":[[-27.925,0],[0,28.053],[27.926,0],[0,-28.053]],"o":[[27.926,0],[0,-28.053],[-27.925,0],[0,28.053]],"v":[[0,50.794],[50.563,0],[0,-50.794],[-50.563,0]],"c":true},"ix":2},"nm":"Path 1","mn":"ADBE Vector Shape - Group","hd":false},{"ty":"fl","c":{"a":0,"k":[0.964705942191,0.968627510819,0.972549079446,1],"ix":4},"o":{"a":0,"k":100,"ix":5},"r":1,"bm":0,"nm":"Fill 1","mn":"ADBE Vector Graphic - Fill","hd":false},{"ty":"tr","p":{"a":0,"k":[58.93,59.199],"ix":2},"a":{"a":0,"k":[0,0],"ix":1},"s":{"a":0,"k":[100,100],"ix":3},"r":{"a":0,"k":0,"ix":6},"o":{"a":0,"k":100,"ix":7},"sk":{"a":0,"k":0,"ix":4},"sa":{"a":0,"k":0,"ix":5},"nm":"Transform"}],"nm":"Group 1","np":2,"cix":2,"bm":0,"ix":1,"mn":"ADBE Vector Group","hd":false},{"ty":"gr","it":[{"ind":0,"ty":"sh","ix":1,"ks":{"a":0,"k":{"i":[[0,32.178],[32.033,0],[0,-32.179],[-32.032,0]],"o":[[0,-32.179],[-32.032,0],[0,32.178],[32.033,0]],"v":[[58,0.001],[0,-58.264],[-58,0.001],[0,58.264]],"c":true},"ix":2},"nm":"Path 1","mn":"ADBE Vector Shape - Group","hd":false},{"ty":"fl","c":{"a":0,"k":[0.011764706817,0.305882352941,0.635294117647,1],"ix":4},"o":{"a":0,"k":100,"ix":5},"r":1,"bm":0,"nm":"Fill 1","mn":"ADBE Vector Graphic - Fill","hd":false},{"ty":"tr","p":{"a":0,"k":[58.25,58.514],"ix":2},"a":{"a":0,"k":[0,0],"ix":1},"s":{"a":0,"k":[100,100],"ix":3},"r":{"a":0,"k":0,"ix":6},"o":{"a":0,"k":100,"ix":7},"sk":{"a":0,"k":0,"ix":4},"sa":{"a":0,"k":0,"ix":5},"nm":"Transform"}],"nm":"Group 2","np":2,"cix":2,"bm":0,"ix":2,"mn":"ADBE Vector Group","hd":false}],"ip":0,"op":900.000036657751,"st":0,"bm":0},{"ddd":0,"ind":13,"ty":4,"nm":"Layer 1/saat Outlines","sr":1,"ks":{"o":{"a":0,"k":100,"ix":11},"r":{"a":0,"k":0,"ix":10},"p":{"a":0,"k":[103.5,92.5,0],"ix":2,"l":2},"a":{"a":0,"k":[103,98.5,0],"ix":1,"l":2},"s":{"a":0,"k":[100,100,100],"ix":6,"l":2}},"ao":0,"shapes":[{"ty":"gr","it":[{"ind":0,"ty":"sh","ix":1,"ks":{"a":0,"k":{"i":[[0.545,-2.042],[-2.033,-0.547],[-0.545,2.042],[2.033,0.547]],"o":[[-0.544,2.042],[2.033,0.547],[0.545,-2.043],[-2.033,-0.547]],"v":[[-3.682,-0.991],[-0.987,3.698],[3.681,0.991],[0.986,-3.698]],"c":true},"ix":2},"nm":"Path 1","mn":"ADBE Vector Shape - Group","hd":false},{"ty":"fl","c":{"a":0,"k":[0.725490196078,0.850980451995,0.996078491211,1],"ix":4},"o":{"a":0,"k":100,"ix":5},"r":1,"bm":0,"nm":"Fill 1","mn":"ADBE Vector Graphic - Fill","hd":false},{"ty":"tr","p":{"a":0,"k":[105.733,20.888],"ix":2},"a":{"a":0,"k":[0,0],"ix":1},"s":{"a":0,"k":[100,100],"ix":3},"r":{"a":0,"k":0,"ix":6},"o":{"a":0,"k":100,"ix":7},"sk":{"a":0,"k":0,"ix":4},"sa":{"a":0,"k":0,"ix":5},"nm":"Transform"}],"nm":"Group 1","np":2,"cix":2,"bm":0,"ix":1,"mn":"ADBE Vector Group","hd":false},{"ty":"gr","it":[{"ind":0,"ty":"sh","ix":1,"ks":{"a":0,"k":{"i":[[-1.035,0],[0,0.832],[1.035,0],[0,-0.832]],"o":[[1.035,0],[0,-0.832],[-1.035,0],[0,0.832]],"v":[[0,1.506],[1.874,0],[0,-1.506],[-1.874,0]],"c":true},"ix":2},"nm":"Path 1","mn":"ADBE Vector Shape - Group","hd":false},{"ty":"fl","c":{"a":0,"k":[0.470588265213,0.713725490196,0.992156922583,1],"ix":4},"o":{"a":0,"k":100,"ix":5},"r":1,"bm":0,"nm":"Fill 1","mn":"ADBE Vector Graphic - Fill","hd":false},{"ty":"tr","p":{"a":0,"k":[26.917,102.349],"ix":2},"a":{"a":0,"k":[0,0],"ix":1},"s":{"a":0,"k":[100,100],"ix":3},"r":{"a":0,"k":0,"ix":6},"o":{"a":0,"k":100,"ix":7},"sk":{"a":0,"k":0,"ix":4},"sa":{"a":0,"k":0,"ix":5},"nm":"Transform"}],"nm":"Group 2","np":2,"cix":2,"bm":0,"ix":2,"mn":"ADBE Vector Group","hd":false},{"ty":"gr","it":[{"ind":0,"ty":"sh","ix":1,"ks":{"a":0,"k":{"i":[[-1.035,0],[0,1.039],[1.035,0],[0,-1.04]],"o":[[1.035,0],[0,-1.04],[-1.035,0],[0,1.039]],"v":[[0,1.883],[1.874,0.001],[0,-1.883],[-1.874,0.001]],"c":true},"ix":2},"nm":"Path 1","mn":"ADBE Vector Shape - Group","hd":false},{"ty":"fl","c":{"a":0,"k":[1,0.792156922583,0.156862745098,1],"ix":4},"o":{"a":0,"k":100,"ix":5},"r":1,"bm":0,"nm":"Fill 1","mn":"ADBE Vector Graphic - Fill","hd":false},{"ty":"tr","p":{"a":0,"k":[58.398,34.205],"ix":2},"a":{"a":0,"k":[0,0],"ix":1},"s":{"a":0,"k":[100,100],"ix":3},"r":{"a":0,"k":0,"ix":6},"o":{"a":0,"k":100,"ix":7},"sk":{"a":0,"k":0,"ix":4},"sa":{"a":0,"k":0,"ix":5},"nm":"Transform"}],"nm":"Group 3","np":2,"cix":2,"bm":0,"ix":3,"mn":"ADBE Vector Group","hd":false},{"ty":"gr","it":[{"ind":0,"ty":"sh","ix":1,"ks":{"a":0,"k":{"i":[[-0.724,0],[0,0.727],[0.725,0],[0,-0.728]],"o":[[0.725,0],[0,-0.728],[-0.724,0],[0,0.727]],"v":[[0,1.318],[1.312,0.001],[0,-1.318],[-1.311,0.001]],"c":true},"ix":2},"nm":"Path 1","mn":"ADBE Vector Shape - Group","hd":false},{"ty":"fl","c":{"a":0,"k":[1,0.792156922583,0.156862745098,1],"ix":4},"o":{"a":0,"k":100,"ix":5},"r":1,"bm":0,"nm":"Fill 1","mn":"ADBE Vector Graphic - Fill","hd":false},{"ty":"tr","p":{"a":0,"k":[158.651,167.292],"ix":2},"a":{"a":0,"k":[0,0],"ix":1},"s":{"a":0,"k":[100,100],"ix":3},"r":{"a":0,"k":0,"ix":6},"o":{"a":0,"k":100,"ix":7},"sk":{"a":0,"k":0,"ix":4},"sa":{"a":0,"k":0,"ix":5},"nm":"Transform"}],"nm":"Group 4","np":2,"cix":2,"bm":0,"ix":4,"mn":"ADBE Vector Group","hd":false},{"ty":"gr","it":[{"ind":0,"ty":"sh","ix":1,"ks":{"a":0,"k":{"i":[[-2.277,0],[0,2.08],[2.277,0],[0,-2.079]],"o":[[2.277,0],[0,-2.079],[-2.277,0],[0,2.08]],"v":[[0.001,3.764],[4.122,-0.001],[0.001,-3.765],[-4.122,-0.001]],"c":true},"ix":2},"nm":"Path 1","mn":"ADBE Vector Shape - Group","hd":false},{"ty":"fl","c":{"a":0,"k":[0.725490196078,0.850980451995,0.996078491211,1],"ix":4},"o":{"a":0,"k":100,"ix":5},"r":1,"bm":0,"nm":"Fill 1","mn":"ADBE Vector Graphic - Fill","hd":false},{"ty":"tr","p":{"a":0,"k":[200.002,114.396],"ix":2},"a":{"a":0,"k":[0,0],"ix":1},"s":{"a":0,"k":[100,100],"ix":3},"r":{"a":0,"k":0,"ix":6},"o":{"a":0,"k":100,"ix":7},"sk":{"a":0,"k":0,"ix":4},"sa":{"a":0,"k":0,"ix":5},"nm":"Transform"}],"nm":"Group 5","np":2,"cix":2,"bm":0,"ix":5,"mn":"ADBE Vector Group","hd":false},{"ty":"gr","it":[{"ind":0,"ty":"sh","ix":1,"ks":{"a":0,"k":{"i":[[-2.173,0],[0,2.079],[2.173,0],[0,-2.079]],"o":[[2.173,0],[0,-2.079],[-2.173,0],[0,2.079]],"v":[[0,3.765],[3.935,0],[0,-3.765],[-3.935,0]],"c":true},"ix":2},"nm":"Path 1","mn":"ADBE Vector Shape - Group","hd":false},{"ty":"fl","c":{"a":0,"k":[0.011764706817,0.305882352941,0.635294117647,1],"ix":4},"o":{"a":0,"k":100,"ix":5},"r":1,"bm":0,"nm":"Fill 1","mn":"ADBE Vector Graphic - Fill","hd":false},{"ty":"tr","p":{"a":0,"k":[34.6,163.716],"ix":2},"a":{"a":0,"k":[0,0],"ix":1},"s":{"a":0,"k":[100,100],"ix":3},"r":{"a":0,"k":0,"ix":6},"o":{"a":0,"k":100,"ix":7},"sk":{"a":0,"k":0,"ix":4},"sa":{"a":0,"k":0,"ix":5},"nm":"Transform"}],"nm":"Group 6","np":2,"cix":2,"bm":0,"ix":6,"mn":"ADBE Vector Group","hd":false},{"ty":"gr","it":[{"ind":0,"ty":"sh","ix":1,"ks":{"a":0,"k":{"i":[[-2.173,0],[0,2.183],[2.173,0],[0,-2.183]],"o":[[2.173,0],[0,-2.183],[-2.173,0],[0,2.183]],"v":[[0,3.953],[3.935,0],[0,-3.953],[-3.935,0]],"c":true},"ix":2},"nm":"Path 1","mn":"ADBE Vector Shape - Group","hd":false},{"ty":"fl","c":{"a":0,"k":[0.011764706817,0.305882352941,0.635294117647,1],"ix":4},"o":{"a":0,"k":100,"ix":5},"r":1,"bm":0,"nm":"Fill 1","mn":"ADBE Vector Graphic - Fill","hd":false},{"ty":"tr","p":{"a":0,"k":[35.35,54.347],"ix":2},"a":{"a":0,"k":[0,0],"ix":1},"s":{"a":0,"k":[100,100],"ix":3},"r":{"a":0,"k":0,"ix":6},"o":{"a":0,"k":100,"ix":7},"sk":{"a":0,"k":0,"ix":4},"sa":{"a":0,"k":0,"ix":5},"nm":"Transform"}],"nm":"Group 7","np":2,"cix":2,"bm":0,"ix":7,"mn":"ADBE Vector Group","hd":false},{"ty":"gr","it":[{"ind":0,"ty":"sh","ix":1,"ks":{"a":0,"k":{"i":[[-1.138,0],[0,1.144],[1.138,0],[0,-1.143]],"o":[[1.138,0],[0,-1.143],[-1.138,0],[0,1.144]],"v":[[0,2.07],[2.061,-0.001],[0,-2.071],[-2.061,-0.001]],"c":true},"ix":2},"nm":"Path 1","mn":"ADBE Vector Shape - Group","hd":false},{"ty":"fl","c":{"a":0,"k":[0.470588265213,0.713725490196,0.992156922583,1],"ix":4},"o":{"a":0,"k":100,"ix":5},"r":1,"bm":0,"nm":"Fill 1","mn":"ADBE Vector Graphic - Fill","hd":false},{"ty":"tr","p":{"a":0,"k":[184.51,79.195],"ix":2},"a":{"a":0,"k":[0,0],"ix":1},"s":{"a":0,"k":[100,100],"ix":3},"r":{"a":0,"k":0,"ix":6},"o":{"a":0,"k":100,"ix":7},"sk":{"a":0,"k":0,"ix":4},"sa":{"a":0,"k":0,"ix":5},"nm":"Transform"}],"nm":"Group 8","np":2,"cix":2,"bm":0,"ix":8,"mn":"ADBE Vector Group","hd":false},{"ty":"gr","it":[{"ind":0,"ty":"sh","ix":1,"ks":{"a":0,"k":{"i":[[-3.209,0],[0,-3.119],[3.208,0],[0,3.119]],"o":[[3.208,0],[0,3.119],[-3.209,0],[0,-3.119]],"v":[[0,-5.647],[5.809,0],[0,5.647],[-5.809,0]],"c":true},"ix":2},"nm":"Path 1","mn":"ADBE Vector Shape - Group","hd":false},{"ty":"fl","c":{"a":0,"k":[0.011764706817,0.305882352941,0.635294117647,1],"ix":4},"o":{"a":0,"k":100,"ix":5},"r":1,"bm":0,"nm":"Fill 1","mn":"ADBE Vector Graphic - Fill","hd":false},{"ty":"tr","p":{"a":0,"k":[176.64,148.28],"ix":2},"a":{"a":0,"k":[0,0],"ix":1},"s":{"a":0,"k":[100,100],"ix":3},"r":{"a":0,"k":0,"ix":6},"o":{"a":0,"k":100,"ix":7},"sk":{"a":0,"k":0,"ix":4},"sa":{"a":0,"k":0,"ix":5},"nm":"Transform"}],"nm":"Group 9","np":2,"cix":2,"bm":0,"ix":9,"mn":"ADBE Vector Group","hd":false},{"ty":"gr","it":[{"ind":0,"ty":"sh","ix":1,"ks":{"a":0,"k":{"i":[[-2.691,0],[0,-2.807],[2.691,0],[0,2.807]],"o":[[2.691,0],[0,2.807],[-2.691,0],[0,-2.807]],"v":[[0,-5.082],[4.872,0.001],[0,5.083],[-4.872,0.001]],"c":true},"ix":2},"nm":"Path 1","mn":"ADBE Vector Shape - Group","hd":false},{"ty":"fl","c":{"a":0,"k":[0.470588265213,0.713725490196,0.992156922583,1],"ix":4},"o":{"a":0,"k":100,"ix":5},"r":1,"bm":0,"nm":"Fill 1","mn":"ADBE Vector Graphic - Fill","hd":false},{"ty":"tr","p":{"a":0,"k":[73.389,175.951],"ix":2},"a":{"a":0,"k":[0,0],"ix":1},"s":{"a":0,"k":[100,100],"ix":3},"r":{"a":0,"k":0,"ix":6},"o":{"a":0,"k":100,"ix":7},"sk":{"a":0,"k":0,"ix":4},"sa":{"a":0,"k":0,"ix":5},"nm":"Transform"}],"nm":"Group 10","np":2,"cix":2,"bm":0,"ix":10,"mn":"ADBE Vector Group","hd":false},{"ty":"gr","it":[{"ind":0,"ty":"sh","ix":1,"ks":{"a":0,"k":{"i":[[5.349,-3.702],[0,0],[-5.353,3.698],[0,0]],"o":[[-5.35,3.701],[0,0],[5.349,-3.701],[0,0]],"v":[[3.833,5.586],[-9.689,6.7],[-3.829,-5.586],[9.688,-6.704]],"c":true},"ix":2},"nm":"Path 1","mn":"ADBE Vector Shape - Group","hd":false},{"ty":"fl","c":{"a":0,"k":[0.470588265213,0.713725490196,0.992156922583,1],"ix":4},"o":{"a":0,"k":100,"ix":5},"r":1,"bm":0,"nm":"Fill 1","mn":"ADBE Vector Graphic - Fill","hd":false},{"ty":"tr","p":{"a":0,"k":[142.001,20.024],"ix":2},"a":{"a":0,"k":[0,0],"ix":1},"s":{"a":0,"k":[100,100],"ix":3},"r":{"a":0,"k":0,"ix":6},"o":{"a":0,"k":100,"ix":7},"sk":{"a":0,"k":0,"ix":4},"sa":{"a":0,"k":0,"ix":5},"nm":"Transform"}],"nm":"Group 11","np":2,"cix":2,"bm":0,"ix":11,"mn":"ADBE Vector Group","hd":false},{"ty":"gr","it":[{"ind":0,"ty":"sh","ix":1,"ks":{"a":0,"k":{"i":[[-5.081,-1.209],[0,0],[5.083,1.206],[0,0]],"o":[[5.081,1.209],[0,0],[-5.082,-1.209],[0,0]],"v":[[-1.253,5.307],[9.202,2.187],[1.252,-5.307],[-9.202,-2.191]],"c":true},"ix":2},"nm":"Path 1","mn":"ADBE Vector Shape - Group","hd":false},{"ty":"fl","c":{"a":0,"k":[0.470588265213,0.713725490196,0.992156922583,1],"ix":4},"o":{"a":0,"k":100,"ix":5},"r":1,"bm":0,"nm":"Fill 1","mn":"ADBE Vector Graphic - Fill","hd":false},{"ty":"tr","p":{"a":0,"k":[9.452,73.543],"ix":2},"a":{"a":0,"k":[0,0],"ix":1},"s":{"a":0,"k":[100,100],"ix":3},"r":{"a":0,"k":0,"ix":6},"o":{"a":0,"k":100,"ix":7},"sk":{"a":0,"k":0,"ix":4},"sa":{"a":0,"k":0,"ix":5},"nm":"Transform"}],"nm":"Group 12","np":2,"cix":2,"bm":0,"ix":12,"mn":"ADBE Vector Group","hd":false},{"ty":"gr","it":[{"ind":0,"ty":"sh","ix":1,"ks":{"a":0,"k":{"i":[[-10.583,14.877],[-15.165,8.321],[-1.083,-8.414],[21.348,-11.343]],"o":[[11.311,-15.895],[15.642,-8.587],[1.083,8.414],[-21.353,11.346]],"v":[[-24.085,17.465],[13.975,-23.646],[33.584,-18.527],[8.493,21.166]],"c":true},"ix":2},"nm":"Path 1","mn":"ADBE Vector Shape - Group","hd":false},{"ty":"fl","c":{"a":0,"k":[0.862745157878,0.925490255917,0.996078491211,1],"ix":4},"o":{"a":0,"k":100,"ix":5},"r":1,"bm":0,"nm":"Fill 1","mn":"ADBE Vector Graphic - Fill","hd":false},{"ty":"tr","p":{"a":0,"k":[167.263,151.15],"ix":2},"a":{"a":0,"k":[0,0],"ix":1},"s":{"a":0,"k":[100,100],"ix":3},"r":{"a":0,"k":0,"ix":6},"o":{"a":0,"k":100,"ix":7},"sk":{"a":0,"k":0,"ix":4},"sa":{"a":0,"k":0,"ix":5},"nm":"Transform"}],"nm":"Group 13","np":4,"cix":2,"bm":0,"ix":13,"mn":"ADBE Vector Group","hd":false},{"ty":"gr","it":[{"ind":0,"ty":"sh","ix":1,"ks":{"a":0,"k":{"i":[[-4.749,-8.507],[-17.035,1.654],[-12.812,7.017],[-5.181,6.449],[3.842,7.922],[29.646,-14.988]],"o":[[4.855,8.704],[12.811,-1.246],[8.967,-4.908],[5.614,-6.987],[-5.279,-10.883],[-32.565,16.47]],"v":[[-49.889,23.439],[-17.366,31.685],[19.94,22.457],[41.187,4.459],[50.796,-18.424],[-14.659,-23.484]],"c":true},"ix":2},"nm":"Path 1","mn":"ADBE Vector Shape - Group","hd":false},{"ty":"fl","c":{"a":0,"k":[0.862745157878,0.925490255917,0.996078491211,1],"ix":4},"o":{"a":0,"k":100,"ix":5},"r":1,"bm":0,"nm":"Fill 1","mn":"ADBE Vector Graphic - Fill","hd":false},{"ty":"tr","p":{"a":0,"k":[69.494,38.722],"ix":2},"a":{"a":0,"k":[0,0],"ix":1},"s":{"a":0,"k":[100,100],"ix":3},"r":{"a":0,"k":0,"ix":6},"o":{"a":0,"k":100,"ix":7},"sk":{"a":0,"k":0,"ix":4},"sa":{"a":0,"k":0,"ix":5},"nm":"Transform"}],"nm":"Group 14","np":4,"cix":2,"bm":0,"ix":14,"mn":"ADBE Vector Group","hd":false},{"ty":"gr","it":[{"ind":0,"ty":"sh","ix":1,"ks":{"a":0,"k":{"i":[[3.581,-66.145],[-61.523,19.303],[-21.758,19.639],[-5.183,17.014],[7.761,10.268],[34.723,-36.381]],"o":[[-1.386,25.647],[28.613,-8.978],[12.969,-11.705],[4.477,-14.686],[-17.457,-23.09],[-34.724,36.382]],"v":[[-98.649,18.775],[11.402,77.269],[59.349,33.75],[96.134,-3.822],[90.104,-44.561],[2.683,-47.082]],"c":true},"ix":2},"nm":"Path 1","mn":"ADBE Vector Shape - Group","hd":false},{"ty":"fl","c":{"a":0,"k":[0.862745157878,0.925490255917,0.996078491211,1],"ix":4},"o":{"a":0,"k":100,"ix":5},"r":1,"bm":0,"nm":"Fill 1","mn":"ADBE Vector Graphic - Fill","hd":false},{"ty":"tr","p":{"a":0,"k":[104.571,99.92],"ix":2},"a":{"a":0,"k":[0,0],"ix":1},"s":{"a":0,"k":[100,100],"ix":3},"r":{"a":0,"k":0,"ix":6},"o":{"a":0,"k":100,"ix":7},"sk":{"a":0,"k":0,"ix":4},"sa":{"a":0,"k":0,"ix":5},"nm":"Transform"}],"nm":"Group 15","np":4,"cix":2,"bm":0,"ix":15,"mn":"ADBE Vector Group","hd":false}],"ip":0,"op":900.000036657751,"st":0,"bm":0}],"markers":[]}
                });
                });
            </script>
            <body>
                <div class="container">
                    <div id="saatAnim" class="saatAnim"></div>
                    
                    <div class="loading">
                        <span style="font-size:16pt; font-weight: 600;">Paycell ödeme adımına yönlendiriliyorsun.</span>
                    </div>
                    <div style="margin:5px;">
                        <span style="font-size:13pt;">Güvenliğin için ekstra bir adım, bu nedenle birkaç saniye sürebilir.</span>
                    </div>
                    <div style="margin:5px;">
                        <span style="font-size:13pt;">Endişe etme, işlemin kısa sürede tamamlanacak.</span>
                    </div>
                    
                    <form id="threeDSecureForm" name="threeDSecureForm" action="' . $threeDSecureUrl . '" method="POST">
                        <input type="hidden" name="threeDSessionId" value="' . $sessionId . '">
                        <input type="hidden" name="callbackurl" value="' . $callbackUrl . '">
                        <button type="submit" id="submitButton" class="submit-button">Continue to Bank</button>
                    </form>
                    
                    <div id="manualInstructions" class="manual-instructions">
                        If you haven\'t been redirected, please click the button above.
                    </div>
                </div>
            </body>
            </html>';
    }



    private function complete_order($order_id)
    {
        $this->load->model('checkout/order');
        $this->model_checkout_order->addHistory($order_id, $this->config->get('payment_paycell_payment_gateway_order_status_id'), 'Ödeme tamamlandı', false);
    }




    private function make3DSSessionRequest($sessionData)
    {
        $requestData = [
            'merchantCode' => $sessionData['merchantCode'],
            'msisdn' => $sessionData['msisdn'],
            'amount' => $sessionData['amount'],
            'installmentCount' => (int)$sessionData['installmentCount'],
            'cardToken' => $sessionData['cardToken'],
            'cardId' => $sessionData['cardId'] ?? null,
            'transactionType' => $sessionData['transactionType'],
            'target' => $sessionData['target'],
            'requestHeader' => [
                'transactionId' => $sessionData['transactionId'],
                'transactionDateTime' => $sessionData['transactionDateTime'],
                'clientIPAddress' => $sessionData['clientIPAddress'],
                'applicationName' => $this->getApplicationName(),
                'applicationPwd' => $this->getApplicationPassword()
            ]
        ];
        
        return $this->makeRequest('/api/3d/session', $requestData);
    }

    private function make3DsSessionResultRequest($sessionData)
    {
        $requestData = [
            'merchantCode' => $this->getMerchantCode(),
            'msisdn' => $sessionData['msisdn'],
            'transactionType' => $sessionData['transactionType'],
            'threeDSessionId' => $sessionData['threeDSessionId'],
            'requestHeader' => [
                'transactionId' => $sessionData['transactionId'],
                'transactionDateTime' => $sessionData['transactionDateTime'],
                'clientIPAddress' => $sessionData['clientIPAddress'],
                'applicationName' => $this->getApplicationName(),
                'applicationPwd' => $this->getApplicationPassword()
            ]
        ];
        
        return $this->makeRequest('/api/3d/session-result', $requestData);
    }

    private function makeProvisionAllRequest($sessionData)
    {
        $requestData = [
            'paymentMethodType' => 'CREDIT_CARD',
            'merchantCode' => $this->getMerchantCode(),
            'msisdn' => $sessionData['msisdn'],
            'referenceNumber' => $sessionData['transactionNumber'],
            'amount' => $sessionData['amount'],
            'currency' => 'TRY',
            'installmentCount' => $sessionData['installmentCount'],
            'paymentType' => 'SALE',
            'threeDSessionId' => $sessionData['threeDSessionId'],
            'cardToken' => $sessionData['cardToken'] ?? null,
            'cardId' => $sessionData['cardId'] ?? null,
            'requestHeader' => [
                'transactionId' => $sessionData['transactionId'],
                'transactionDateTime' => $sessionData['transactionDateTime'],
                'clientIPAddress' => $sessionData['clientIPAddress'],
                'applicationName' => $this->getApplicationName(),
                'applicationPwd' => $this->getApplicationPassword()
            ]
        ];
        
        return $this->makeRequest('/api/payment/provision', $requestData);
    }

    private function makeInquireAllRequest($sessionData)
    {
        $requestData = [
            'requestHeader' => [
                'transactionId' => $sessionData['transactionId'],
                'transactionDateTime' => $sessionData['transactionDateTime'],
                'clientIPAddress' => $sessionData['clientIPAddress'],
                'applicationName' => $this->getApplicationName(),
                'applicationPwd' => $this->getApplicationPassword()
            ],
            'msisdn' => $sessionData['msisdn'],
            'merchantCode' => $this->getMerchantCode(),
            'originalReferenceNumber' => $sessionData['referenceNumber'],
            'paymentMethodType' => $sessionData['paymentMethodType'],
            'referenceNumber' => $sessionData['referenceNumber'],
        ];
        
        return $this->makeRequest('/api/payment/inquire', $requestData);
    }

    private function getClientIPAddress()
    {
        $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    private function getBaseUrl()
    {
        $sandboxMode = $this->config->get('payment_paycell_payment_gateway_sandbox_mode');
        
        if ($sandboxMode) {
            return 'https://plugin-wp-test.paycell.com.tr';
        } else {
            return 'https://plugin-wp-prod.paycell.com.tr';
        }
    }

    private function getCardTokenUrl()
    {
        $sandboxMode = $this->config->get('payment_paycell_payment_gateway_sandbox_mode');
        
        if ($sandboxMode) {
            return 'https://omccstb.turkcell.com.tr/paymentmanagement/rest/getCardTokenSecure';
        } else {
            return 'https://epayment-gtm.turkcell.com.tr/paymentmanagement/rest/getCardTokenSecure';
        }
    }

    private function makeRequest($endpoint, $data = null, $method = 'POST')
    {
        $url = $this->getBaseUrl() . $endpoint;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);

        if ($method === 'POST' && $data) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception('CURL Error: ' . $error);
        }

        if ($httpCode !== 200) {
            throw new \Exception('HTTP Error: ' . $httpCode);
        }

        $responseData = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON response from PayCell API');
        }

        return $responseData;
    }


    private function makeBinCheckRequest($sessionData)
    {
        $requestData = [
            'requestHeader' => [
                'transactionId' => $sessionData['transactionId'],
                'transactionDateTime' => $sessionData['transactionDateTime'],
                'clientIPAddress' => $sessionData['clientIPAddress'],
                'applicationName' => $this->getApplicationName(),
                'applicationPwd' => $this->getApplicationPassword()
            ],
            'binValue' => $sessionData['binNumber'],
            'merchantCode' => $this->getMerchantCode(),
        ];
        
        return $this->makeRequest('/api/cards/bin-info', $requestData);
    }

    public function getCards()
    {
        $this->response->addHeader('Content-Type: application/json');
        try {
            $this->validateCsrfToken();
            $input = json_decode(file_get_contents('php://input'), true);
            
            $msisdn = null;
            if (isset($this->session->data['order_id'])) {
                $order_id = $this->session->data['order_id'];
                $order_info = $this->getOrder($order_id);
                $msisdn = $order_info['telephone'] ?? null;
            }
            
            if (!$msisdn && isset($input['msisdn'])) {
                $msisdn = $input['msisdn'];
            }
            
            if (!$msisdn) {
                throw new \Exception('MSISDN (phone number) is required');
            }
            
            $transactionId = $input['transactionId'] ?? $this->generateTransactionId();
            $transactionDateTime = $input['transactionDateTime'] ?? $this->generateTransactionDateTime();
            
            $sessionData = [
                'transactionId' => $transactionId,
                'transactionDateTime' => $transactionDateTime,
                'clientIPAddress' => $this->getClientIPAddress(),
                'msisdn' => $msisdn
            ];
            
            if (isset($input['referenceNumber'])) {
                $sessionData['referenceNumber'] = $input['referenceNumber'];
            }
            
            if (isset($input['otpToken'])) {
                $sessionData['otpToken'] = $input['otpToken'];
            }
            
            $response = $this->makeGetCardsRequest($sessionData);
            
            if ($response && isset($response['responseHeader']['responseCode'])) {
                $responseCode = $response['responseHeader']['responseCode'];
                
                if ($responseCode == '3110' || (isset($response['responseHeader']['responseDescription']) && 
                    (stripos($response['responseHeader']['responseDescription'], 'otp') !== false || 
                     stripos($response['responseHeader']['responseDescription'], 'verification') !== false))) {
                    $this->response->setOutput(json_encode([
                        'success' => false,
                        'requiresOTP' => true,
                        'message' => 'OTP verification required',
                    ]), true);
                    return;
                }
                
                if ($responseCode == '0') {
                    $cards = [];
                    
                    $cardsData = null;
                    if (isset($response['paymentMethods']) && is_array($response['paymentMethods'])) {
                        $cardsData = $response['paymentMethods'];
                    } elseif (isset($response['cards']) && is_array($response['cards'])) {
                        $cardsData = $response['cards'];
                    } elseif (isset($response['cardList']) && is_array($response['cardList'])) {
                        $cardsData = $response['cardList'];
                    } elseif (isset($response['data']['paymentMethods']) && is_array($response['data']['paymentMethods'])) {
                        $cardsData = $response['data']['paymentMethods'];
                    } elseif (isset($response['data']['cards']) && is_array($response['data']['cards'])) {
                        $cardsData = $response['data']['cards'];
                    } elseif (isset($response['data']['cardList']) && is_array($response['data']['cardList'])) {
                        $cardsData = $response['data']['cardList'];
                    }
                    
                    if ($cardsData) {
                        foreach ($cardsData as $card) {
                            $cards[] = [
                                'id' => $card['cardId'] ?? null,
                                'name' => $card['alias'] ?? null,
                                'masked_card_no' => $card['maskedCardNo'] ?? null,
                                'cardBrand' => $card['cardBrand'] ?? null,
                                'cardType' => $card['cardType'] ?? null,
                                'isDefault' => $card['isDefault'] ?? null,
                                'isExpired' => $card['isExpired'] ?? null,
                                'showEulaId' => $card['showEulaId'] ?? null,
                                'isThreeDValidated' => $card['isThreeDValidated'] ?? null,
                                'isOTPValidated' => $card['isOTPValidated'] ?? null,
                                'activationDate' => $card['activationDate'] ?? null,
                            ];
                        }
                    }
                    
                    $this->response->setOutput(json_encode([
                        'success' => true,
                        'cards' => $cards,
                    ]), true);
                } else {
                    $errorMessage = $response['responseHeader']['responseDescription'] ?? 
                                   $response['errorMessage'] ?? 
                                   $response['message'] ?? 
                                   'Failed to get cards';
                    throw new \Exception($errorMessage);
                }
            } else {
                throw new \Exception('Invalid response from Paycell API');
            }
            
        } catch (\Exception $exception) {
            $errorResponse = $this->buildErrorResponse(-1, $exception->getMessage());
            $this->response->setOutput(json_encode($errorResponse), true);
        }
    }

    private function makeGetCardsRequest($sessionData)
    {
        $requestData = [
            'requestHeader' => [
                'transactionId' => $sessionData['transactionId'],
                'transactionDateTime' => $sessionData['transactionDateTime'],
                'clientIPAddress' => $sessionData['clientIPAddress'],
                'applicationName' => $this->getApplicationName(),
                'applicationPwd' => $this->getApplicationPassword()
            ],
            'msisdn' => $sessionData['msisdn'],
            'merchantCode' => $this->getMerchantCode(),
        ];
        
        if (isset($sessionData['referenceNumber'])) {
            $requestData['referenceNumber'] = $sessionData['referenceNumber'];
        }
        
        if (isset($sessionData['otpToken'])) {
            $requestData['otpToken'] = $sessionData['otpToken'];
        }
        
        return $this->makeRequest('/api/cards/payment-methods', $requestData);
    }

    private function generateTransactionId()
    {
        $timestamp = (string)(time() * 1000); 
        $random = str_pad((string)random_int(0, 9999999), 7, '0', STR_PAD_LEFT); 
        return $timestamp . $random;
    }

    private function generateTransactionDateTime()
    {
        $now = new \DateTime();
        $microseconds = (int)$now->format('u');
        $milliseconds = (int)($microseconds / 1000);
        return $now->format('YmdHis') . str_pad((string)$milliseconds, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Send OTP to customer's phone number
     */
    public function sendOTP()
    {
        $this->response->addHeader('Content-Type: application/json');
        try {
            $this->validateCsrfToken();
            $input = json_decode(file_get_contents('php://input'), true);
            
            $msisdn = null;
            if (isset($this->session->data['order_id'])) {
                $order_id = $this->session->data['order_id'];
                $order_info = $this->getOrder($order_id);
                $msisdn = $order_info['telephone'] ?? null;
            }
            
            if (!$msisdn && isset($input['msisdn'])) {
                $msisdn = $input['msisdn'];
            }
            
            if (!$msisdn) {
                throw new \Exception('MSISDN (phone number) is required');
            }
            
            $transactionId = $input['transactionId'] ?? $this->generateTransactionId();
            $transactionDateTime = $input['transactionDateTime'] ?? $this->generateTransactionDateTime();
            
            $sessionData = [
                'transactionId' => $transactionId,
                'transactionDateTime' => $transactionDateTime,
                'clientIPAddress' => $this->getClientIPAddress(),
                'msisdn' => $msisdn
            ];
            
            if (isset($input['referenceNumber'])) {
                $sessionData['referenceNumber'] = $input['referenceNumber'];
            }
            
            $response = $this->makeSendOTPRequest($sessionData);
            
            if ($response && isset($response['responseHeader']['responseCode']) && $response['responseHeader']['responseCode'] == '0') {
                $this->response->setOutput(json_encode([
                    'success' => true,
                    'message' => 'OTP sent successfully',
                    'otpReferenceId' => $response['otpReferenceId'] ?? $response['referenceId'] ?? null,
                    'otpToken' => $response['otpToken'] ?? $response['token'] ?? null,
                ]), true);
            } else {
                $errorMessage = $response['responseHeader']['responseDescription'] ?? 
                               $response['errorMessage'] ?? 
                               $response['message'] ?? 
                               'Failed to send OTP';
                throw new \Exception($errorMessage);
            }
            
        } catch (\Exception $exception) {
            $errorResponse = $this->buildErrorResponse(-1, $exception->getMessage());
            $this->response->setOutput(json_encode($errorResponse), true);
        }
    }

    /**
     * Verify OTP code
     */
    public function verifyOTP()
    {
        $this->response->addHeader('Content-Type: application/json');
        try {
            $this->validateCsrfToken();
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($input['otpCode'])) {
                throw new \Exception('OTP code is required');
            }
            
            $msisdn = null;
            if (isset($this->session->data['order_id'])) {
                $order_id = $this->session->data['order_id'];
                $order_info = $this->getOrder($order_id);
                $msisdn = $order_info['telephone'] ?? null;
            }
            
            if (!$msisdn && isset($input['msisdn'])) {
                $msisdn = $input['msisdn'];
            }
            
            if (!$msisdn) {
                throw new \Exception('MSISDN (phone number) is required');
            }
            
            $transactionId = $input['transactionId'] ?? $this->generateTransactionId();
            $transactionDateTime = $input['transactionDateTime'] ?? $this->generateTransactionDateTime();
            
            $sessionData = [
                'referenceNumber' => $input['referenceNumber'],
                'transactionId' => $transactionId,
                'transactionDateTime' => $transactionDateTime,
                'clientIPAddress' => $this->getClientIPAddress(),
                'msisdn' => $msisdn,
                'otpCode' => $input['otpCode'],
                'otpReferenceId' => $input['otpReferenceId'] ?? null,
                'otpToken' => $input['otpToken'] ?? null,
            ];
            
            $response = $this->makeVerifyOTPRequest($sessionData);
            
            if ($response && isset($response['responseHeader']['responseCode']) && $response['responseHeader']['responseCode'] == '0') {
                $otpToken = $response['otpToken'] ?? $response['token'] ?? $response['otpTokenId'] ?? null;
                
                $this->response->setOutput(json_encode([
                    'success' => true,
                    'message' => 'OTP verified successfully',
                    'otpToken' => $otpToken,
                ]), true);
            } else {
                $errorMessage = $response['responseHeader']['responseDescription'] ?? 
                               $response['errorMessage'] ?? 
                               $response['message'] ?? 
                               'Failed to verify OTP';
                throw new \Exception($errorMessage);
            }
            
        } catch (\Exception $exception) {
            $errorResponse = $this->buildErrorResponse(-1, $exception->getMessage());
            $this->response->setOutput(json_encode($errorResponse), true);
        }
    }

    private function makeSendOTPRequest($sessionData)
    {
        $requestData = [
            'requestHeader' => [
                'transactionId' => $sessionData['transactionId'],
                'transactionDateTime' => $sessionData['transactionDateTime'],
                'clientIPAddress' => $sessionData['clientIPAddress'],
                'applicationName' => $this->getApplicationName(),
                'applicationPwd' => $this->getApplicationPassword()
            ],
            'extraParameters' => [
                ['key' => 'VALIDATION_TYPE', 'value' => 'PM_LIST'],
            ],
            'referenceNumber' => $sessionData['referenceNumber'],
            'msisdn' => $sessionData['msisdn'],
            'merchantCode' => $this->getMerchantCode(),
        ];
        
        if (isset($sessionData['referenceNumber'])) {
            $requestData['referenceNumber'] = $sessionData['referenceNumber'];
        }
        
        return $this->makeRequest('/api/otp/send', $requestData);
    }

    private function makeVerifyOTPRequest($sessionData)
    {
        $requestData = [
            'requestHeader' => [
                'transactionId' => $sessionData['transactionId'],
                'transactionDateTime' => $sessionData['transactionDateTime'],
                'clientIPAddress' => $sessionData['clientIPAddress'],
                'applicationName' => $this->getApplicationName(),
                'applicationPwd' => $this->getApplicationPassword()
            ],
            'extraParameters' => [
                ['key' => 'VALIDATION_TYPE', 'value' => 'PM_LIST'],
            ],
            'referenceNumber' => $sessionData['referenceNumber'],
            'msisdn' => $sessionData['msisdn'],
            'otp' => $sessionData['otpCode'],
            'token' => $sessionData['otpToken'],
        ];
        
            if (isset($sessionData['otpReferenceId'])) {
                $requestData['otpReferenceId'] = $sessionData['otpReferenceId'];
            }
            
            if (isset($sessionData['otpToken'])) {
                $requestData['otpToken'] = $sessionData['otpToken'];
            }
        
        return $this->makeRequest('/api/otp/validate', $requestData);
    }







    private function buildErrorResponse($errorCode, $errorDescription)
    {
        return array(
            'errors' => array(
                'errorCode' => $errorCode,
                'errorDescription' => $errorDescription
            )
        );
    }


    private function formatPrice($number)
    {
        return round((float)$number, 2);
    }

    private function getShippingInfo()
    {
        if (isset($this->session->data['shipping_method'])) {
            $shipping_info = $this->session->data['shipping_method'];
        } else {
            $shipping_info = false;
        }
        return $shipping_info;
    }

    private function getOrder($order_id)
    {
        $this->load->model('extension/paycell_payment_gateway/payment/paycell_payment_gateway');
        return $this->model_extension_paycell_payment_gateway_payment_paycell_payment_gateway->getOrder($order_id);
    }






    private static function isHttps()
    {
        static $ret;

        isset($ret) || $ret = @ (
            $_SERVER['REQUEST_SCHEME'] == 'https' ||
            $_SERVER['SERVER_PORT'] == '443' ||
            $_SERVER['HTTPS'] == 'on'
        );

        return $ret;
    }
}
