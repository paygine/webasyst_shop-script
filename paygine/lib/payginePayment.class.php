<?php

/**
 * @package waPlugins
 * @subpackage Payment
 * @name Paygine
 * @description Paygine payment module
 * @payment-type online
 *
 * @property-read string $sector_id
 * @property-read string $password
 * @property-read string $test_mode
 *
 */
class payginePayment extends waPayment implements waIPayment {

	private $order_id;
	private $callback_request;

	public function allowedCurrency() {
		return array('RUB', 'USD', 'EUR');
	}

	public function payment($payment_form_data, $order_data, $auto_submit = false) {

		$order_data = waOrder::factory($order_data);

		switch ($order_data['currency_id']) {
			case 'RUR':
				$currency = '643';
				break;
			case 'RUB':
				$currency = '643';
				break;
			case 'EUR':
				$currency = '978';
				break;
			case 'USD':
				$currency = '840';
				break;
			default:
				return 'Невозможно использовать выбранный способ оплаты с этой валютой';
		}

		$price = intval(floatval($order_data['amount']) * 100);

		if ($this->test_mode == '0')
			$paygine_url = 'https://pay.paygine.com';
		else
			$paygine_url = 'https://test.paygine.com';
		$url = $paygine_url . '/webapi/Register';

		$email = '';
		if (isset($payment_form_data['customer_email']))
			$email = $payment_form_data['customer_email'];
		if (empty($email))
			$email = isset($order_data['customer_info']['email']) ? $order_data['customer_info']['email'] : '';
		if (empty($email))
			$email = isset($order_data['contact_email']) ? $order_data['contact_email'] : '';
		if (empty($email)) {
			$contact = new waContact($order_data->contact_id);
			$email = isset($contact->get('email', 'value')[0]) ? $contact->get('email', 'value')[0] : '';
		}

		// Ставка НДС
		$TAX = (isset($this->tax) && $this->tax > 0 && $this->tax <= 6) ? $this->tax : 6;

	    // список товаров
	    $fiscalPositions='';
	    $fiscalAmount = 0;
	    foreach ($order_data->items as $item) {
			$fiscalPositions .= $item['quantity'].';';
			$elementPrice = round($item['price'], 2) - round(ifset($item['discount'], 0.0), 2);
			$elementPrice = $elementPrice * 100;
			$fiscalPositions .= $elementPrice.';';
			$fiscalPositions .= $TAX . ';';
			$fiscalPositions .= mb_substr($item['name'], 0, 128).'|';

			$fiscalAmount += $item['quantity'] * $elementPrice;
	    }    

	    if ($order_data->shipping > 0) {
			$fiscalPositions.='1;';
			$elementPrice = round($order_data->shipping, 2);
			$elementPrice = $elementPrice * 100;
			$fiscalPositions .= $elementPrice.';';
			$fiscalPositions .= $TAX . ';';
			$fiscalPositions .= 'Доставка|';
			
			$fiscalAmount += $elementPrice;
	    }
    	    
	    $fiscalDiff = abs($fiscalAmount - $price);
	    if ($fiscalDiff) {
	    	$fiscalPositions .= '1;'.$fiscalDiff.';6;Скидка;14|';
	    }
	    $fiscalPositions = substr($fiscalPositions, 0, -1);

		$signature  = base64_encode(md5($this->sector_id . $price . $currency . $this->password));
		$data = array(
			'sector' => $this->sector_id,
			'reference' => $this->app_id . '_' . $this->merchant_id . '_' . $order_data['order_id'],
			'amount' => $price,
      		'fiscal_positions' => $fiscalPositions,  // список товаров
			'description' => '#' . $order_data['order_id'],
			'email' => htmlspecialchars($email, ENT_QUOTES),
			'currency' => $currency,
			'mode' => 1,
			'url' => $this->getRelayUrl(),
			'signature' => $signature
		);
		$options = array(
			'http' => array(
				'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
				'method'  => 'POST',
				'content' => http_build_query($data),
			),
		);

		$context  = stream_context_create($options);
		$paygine_id = file_get_contents($url, false, $context);

		if (intval($paygine_id) == 0) {
			return 'В процессе оплаты произошла ошибка платежной системы. Попробуйте ещё раз или выберите другой способ оплаты.';
		}

		$signature = base64_encode(md5($this->sector_id . $paygine_id . $this->password));
		$redirect_url = $paygine_url
			. '/webapi/Purchase'
			. '?sector=' . $this->sector_id
			. '&id=' . $paygine_id
			. '&signature=' . $signature;

		$view = wa()->getView();
		$view->assign('form_url', $redirect_url);
		$view->assign('auto_submit', $auto_submit);

		return $view->fetch($this->path . '/templates/payment.html');

	}

	protected function callbackInit($request) {
		$pattern = '/^([a-z]+)_(.+)_(.+)$/';

		if (isset($request['callback']) && $request['callback'] == 1) {
			$xml = file_get_contents('php://input');
			if (!$xml)
				die('error 1');
			$xml = simplexml_load_string($xml);
			if (!$xml)
				die('error 2');
			$response = json_decode(json_encode($xml), true);
			if (!$response)
				die('error 3');
			if (!empty($response['reference']) && preg_match($pattern, $response['reference'], $match)) {
				$this->app_id = $match[1];
				$this->merchant_id = $match[2];
				$this->order_id = $match[3];
				$this->callback_request = $response;
			}
		} else {
			if (!empty($request['reference']) && preg_match($pattern, $request['reference'], $match)) {
				$this->app_id = $match[1];
				$this->merchant_id = $match[2];
				$this->order_id = $match[3];
				$this->callback_request = null;
			}
		}
		return parent::callbackInit($request);
	}

	protected function callbackHandler($request) {

		if (!$this->order_id || !$this->app_id || !$this->merchant_id) {
			throw new waPaymentException('Invalid invoice number', $code);
		}

		$transaction_data = $this->formalizeData($request);
		$transaction_data['order_id'] = $this->order_id;

		if ($this->callback_request) {
			if ($this->isOperationApproved($this->callback_request)) {
				$this->execAppCallback(self::CALLBACK_PAYMENT, $transaction_data);
			}
			header('Content-type: text/plain');
			die('ok');
		}

		if ($this->checkPaymentState($request)) {
			$status = waAppPayment::URL_SUCCESS;
			$this->execAppCallback(self::CALLBACK_PAYMENT, $transaction_data);
		} else {
			$status = waAppPayment::URL_FAIL;
			$this->execAppCallback(self::CALLBACK_DECLINE, $transaction_data);
		}

		return array(
			'redirect' => $this->getAdapter()->getBackUrl($status, $transaction_data)
		);

	}

	private function checkPaymentState($request) {
		$paygine_order_id = intval($request['id']);
		if (!$paygine_order_id)
			throw new waPaymentException("Invalid order id", $code);

		$paygine_operation_id = intval($request['operation']);
		if (!$paygine_operation_id)
			throw new waPaymentException("Invalid operation id", $code);

		$signature = base64_encode(md5($this->sector_id . $paygine_order_id . $paygine_operation_id . $this->password));

		if ($this->test_mode == '0')
			$paygine_url = 'https://pay.paygine.com';
		else
			$paygine_url = 'https://test.paygine.com';
		$url = $paygine_url . '/webapi/Operation';

		$context  = stream_context_create(array(
			'http' => array(
				'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
				'method'  => 'POST',
				'content' => http_build_query(array(
					'sector' => $this->sector_id,
					'id' => $paygine_order_id,
					'operation' => $paygine_operation_id,
					'signature' => $signature
				)),
			)
		));

		$repeat = 3;
		try {
			while ($repeat) {
				$repeat--;
				sleep(2);

				$xml = file_get_contents($url, false, $context);
				if (!$xml)
					throw new Exception('Empty data');
				$xml = simplexml_load_string($xml);
				if (!$xml)
					throw new Exception('Non valid XML was received');
				$response = json_decode(json_encode($xml), true);
				if (!$response)
					throw new Exception('Non valid XML was received');

				if (!$this->isOperationApproved($response))
					continue;

				return true;
			}

			throw new Exception('Unknown error');

		} catch (Exception $ex) {
			error_log($ex->getMessage());
			return false;
		}

	}

	private function isOperationApproved($response) {
		$tmp_response = (array)$response;
		unset($tmp_response['signature']);
		$signature = base64_encode(md5(implode('', $tmp_response) . $this->password));
		if ($signature !== $response['signature'])
			return false;
		return (($response['type'] == 'PURCHASE' || $response['type'] == 'EPAYMENT') && $response['state'] == 'APPROVED');

	}

}