<?php

namespace App\Traits;


trait HasAPITrait
{
    private function getAPIData(string $url)
    {
        //init cURL session
        $ch = curl_init();

        //set cURL options
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects if any
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Set timeout
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Verify SSL

        //execute cURL request
        $response = curl_exec($ch);

        //check for cURL errors
        if(curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            return "Error fetching data from API: " . $error;
        }
        elseif(strlen($response) < 1) {
            return "Empty response";
        }

        //close cURL session
        curl_close($ch);

        //decode json response
        $response_decoded = json_decode($response, true);


        //check if json decoding was successful
        if(json_last_error() !== JSON_ERROR_NONE) {
            return "Error decoding JSON: " . json_last_error_msg();
        }

        return $response_decoded;
    }
}
