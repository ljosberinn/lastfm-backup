<?php /** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types=1);

class Backup {

    /** @var UriBuilder $uriBuilder */
    private $uriBuilder;

    /** @var string $backupPath */
    private $backupPath;

    /** @var string DIRECTORY_DELIMITER */
    private const DIRECTORY_DELIMITER = '/';

    /** @var string $currentDate */
    private $currentDate;
    /** @var resource $handle */
    private $handle;
    /** @var int $mostRecentScrobble */
    private $mostRecentScrobble;

    /**
     * @param array $secrets [the contents of secrets.php]
     *
     * @throws Exception
     */
    public function __construct(array $secrets) {
        $this->uriBuilder = new UriBuilder($secrets);
        $this->backupPath = dirname(__DIR__, 2) . '/backup';
    }

    /**
     * @param int      $page                       [the currently iterated page]
     * @param bool     $isCurrentlyScrobbling      [flag to indicate the user was scrobbling at start]
     * @param int|NULL $timestamp                  [timestamp to carry over JSON access indirectly]
     * @param bool     $collectNowPlayingFromStart [flag to indicate the import is done, but user was scrobbling at start]
     *
     * @throws Exception
     */
    public function savePage(int $page, bool $isCurrentlyScrobbling = false, int $timestamp = NULL, bool $collectNowPlayingFromStart = false): void {
        // carry over last known timestamp of the previous page
        if($timestamp !== NULL) {
            User::verifyExistence($this->uriBuilder);

            $this->currentDate = date('Y-m-d', $timestamp);
            $this->handle      = $this->getJSONHandle();
        }

        $pageURI  = $this->uriBuilder->getRecentTracks($page);
        $response = Crawler::get($pageURI);

        // occasional API hickup
        if(!isset($response['recenttracks'])) {
            sleep(2);
            header('Location: index.php?' . http_build_query($_GET));
        }

        $scrobbles = $response['recenttracks']['track'];

        // reached the end of all pages
        if(empty($scrobbles)) {
            if($isCurrentlyScrobbling) {
                header('Location: index.php?page=1&collectNowPlayingFromStart');
            }

            // finish off last JSON
            $this->finishJSON();
            die('Done.');
        }

        $isUnfinishedJSON = false;
        $scrobbleCount    = count($scrobbles);
        $skippedTracks    = 0;

        foreach($scrobbles as $scrobble) {
            // skip 'nowplaying' tracks
            if(isset($scrobble['@attr'], $scrobble['@attr']['nowplaying'])) {
                $isCurrentlyScrobbling = true;
                continue;
            }

            $scrobble  = $this->extractScrobbleData($scrobble);
            $timestamp = $scrobble['timestamp'];
            $trackDate = date('Y-m-d', $timestamp);

            $praefix = ',';

            // only revalidate file if this scrobbles timestamp is on another day
            // e.g. 2019-05-30 !== null means its the first scrobble in general
            // e.g. 2019-05-30 !== 2019-05-31 means its another day, another JSON
            if($trackDate !== $this->currentDate) {
                // finish off last JSON if there is one because first scrobble doesn't have a handle yet
                if($this->currentDate !== NULL) {
                    $isUnfinishedJSON = false;
                    $this->finishJSON();
                }
                // and prepare new
                $this->currentDate = $trackDate;
                $this->handle      = $this->getJSONHandle();
                if($this->mostRecentScrobble === NULL) {
                    $praefix = '[';
                }
            }

            // if mostRecentScrobble is set, we're appending to an existing JSON and can ignore previous scrobbles
            if($this->mostRecentScrobble !== NULL && $timestamp <= $this->mostRecentScrobble) {
                $isUnfinishedJSON = true;
                ++$skippedTracks;
                continue;
            }

            // append the JSON; if its a new file, prepend with [, else prepend it with ,
            fwrite($this->handle, $praefix . json_encode($scrobble));
        }

        if($isUnfinishedJSON) {
            $this->finishJSON();
        }

        if($collectNowPlayingFromStart || $skippedTracks === $scrobbleCount) {
            die('Done.');
        }

        $newParams = [
            'page'      => $page + 1,
            'timestamp' => $timestamp,
        ];

        // carry the flag across all pages
        if($isCurrentlyScrobbling) {
            $newParams['isCurrentlyScrobbling'] = 1;
        }

        // hack to circumvent Chrome Too Many Redirects warning
        echo 'Added ' . ($scrobbleCount - $skippedTracks) . ' tracks, next page incoming.
        <script>
        setTimeout(() => (location.href = "index.php?' . http_build_query($newParams) . '"), 150);
        </script>';
    }

    private function finishJSON(): void {
        fwrite($this->handle, ']');
        fclose($this->handle);

        // reverse the contents to have the JSON be ordered by timestamp ASC which also enables the script to append new content later on easier
        $jsonName = implode(self::DIRECTORY_DELIMITER, [$this->backupPath, $this->currentDate . '.json']);
        $content  = json_decode(file_get_contents($jsonName), true);

        usort($content, static function($a, $b) {
            return $a['timestamp'] <=> $b['timestamp'];
        });

        $reversalHandle = fopen($jsonName, 'wb');
        fwrite($reversalHandle, json_encode($content));
        fclose($reversalHandle);
        $this->mostRecentScrobble = NULL;
    }

    /**
     * @return resource
     */
    private function getJSONHandle() {
        $jsonName = $this->currentDate . '.json';
        $jsonPath = implode(self::DIRECTORY_DELIMITER, [$this->backupPath, $jsonName]);

        // if file exists, we only want to append
        // really dirty way to allow 'wb+' to work because we can't just append to the already finished JSON
        // only really relevant in case someone scrobbles more than 200 songs/day
        // or imports as I did, since imported songs show up as having being played on January 1st 1970
        if(file_exists($jsonPath)) {
            $previousContent = file_get_contents($jsonPath);

            // remove JSON ending [ if it exists
            // if it doesnt exist, it means we're accessing the JSON for the third time:
            // 1st time: regular creation
            // 2nd time: removal of ], appending
            // 3rd time: no ] existent, file wasn't finished in previous iteration,
            // thus trackTimestamp still hasn't indicated another day
            $bracketPos = strpos($previousContent, ']');
            if($bracketPos !== false) {
                $json                     = json_decode($previousContent, true);
                $this->mostRecentScrobble = (int) end($json)['timestamp'];

                $handle = fopen($jsonPath, 'wb+');

                fwrite($handle, str_replace(']', '', $previousContent));
            } else {
                $handle = fopen($jsonPath, 'ab');
            }
        } else {
            $handle = fopen($jsonPath, 'wb+');
        }

        if(is_resource($handle)) {
            return $handle;
        }

        die('Couldn\'t open handle for ' . $jsonPath);
    }

    /**
     * Extracts relevant metadata; cover URI appear to be exclusively hosted on said server; reducing JSON size by cutting it down
     *
     * @param array $track [currently iterated track]
     *
     * @return array
     */
    private function extractScrobbleData(array $track): array {
        return [
            'timestamp' => (int) $track['date']['uts'],
            'album'     => $track['album']['#text'],
            'artist'    => $track['artist']['#text'],
            'title'     => $track['name'],
            'cover'     => str_replace('https://lastfm-img2.akamaized.net/i/u/34s/', '', $track['image'][0]['#text']),
        ];
    }
}
