<?php
defined( 'ABSPATH' ) || die( 'This plugin must be run within the scope of WordPress.' );

if ( ! class_exists( 'EDU_NetsEasy' ) ) {
	class EDU_NetsEasy extends EDU_Integration {

		const NetsEasyTestApiUrl = 'https://test.api.dibspayment.eu/v1/payments/';
		const NetsEasyLiveApiUrl = 'https://api.dibspayment.eu/v1/payments/';

		const NetsEasyTestCheckoutUrl = 'https://test.checkout.dibspayment.eu/v1/checkout.js?v=1';
		const NetsEasyLiveCheckoutUrl = 'https://checkout.dibspayment.eu/v1/checkout.js?v=1';

		public function __construct() {
			$this->id          = 'edu-netseasy';
			$this->displayName = __( 'Nets Easy Integration', 'eduadmin-nets-easy-integration' );
			$this->description = '';
			$this->type        = 'payment';

			$this->init_form_fields();
			$this->init_settings();

			add_action( 'eduadmin-checkpaymentplugins', array( $this, 'intercept_booking' ) );
			add_action( 'eduadmin-processbooking', array( $this, 'process_booking' ) );
			add_action( 'eduadmin-bookingcompleted', array( $this, 'process_netsresponse' ) );
			add_action( 'wp_loaded', array( $this, 'process_paymentstatus' ) );

			add_shortcode( 'eduadmin-nets-testpage', array( $this, 'test_page' ) );
		}

		public function test_page( $attributes ) {
			$attributes = shortcode_atts(
				array(
					'bookingid'          => 0,
					'programmebookingid' => 0,
				),
				normalize_empty_atts( $attributes ),
				'test_page'
			);

			if ( $attributes['bookingid'] > 0 ) {
				$event_booking = EDUAPI()->OData->Bookings->GetItem(
					$attributes['bookingid'],
					null,
					'Customer($select=CustomerId;),ContactPerson($select=PersonId;),OrderRows',
					false
				);
			} elseif ( $attributes['programmebookingid'] > 0 ) {
				$event_booking = EDUAPI()->OData->ProgrammeBookings->GetItem(
					$attributes['programmebookingid'],
					null,
					'Customer($select=CustomerId;),ContactPerson($select=PersonId;),OrderRows',
					false
				);
			}

			$_customer = EDUAPI()->OData->Customers->GetItem(
				$event_booking['Customer']['CustomerId'],
				null,
				null,
				false
			);

			$_contact = EDUAPI()->OData->Persons->GetItem(
				$event_booking['ContactPerson']['PersonId'],
				null,
				null,
				false
			);

			$ebi = new EduAdmin_BookingInfo( $event_booking, $_customer, $_contact );

			if ( ! empty( EDU()->session['netseasy-order-id'] ) && ! empty( $_GET['paymentId'] ) && EDU()->session['netseasy-order-id'] === $_GET['paymentId'] ) {
				do_action( 'eduadmin-bookingcompleted', $ebi );
			} else {
				do_action( 'eduadmin-processbooking', $ebi );
			}
		}

		/**
		 * @param EduAdmin_BookingInfo|null $ebi
		 */
		public function intercept_booking( $ebi = null ) {
			if ( 'no' === $this->get_option( 'enabled', 'no' ) ) {
				return;
			}

			if ( ! empty( $_POST['act'] ) && ( 'bookCourse' === $_POST['act'] || 'bookProgramme' === $_POST['act'] ) ) {
				$ebi->NoRedirect = true;
			}
		}

		/**
		 * @param EduAdmin_BookingInfo|null $ebi
		 */
		public function process_booking( $ebi = null ) {
			if ( 'no' === $this->get_option( 'enabled', 'no' ) ) {
				return;
			}

			$ebi->NoRedirect = true;

			if ( empty( $_GET['paymentId'] ) || empty( EDU()->session['netseasy-order-id'] ) ) {

				$checkout_url = ! checked( $this->get_option( 'test_mode', 'no' ), '1', false ) ?
					EDU_NetsEasy::NetsEasyLiveCheckoutUrl :
					EDU_NetsEasy::NetsEasyTestCheckoutUrl;

				$checkout_key = ! checked( $this->get_option( 'test_mode', 'no' ), '1', false ) ?
					$this->get_option( 'live_checkout_key', '' ) :
					$this->get_option( 'test_checkout_key', '' );

				$checkout = $this->create_checkout( $ebi );
				if ( ! isset( $checkout->paymentId ) ) {
					EDU()->write_debug( $checkout );

					return;
				}

				echo '
<script type="text/javascript" src="' . $checkout_url . '"></script>
<div id="nets-checkout-content"></div>
<script type="text/javascript">
var checkoutOptions = {
    checkoutKey: "' . $checkout_key . '",
    paymentId: "' . $checkout->paymentId . '"
};

var checkout = new Nets.Checkout(checkoutOptions);
checkout.on("payment-completed", function(response) {
   location.href = "' . EDU()->session['return-url'] . '"
});
</script>';
			}
		}

		/**
		 * @param EduAdmin_BookingInfo|null $ebi
		 *
		 * @return stdClass|null
		 */
		public function create_checkout( $ebi = null ) {

			$test_mode = ! checked( $this->get_option( 'test_mode', 'no' ), '1', false );

			$checkout_url = $test_mode ?
				EDU_NetsEasy::NetsEasyLiveApiUrl :
				EDU_NetsEasy::NetsEasyTestApiUrl;

			$secret_key = $test_mode ?
				$this->get_option( 'live_secret_key', '' ) :
				$this->get_option( 'test_secret_key', '' );

			$current_url = esc_url( "{$_SERVER['REQUEST_SCHEME']}://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}" );

			$terms_url = $this->get_option( 'termsurl', '' );

			$reference_id = 0;

			$_event = null;

			$completed_url = $current_url;

			if ( ! empty( $ebi->EventBooking['BookingId'] ) ) {
				$reference_id = intval( $ebi->EventBooking['BookingId'] );

				$_event      = EDUAPI()->OData->Events->GetItem( $ebi->EventBooking['EventId'] );
				$current_url = $current_url;

				$completed_url = add_query_arg(
					array(
						'paymentId'      => '{paymentGuid}',
						'act'            => 'paymentCompleted',
						'edu-valid-form' => wp_create_nonce( 'edu-booking-confirm' ),
						'booking_id'     => $reference_id
					),
					$current_url
				);
			}

			if ( ! empty( $ebi->EventBooking['ProgrammeBookingId'] ) ) {
				$reference_id = intval( $ebi->EventBooking['ProgrammeBookingId'] );

				$_event        = EDUAPI()->OData->ProgrammeStarts->GetItem( $ebi->EventBooking['ProgrammeStartId'] );
				$current_url   = $current_url;
				$completed_url = add_query_arg(
					array(
						'paymentId'            => '{paymentGuid}',
						'act'                  => 'paymentCompleted',
						'edu-valid-form'       => wp_create_nonce( 'edu-booking-confirm' ),
						'programme_booking_id' => $reference_id
					),
					$current_url
				);
			}


			$rowExtraInfo = "";

			if ( null != $_event ) {
				if ( ! empty( $_event['City'] ) ) {
					$rowExtraInfo .= ';' . $_event['City'];
				}

				if ( ! empty( $_event['StartDate'] ) ) {
					$rowExtraInfo .= ';' . date( "Y-m-d", strtotime( $_event['StartDate'] ) );
				}

				if ( ! empty( $_event['EndDate'] ) ) {
					$rowExtraInfo .= ';' . date( "Y-m-d", strtotime( $_event['EndDate'] ) );
				}
			}

			$netsOrder                       = array();
			$netsOrder['order']              = array();
			$netsOrder['order']['amount']    = 0;
			$netsOrder['order']['currency']  = get_option( 'eduadmin-currency', 'SEK' );
			$netsOrder['order']['reference'] = $reference_id;
			$netsOrder['order']['items']     = array();

			$totalGrossTotalAmount = 0;

			$priceWithVAT = function ( $price, $vatPercent, $priceIncVat ) {
				if ( $priceIncVat ) {
					return $price * 100;
				}

				return ( $price + ( $price * $vatPercent / 100 ) ) * 100;
			};

			$priceWithoutVAT = function ( $price, $vatPercent, $priceIncVat ) {
				if ( ! $priceIncVat ) {
					return $price * 100;
				}

				return ( $price / ( 1 + $vatPercent / 100 ) ) * 100;
			};

			foreach ( $ebi->EventBooking['OrderRows'] as $order_row ) {
				$cart_item = array();

				$cart_item['reference'] = $order_row['ItemNumber'];
				$cart_item['name']      = $order_row['Description'] . $rowExtraInfo;
				$cart_item['quantity']  = intval( $order_row['Quantity'] );
				$cart_item['unit']      = __( 'pcs', 'frontend', 'eduadmin-nets-easy-integration' );

				$grossTotalAmount = $priceWithVAT( $order_row['TotalPriceIncDiscount'], $order_row['VatPercent'], $order_row['PriceIncVat'] );
				$netTotalAmount   = $priceWithoutVAT( $order_row['TotalPriceIncDiscount'], $order_row['VatPercent'], $order_row['PriceIncVat'] );

				$cart_item['unitPrice'] = $priceWithoutVAT( $order_row['PricePerUnit'], $order_row['VatPercent'], $order_row['PriceIncVat'] );
				$cart_item['taxRate']   = intval( $order_row['VatPercent'] * 100 );

				$cart_item['grossTotalAmount'] = intval( $grossTotalAmount );
				$cart_item['netTotalAmount']   = intval( $netTotalAmount );

				$cart_item['taxAmount'] = intval( $grossTotalAmount - $netTotalAmount );

				$totalGrossTotalAmount += $grossTotalAmount;

				$netsOrder['order']['items'][] = $cart_item;
			}

			$netsOrder['order']['amount'] = $totalGrossTotalAmount;

			$netsOrder['checkout'] = array(
				'integrationType'             => 'embeddedCheckout',
				'charge'                      => true,
				'merchantHandlesConsumerData' => false,
				'url'                         => $completed_url,
				'returnUrl'                   => $completed_url,
				'termsUrl'                    => $terms_url,
				'consumerType'                => array(
					'supportedTypes' => array( 'B2B', 'B2C' ),
					'default'        => 'B2B'
				)
			);

			$c = curl_init( $checkout_url );
			curl_setopt( $c, CURLOPT_CUSTOMREQUEST, 'POST' );
			curl_setopt( $c, CURLOPT_POSTFIELDS, json_encode( $netsOrder ) );
			curl_setopt( $c, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $c, CURLOPT_HTTPHEADER, array(
				'Content-Type: application/json',
				'Accept: application/json',
				'Authorization: ' . str_replace( array(
					'live-secret-key-',
					'test-secret-key-'
				), '', strtolower( $secret_key ) )
			) );

			$checkout_result = curl_exec( $c );
			curl_close( $c );

			$payment = json_decode( $checkout_result );

			EDU()->session['netseasy-order-id'] = $payment->paymentId;
			EDU()->session['return-url']        = $completed_url;

			return $payment;
		}

		public function process_netsresponse() {
			if ( 'no' === $this->get_option( 'enabled', 'no' ) ) {
				return;
			}

			if ( ! empty( $_GET['paymentId'] ) && ! empty( $_GET['act'] ) && 'paymentCompleted' === $_GET['act'] && EDU()->session['netseasy-order-id'] == $_GET['paymentId'] ) {

				$booking_id = 0;
				if ( isset( $_GET['booking_id'] ) ) {
					$booking_id = intval( $_GET['booking_id'] );
				}

				$programme_booking_id = 0;
				if ( isset( $_GET['programme_booking_id'] ) ) {
					$programme_booking_id = intval( $_GET['programme_booking_id'] );
				}

				$test_mode = ! checked( $this->get_option( 'test_mode', 'no' ), '1', false );

				$checkout_url = $test_mode ?
					EDU_NetsEasy::NetsEasyLiveApiUrl :
					EDU_NetsEasy::NetsEasyTestApiUrl;

				$secret_key = $test_mode ?
					$this->get_option( 'live_secret_key', '' ) :
					$this->get_option( 'test_secret_key', '' );

				$c = curl_init( $checkout_url . '/' . $_GET['paymentId'] );
				curl_setopt( $c, CURLOPT_CUSTOMREQUEST, 'GET' );
				curl_setopt( $c, CURLOPT_RETURNTRANSFER, true );
				curl_setopt( $c, CURLOPT_HTTPHEADER, array(
					'Content-Type: application/json',
					'Accept: application/json',
					'Authorization: ' . str_replace( array(
						'live-secret-key-',
						'test-secret-key-'
					), '', strtolower( $secret_key ) )
				) );

				$checkout_result = curl_exec( $c );
				curl_close( $c );

				$payment = json_decode( $checkout_result );
				$booking = null;

				$reference_id = 0;

				if ( $booking_id > 0 ) {
					$booking = EDUAPI()->OData->Bookings->GetItem(
						$booking_id,
						null,
						null,
						false
					);

					$reference_id = $booking['BookingId'];
				}

				if ( $programme_booking_id > 0 ) {
					$booking = EDUAPI()->OData->ProgrammeBookings->GetItem(
						$programme_booking_id,
						null,
						null,
						false
					);

					$reference_id = $booking['ProgrammeBookingId'];
				}

				echo "<pre>" . print_r( $booking, true ) . "</pre>";
				if ( $booking != null && intval( $payment->payment->orderDetails->reference ) == intval( $reference_id ) ) {

				}

				echo "<pre>" . print_r( $payment, true ) . "</pre>";

				if ( 'paymentCompleted' === $_GET['act'] ) {
					if ( ! $test_mode ) {
						$patch_booking = new stdClass();

						$status = true;

						if ( isset( $_GET['paymentFailed'] ) && 'true' === $_GET['paymentFailed'] ) {
							$status = false;
						}

						$patch_booking->Paid = $status;

						// We're setting this as a Card Payment, so that our service in the background will remove it if it doesn't get paid in time (15 minute slot)
						$patch_booking->PaymentMethodId = 2;
						if ( $booking_id > 0 ) {
							EDUAPI()->REST->Booking->PatchBooking(
								$booking_id,
								$patch_booking
							);
						}

						if ( $programme_booking_id > 0 ) {
							EDUAPI()->REST->ProgrammeBooking->PatchBooking(
								$programme_booking_id,
								$patch_booking
							);
						}
					}

					EDU()->session['netseasy-order-id'] = null;
					EDU()->session['return-url']        = null;
				}
			}
		}

		public function init_form_fields() {
			$this->setting_fields = array(
				'enabled'           => array(
					'title'       => __( 'Enabled', 'eduadmin-nets-easy-integration' ),
					'type'        => 'checkbox',
					'description' => __( 'Enables/Disabled the integration with Nets Easy', 'eduadmin-nets-easy-integration' ),
					'default'     => 'no',
				),
				'live_secret_key'   => array(
					'title'       => __( 'Secret Key (Live)', 'eduadmin-nets-easy-integration' ),
					'type'        => 'text',
					'description' => __( 'The secret key (Live) to authenticate with Nets Easy', 'eduadmin-nets-easy-integration' ),
					'default'     => '',
				),
				'live_checkout_key' => array(
					'title'       => __( 'Checkout Key (Live)', 'eduadmin-nets-easy-integration' ),
					'type'        => 'text',
					'description' => __( 'The checkout key (Live) to use with Nets Easy', 'eduadmin-nets-easy-integration' ),
					'default'     => '',
				),
				'test_secret_key'   => array(
					'title'       => __( 'Secret Key (Test)', 'eduadmin-nets-easy-integration' ),
					'type'        => 'text',
					'description' => __( 'The secret key (Test) to authenticate with Nets Easy', 'eduadmin-nets-easy-integration' ),
					'default'     => '',
				),
				'test_checkout_key' => array(
					'title'       => __( 'Checkout Key (Test)', 'eduadmin-nets-easy-integration' ),
					'type'        => 'text',
					'description' => __( 'The checkout key (Test) to use with Nets Easy', 'eduadmin-nets-easy-integration' ),
					'default'     => '',
				),
				'termsurl'          => array(
					'title'       => __( 'Terms and Conditions URL', 'eduadmin-nets-easy-integration' ),
					'type'        => 'text',
					'description' => __( 'This URL is required for Nets Easy', 'eduadmin-nets-easy-integration' ),
					'default'     => '',
				),
				'test_mode'         => array(
					'title'       => __( 'Test mode', 'eduadmin-nets-easy-integration' ),
					'type'        => 'checkbox',
					'description' => __( 'Enables test mode, so you can test the integration', 'eduadmin-nets-easy-integration' ),
					'default'     => 'no',
				),
			);
		}

		public function process_paymentstatus() {
			if ( 'no' === $this->get_option( 'enabled', 'no' ) ) {
				return;
			}

			if ( ! empty( $_GET['paymentId'] ) && ! empty( $_GET['status'] ) ) {

				$booking_id = 0;
				if ( isset( $_GET['booking_id'] ) ) {
					$booking_id = intval( $_GET['booking_id'] );
				}

				$programme_booking_id = 0;
				if ( isset( $_GET['programme_booking_id'] ) ) {
					$programme_booking_id = intval( $_GET['programme_booking_id'] );
				}

				$test_mode = ! checked( $this->get_option( 'test_mode', 'no' ), '1', false );

				$checkout_url = $test_mode ?
					EDU_NetsEasy::NetsEasyLiveApiUrl :
					EDU_NetsEasy::NetsEasyTestApiUrl;

				$secret_key = $test_mode ?
					$this->get_option( 'live_secret_key', '' ) :
					$this->get_option( 'test_secret_key', '' );
			}
		}
	}
}
