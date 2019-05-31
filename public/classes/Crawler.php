<?php declare(strict_types=1);

class Crawler {

    /**
     * @param string $uri
     *
     * @return array
     * @throws Exception
     */
    public static function get(string $uri): array {
        try {
            $curl = curl_init();

            curl_setopt_array($curl, [
                CURLOPT_URL            => $uri,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($curl);
            curl_close($curl);

            return is_string($response) ? json_decode($response, true) : [];
        } catch(Exception $e) {
            die($e->getMessage());
        }
    }
}
