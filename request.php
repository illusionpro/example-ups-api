<?php

namespace App\Services;

use App\Models\Agency;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class UPSService
{
    
    public function loginAndGetAccessToken()
    {
        $clientId = env('UPS_CLIENT_ID');
        $clientSecret = env('UPS_CLIENT_SECRET');
        $merchantId = "";

        $client = new Client();

        $payload = [
            "grant_type" => "client_credentials",
        ];

        $headers = [
            "Content-Type" => "application/x-www-form-urlencoded",
            "x-merchant-id" => $merchantId,
            "Authorization" => "Basic " . base64_encode("$clientId:$clientSecret"),
        ];

        try {

            $response = $client->post('https://wwwcie.ups.com/security/v1/oauth/token', [
                'headers' => $headers,
                'form_params' => $payload,
            ]);

            $data = $response->getBody()->getContents();
            $response = json_decode($data, true);

            /// rad time expired
            $expiresInSeconds = $response['expires_in'];
            $issuedAt = $response['issued_at'];
            $expiresAt = $issuedAt + ($expiresInSeconds * 1000);

            // Save data token i cache
            Cache::put('access_token', [
                'token' => $response['access_token'],
                'expires_at' => $expiresAt,
            ], now()->addSeconds($expiresInSeconds));

        } catch (\Exception $e) {
            // Obsługa błędu, jeśli wystąpi
            echo "Error: " . $e->getMessage();
        }
    
    }

    public function checkToken()
    {
        // Read Token From Cache
        $tokenData = Cache::get('access_token');

        // check is valid token and time expired
        if (!$tokenData || !$this->isTokenValid($tokenData['expires_at'])) {
            $this->loginAndGetAccessToken(); 
        }

    }

    public function isTokenValid($expiresAt)
    {
        // Pobierz aktualny czas serwera w milisekundach
        $currentTime = round(microtime(true) * 1000);

        // Sprawdź, czy token jest jeszcze ważny
        return $currentTime < $expiresAt;
    }

    public function createShipment($data)
    {
        $receiver = $data->receiver;
        $version = "v1";
        $agency = Agency::find($data->agency_id);
        
        $requestData = [
            "ShipmentRequest" => [
                "Request" => [
                    "SubVersion" => "1601",
                    "RequestOption" => "nonvalidate",
                    "TransactionReference" => [
                        "CustomerContext" => $data->id
                    ]
                ],
                "Shipment" => [
                    "Description" => 'B_'.$data->id,
                    "ShipmentRatingOptions" => array(
                        "NegotiatedRatesIndicator" => "X"
                      ),
                    "ItemizedChargesRequestedIndicator" => "X",
                    "Shipper" => [
                        "Name" => $agency->company,
                        "AttentionName" => $agency->firstname.' '.$agency->lastname,
                        "Phone" => [
                            "Number" => $agency->phone,
                            "Extension" => " "
                        ],
                        "ShipperNumber" => env('UPS_ACCOUNT'),
                        "Address" => [
                            "AddressLine" => $agency->address,
                            "City" => $agency->city,
                            "StateProvinceCode" => $agency->state_code,
                            "PostalCode" => $agency->zipcode,
                            "CountryCode" => "US"
                        ]
                    ],
                    "ShipTo" => [
                        "Name" => $receiver->company,
                        "AttentionName" => $receiver->firstname . ' ' . $receiver->lastname,
                        "Phone" => [
                            "Number" => $receiver->phone
                        ],
                        "EMailAddress" => $receiver->email,
                        "Address" => [
                            "AddressLine" => $receiver->address,
                            "City" => $receiver->city,
                            "StateProvinceCode" => $receiver->state_code,
                            "PostalCode" => $receiver->zipcode,
                            "CountryCode" => $receiver->country_code
                        ],
                        "Residential" => " "
                    ],
                    "ShipFrom" => [
                        "Name" => $agency->company,
                        "AttentionName" => "",
                        "Phone" => [
                            "Number" => $agency->phone
                            //"Number" => "12345678"
                        ],
                        "EMailAddress" => $agency->email,
                        "Address" => [
                            "AddressLine" => $agency->address,
                            "City" => $agency->city,
                            "StateProvinceCode" => $agency->state_code,
                            "PostalCode" => $agency->zipcode,
                            "CountryCode" => $agency->country_code
                        ],
                    ],
                    "PaymentInformation" => [
                        "ShipmentCharge" => [
                            "Type" => "01",
                            "BillShipper" => [
                                "AccountNumber" => env('UPS_ACCOUNT'),
                            ]
                        ]
                    ],
                    "Service" => [
                        "Code" => "03",
                        "Description" => "Ground"
                    ],
                    "Package" => [
                        "Description" => 'C_'.$data->id,// Inside parcel number
                        "Packaging" => [
                            "Code" => "02",
                            "Description" => "Customer Supplied Package"
                        ],
                        "Dimensions" => [
                            "UnitOfMeasurement" => [
                                "Code" => "IN",
                                "Description" => "Inches"
                            ],
                            "Length" => $data->depth,
                            "Width" => $data->width,
                            "Height" => $data->height
                        ],
                        "PackageWeight" => [
                            "UnitOfMeasurement" => [
                                "Code" => "LBS",
                                "Description" => "Pounds"
                            ],
                            "Weight" => $data->weight
                        ]
                    ]
                ],
                "LabelSpecification" => [
                    "LabelImageFormat" => [
                        "Code" => "GIF",
                        "Description" => "GIF"
                    ],
                    "HTTPUserAgent" => "Mozilla/4.5"
                ]
            ]
        ];


        $this->checkToken();

        $accessToken = Cache::get('access_token');

        $headers = [
            "Authorization" => "Bearer " . $accessToken['token'],
            "Content-Type" => "application/json",
            "transactionSrc" => "testing"
        ];

        try {

            $query = [
                "additionaladdressvalidation" => false
            ];
            
            //$url = "https://onlinetools.ups.com/api/shipments/" . $version . "/ship?" . http_build_query($query);
            $url = "https://wwwcie.ups.com/api/shipments/" . $version . "/ship?" . http_build_query($query);

            $response = Http::withHeaders($headers)->post($url, $requestData);
            $responseData = json_decode($response, true);

            dd($responseData);
            //if($responseData["ShipmentResponse"]['Response']['ResponseStatus']['Code'] == 1):
            //    echo 'OK';
            //endif; 

            if ($response->status() != '200'):
                throw new \Exception($response->body());
            else:
                
                $parcel['trackingNumber'] = $responseData["ShipmentResponse"]["ShipmentResults"]["PackageResults"]['TrackingNumber'];
                $image = $responseData["ShipmentResponse"]["ShipmentResults"]["PackageResults"]["ShippingLabel"]["GraphicImage"];
                // Wygeneruj unikalną nazwę dla pliku, np. za pomocą UUID, aby uniknąć konfliktów nazw.
                $fileName = 'shipping_label_' . uniqid() . '.gif';
                
                Storage::disk('labels')->put($fileName, base64_decode($image));
                $parcel['label'] = $fileName;
                $parcel['ups_cost'] = $responseData["ShipmentResponse"]["ShipmentResults"]["ShipmentCharges"]["TotalCharges"]["MonetaryValue"];
                return $parcel;
            endif;

            
         
        } catch (RequestException $e) {
            // TODO: CREATE LOG and code response
            if ($response->failed()) {
                echo "FAILED Request failed. Response status code from UPS: " . $response->status();
            } else {
                //echo "Error: " . $e->getMessage();
            }
        }
    }

}
