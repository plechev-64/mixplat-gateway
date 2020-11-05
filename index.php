<?php

require_once 'mixplat-api.php';

add_action( 'init', 'rcl_add_mixplat_gateway', 10 );
function rcl_add_mixplat_gateway() {
	$pm = new Rcl_Mixplat_Gateway();
	$pm->register_payment( 'mixplat' );
}

class Rcl_Mixplat_Gateway extends Rcl_Payment {

	public $form_pay_id;

	function register_payment( $form_pay_id ) {
		$this->form_pay_id = $form_pay_id;
		parent::add_payment( $this->form_pay_id, array(
			'class'		 => get_class( $this ),
			'request'	 => 'mixplat-request',
			'name'		 => 'Mixplat',
			'image'		 => rcl_addon_url( 'assets/mixplat.jpg', __FILE__ )
		) );
		if ( is_admin() )
			$this->add_options();
	}

	function add_options() {
		add_filter( 'rcl_pay_option', (array( $this, 'options' ) ) );
		add_filter( 'rcl_pay_child_option', (array( $this, 'child_options' ) ) );
	}

	function options( $options ) {
		$options[$this->form_pay_id] = 'Mixplat';
		return $options;
	}

	function child_options( $content ) {

		$options = array(
			array(
				'type'			 => 'text',
				'slug'			 => 'mp_customname',
				'title'			 => __( 'Наименование платежной системы' ),
				'placeholder'	 => 'Mixplat'
			),
			array(
				'type'	 => 'text',
				'slug'	 => 'mp_projectid',
				'title'	 => __( 'Project ID' )
			),
			array(
				'type'	 => 'password',
				'slug'	 => 'mp_apikey',
				'title'	 => __( 'API Key' )
			),
			array(
				'type'	 => 'select',
				'slug'	 => 'mp_test',
				'title'	 => __( 'Режим работы' ),
				'values' => array(
					__( 'Рабочий' ),
					__( 'Тестовый' )
				),
			),
			array(
				'type'		 => 'select',
				'slug'		 => 'mp_fn',
				'title'		 => __( 'Фискализация платежа' ),
				'values'	 => array(
					__( 'Отключено' ),
					__( 'Включено' )
				),
				'childrens'	 => array(
					1 => array(
						array(
							'type'	 => 'select',
							'slug'	 => 'mp_nds',
							'title'	 => __( 'Ставка НДС' ),
							'values' => array(
								'none'	 => __( 'без НДС' ),
								'vat0'	 => __( 'НДС по ставке 0%' ),
								'vat10'	 => __( 'НДС по ставке 10%' ),
								'vat20'	 => __( 'НДС по ставке 20%' ),
								'vat110' => __( 'НДС по ставке 10/110' ),
								'vat120' => __( 'НДС по ставке 20/120' )
							)
						)
					)
				)
			)
		);

		$RclOptions = new Rcl_Options();

		$content .= $RclOptions->child(
			array(
			'name'	 => 'connect_sale',
			'value'	 => $this->form_pay_id
			), array(
			$RclOptions->options_box( __( 'Настройки подключения Mixplat' ), $options )
			)
		);

		return $content;
	}

	function pay_form( $data ) {
		global $rmag_options;

		$projectid	 = $rmag_options['mp_projectid'];
		$apikey		 = $rmag_options['mp_apikey'];
		$test		 = $rmag_options['mp_test'];

		$currency		 = isset( $rmag_options['primary_cur'] ) ? $rmag_options['primary_cur'] : 'RUB';
		$desc			 = ($data->description) ? $data->description : 'Платеж от ' . get_the_author_meta( 'user_email', $data->user_id );
		$baggage_data	 = ($data->baggage_data) ? $data->baggage_data : false;

		$paymentArgs = array(
			'api_version'			 => 3,
			'amount'				 => intval( $data->pay_summ * 100 ),
			'currency'				 => $currency,
			'request_id'			 => $data->pay_id,
			'merchant_payment_id'	 => $data->pay_id,
			'project_id'			 => $projectid,
			'user_email'			 => get_the_author_meta( 'email', $data->user_id ),
			'url_success'			 => get_permalink( $rmag_options['page_successfully_pay'] ),
			'url_failure'			 => get_permalink( $rmag_options['page_fail_pay'] ),
			//'notify_url'			 => get_permalink( $rmag_options['page_result_pay'] ),
			'description'			 => $desc,
			'merchant_fields'		 => array(
				'baggage'	 => $baggage_data,
				'user_id'	 => $data->user_id,
				'pay_type'	 => $data->pay_type
			)
		);

		if ( $test ) {
			$paymentArgs['test'] = 1;
		}

		$paymentArgs['signature'] = MixplatAPI::calcPaymentSignature( $paymentArgs, $apikey );

		if ( $rmag_options['fn_fn'] ) {

			$items = array();

			if ( $data->pay_type == 1 ) {

				$items[] = array(
					'quantity'	 => 1,
					'sum'		 => $data->pay_summ,
					'vat'		 => $rmag_options['mp_nds'],
					'name'		 => __( 'Пополнение личного счета' )
				);
			} else if ( $data->pay_type == 2 ) {

				$order = rcl_get_order( $data->pay_id );

				if ( $order ) {

					$items[] = array(
						'quantity'	 => 1,
						'sum'		 => $order->order_price,
						'vat'		 => $rmag_options['mp_nds'],
						'name'		 => __( 'Оплата заказа' ) . ' №' . $order->order_id
					);
				}
			} else {

				$items[] = array(
					'quantity'	 => 1,
					'sum'		 => $data->pay_summ,
					'vat'		 => $rmag_options['mp_nds'],
					'name'		 => $data->description
				);
			}

			$paymentArgs['items'] = $items;
		}

		$result = MixplatAPI::createPayment( $paymentArgs );

		$fields = array(
			'projectid'		 => $projectid,
			'amount'		 => $data->pay_summ,
			'paimentid'		 => $data->pay_id,
			'userid'		 => $data->user_id,
			'paymenttype'	 => $data->pay_type,
			'baggagedata'	 => $baggage_data
		);

		$data->onclick	 = 'location.replace(\'' . $result->redirect_url . '\');return false;';
		$form			 = parent::form( $fields, $data, $result->redirect_url );
		$data->onclick	 = '';

		return $form;
	}

	function result( $data ) {
		global $rmag_options;

		$projectid	 = $rmag_options['mp_projectid'];
		$apikey		 = $rmag_options['mp_apikey'];

		$POST = json_decode( file_get_contents( 'php://input' ), TRUE );

		if ( ! $POST ) {
			rcl_add_log( 'PAYMENT RESULT', 'Empty data', true );
			exit;
		}

		$sign = MixplatAPI::calcActionSignature( $POST, $apikey );

		if ( strcmp( $sign, $POST['signature'] ) !== 0 ) {
			rcl_add_log( 'PAYMENT RESULT', 'Incorrect signature', true );
			rcl_mail_payment_error( $sign, $POST );
			exit;
		}

		if ( $POST['status'] !== 'success' ) {
			header( 'Content-Type: application/json' );
			echo json_encode( array( 'result' => 'ok' ) );
			exit;
		}

		$merchant_fields = json_decode( $POST['merchant_fields'] );

		$data->pay_summ		 = $POST['amount'] / 100;
		$data->pay_id		 = $POST['merchant_payment_id'];
		$data->user_id		 = $merchant_fields->user_id;
		$data->pay_type		 = $merchant_fields->pay_type;
		$data->baggage_data	 = $merchant_fields->baggage;

		if ( ! parent::get_pay( $data ) ) {
			parent::insert_pay( $data );
			header( 'Content-Type: application/json' );
			echo json_encode( array( 'result' => 'ok' ) );
		}

		exit;
	}

}
