<?php declare(strict_types=1);

class UriBuilder {

    private const URI_BASE = 'https://ws.audioscrobbler.com/2.0/?format=json&';

    /** @var string $apiKey [the key used to access the Last.fm API] */
    private $apiKey;

    /** @var string $user [the profile name to access] */
    private $user;

    /**
     * UriBuilder constructor.
     *
     * @param array $secrets
     */
    public function __construct(array $secrets) {
        if($this->isValidAPIKey($secrets['api-key'])) {
            $this->apiKey = $secrets['api-key'];
            $this->user   = $secrets['profile'];
        }
    }

    /**
     * @param string $apiKey
     *
     * @return bool
     */
    private function isValidAPIKey(string $apiKey): bool {
        return strlen($apiKey) === 32 && ctype_alnum($apiKey);
    }

    public function getUserInfoUri(): string {
        return self::URI_BASE . http_build_query([
                'method'  => 'user.getinfo',
                'api_key' => $this->apiKey,
                'user'    => $this->user,
            ]);
    }

    /**
     * @param int|NULL $page
     *
     * @return string
     */
    public function getRecentTracks($page = NULL): string {
        $params = [
            'method'  => 'user.getrecenttracks',
            'api_key' => $this->apiKey,
            'user'    => $this->user,
            'limit'   => 200,
        ];

        if($page !== NULL) {
            $params['page'] = $page;
        }

        return self::URI_BASE . http_build_query($params);
    }
}
