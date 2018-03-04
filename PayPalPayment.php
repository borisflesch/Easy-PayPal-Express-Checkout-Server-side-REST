<?php
class PayPalPayment {

	protected $sandbox_mode,
		  $client_id,
		  $client_secret,
		  $access_token;


	public function __construct() {
		$this->sandbox_mode = 1;
		$this->client_id = "";
		$this->client_secret = "";
		$this->access_token = "";
	}

	/**
	 * Définit le mode Sandbox / Live du paiement : 1 (ou true) pour le mode Sandbox, 0 (ou false) pour le mode Live
	 */
	public function setSandboxMode($mode) {
		$this->sandbox_mode = ($mode) ? true : false;
	}

	/**
	 * Définit le Client ID à utiliser (à récupérer dans les Credentials PayPal)
	 */
	public function setClientID($clientid) {
		$this->client_id = $clientid;
	}

	/**
	 * Définit le Secret à utiliser (à récupérer dans les Credentials PayPal)
	 */
	public function setSecret($secret) {
		$this->client_secret = $secret;
	}

	/**
	 * Génère un access token depuis l'API PayPal et le stock en variable de session
	 * Renvoie l'access token généré si réussi sinon false
	 * (Pour communiquer avec l'API PayPal, il est obligatoire de s'authentifier à l'aide de ce "Access Token" qui est généré à partir des Credentials : Client ID et Secret)
	 */
	public function generateAccessToken() {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		if ($this->sandbox_mode) {
			curl_setopt($ch, CURLOPT_URL, "https://api.sandbox.paypal.com/v1/oauth2/token");  //DUMMY
		} else {
			curl_setopt($ch, CURLOPT_URL, "https://api.paypal.com/v1/oauth2/token");  //LIVE
		}
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=client_credentials");
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_USERPWD, $this->client_id . ":" . $this->client_secret);

		$headers = array();
		$headers[] = "Accept: application/json";
		$headers[] = "Accept-Language: en_US";
		$headers[] = "Content-Type: application/x-www-form-urlencoded";
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		$result = curl_exec($ch);
		$data = json_decode($result);
		curl_close ($ch);

		$access_token = $data->access_token;

		// Récupérer le nombre de secondes avant expiration :
		$timestamp_expiration = intval($data->expires_in) - 120; // Timestamp donné -2 minutes (marge supplémentaire)

		// Création des variables de session avec expiration_date et access_token
		$_SESSION['paypal_token'] = [];
		$_SESSION['paypal_token']['access_token'] = $access_token;
		$_SESSION['paypal_token']['expiration_timestamp'] = time() + $timestamp_expiration;


		if ($access_token) {
			return $access_token;
		} else {
			return false;
		}
	}

	/**
	 * Renvoie un access token (demande à en générer un nouveau si besoin)
	 */
	public function getAccessToken() {
		if ($this->access_token) {
			return $this->access_token;
		} else {
			$access_token = "";
			if (!empty($_SESSION['paypal_token'])) {
				// Vérifier si le token n'a pas expiré
				if (time() <= $_SESSION['paypal_token']['expiration_timestamp']) {
					if (!empty($_SESSION['paypal_token']['access_token'])) {
						$access_token = $_SESSION['paypal_token']['access_token'];
					}
				}
			}

			// Si l'access_token renvoyé est vide, on en génère un nouveau
			if (!$access_token) {
				$access_token = $this->generateAccessToken();
			}

			return $access_token;
		}
	}

	/**
	 * Crée le paiement via l'API PayPal et renvoie la réponse du serveur PayPal
	 */
	public function createPayment($payment_data) {
		/* Exemple de format pour le paramètre $payment_data à passer :
		$payment_data = [
			"intent" => "sale",
			"redirect_urls" => [
				"return_url" => "http://localhost/",
				"cancel_url" => "http://localhost/"
			],
			"payer" => [
				"payment_method" => "paypal"
			],
			"transactions" => [
				[
					"amount" => [
						"total" => "Montant total de la transaction",
						"currency" => "EUR" // USD, CAD, etc.
					],
					"item_list" => [
						"items" => [
							[
								"quantity" => "1",
								"sku" => "Code de l'item"
								"name" => "Nom de l'item",
								"price" => "xx.xx",
								"currency" => "EUR"
							]
						]
					],
					"description" => "Description du paiement..."
				]
			]
		];
		*/

		$authorization = "Authorization: Bearer ".$this->getAccessToken();

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		if ($this->sandbox_mode) {
			curl_setopt($ch, CURLOPT_URL, "https://api.sandbox.paypal.com/v1/payments/payment");
		} else {
			curl_setopt($ch, CURLOPT_URL, "https://api.paypal.com/v1/payments/payment");
		}
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json' , $authorization ));
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payment_data));

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$server_output = curl_exec ($ch);
		curl_close ($ch);

		return $server_output;
	}

	/**
	 * Exécute un paiement via l'API PayPal et renvoie la réponse de PayPal
	 */
	public function executePayment($paymentID, $payerID) {
		if ($this->sandbox_mode) {
			$paypal_url = "https://api.sandbox.paypal.com/v1/payments/payment/".$paymentID."/execute/";
		} else {
			$paypal_url = "https://api.paypal.com/v1/payments/payment/".$paymentID."/execute/";
		}
		$authorization = "Authorization: Bearer ".$this->getAccessToken();

		$data = ["payer_id" => $payerID];

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($ch, CURLOPT_URL, $paypal_url);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json' , $authorization ));
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$server_output = curl_exec ($ch);
		curl_close ($ch);

		return $server_output;
	}
}
