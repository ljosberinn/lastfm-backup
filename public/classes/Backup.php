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
     * @param bool     $hasNowPlayingOnStart       [flag to indicate the user was scrobbling at start]
     * @param int|NULL $timestamp                  [timestamp to carry over JSON access indirectly]
     * @param bool     $collectNowPlayingFromStart [flag to indicate the import is done, but user was scrobbling at start]
     *
     * @throws Exception
     */
    public function savePage(int $page, bool $hasNowPlayingOnStart = false, int $timestamp = NULL, bool $collectNowPlayingFromStart = false): void {
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

        $tracks = $response['recenttracks']['track'];

        // reached the end of all pages
        if(empty($tracks)) {
            if($hasNowPlayingOnStart) {
                header('Location: index.php?page=1&collectNowPlayingFromStart');
            }

            // finish off last JSON
            $this->finishJSON();
            die('Done.');
        }

        foreach($tracks as $track) {
            // skip 'nowplaying' tracks
            if(isset($track['@attr'], $track['@attr']['nowplaying'])) {
                $hasNowPlayingOnStart = true;
                continue;
            }

            $track = $this->extractTrackData($track);

            $timestamp = $track['timestamp'];
            $trackDate = date('Y-m-d', $timestamp);

            $praefix = ',';

            // only revalidate file if this tracks timestamp is on another day
            // e.g. 2019-05-30 !== null means its the first scrobble in general
            // e.g. 2019-05-30 !== 2019-05-31 means its another day, another JSON
            if($trackDate !== $this->currentDate) {
                // finish off last JSON if there is one because first track doesn't have a handle yet
                if($this->currentDate !== NULL) {
                    $this->finishJSON();
                }
                // and prepare new
                $this->currentDate = $trackDate;
                $this->handle      = $this->getJSONHandle();
                $praefix           = '[';
            }

            // append the JSON; if its a new file, prepend with [, else prepend it with ,
            fwrite($this->handle, $praefix . json_encode($track));
        }

        if($collectNowPlayingFromStart) {
            die('Done.');
        }

        $newParams = [
            'page'      => $page + 1,
            'timestamp' => $timestamp,
        ];

        // carry the flag across all pages
        if($hasNowPlayingOnStart) {
            $newParams['hasNowPlayingOnStart'] = 1;
        }

        // hack to cirvumvent Chrome Too Many Redirects warning
        echo 'Added ' . count($tracks) . ' tracks, next page incoming.
        <script>
        setTimeout(() => (location.href = "index.php?' . http_build_query($newParams) . '"), 150);
        </script>';
    }

    private function finishJSON(): void {
        fwrite($this->handle, ']');
        fclose($this->handle);
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
            // 3rd time: no ] existant, file wasn't finished in previous iteration,
            // thus trackTimestamp still hasn't indicated another day
            $bracketPos = strpos($previousContent, ']');
            if($bracketPos !== false) {
                $handle = fopen($jsonPath, 'wb+');

                fwrite($handle, str_replace(']', '', $previousContent));
                return $handle;
            }

            return fopen($jsonPath, 'ab');
        }

        return fopen($jsonPath, 'wb+');
    }

    /**
     * Extracts relevant metadata; cover URI appear to be exclusively hosted on said server; reducing JSON size by cutting it down
     *
     * @param array $track [currently iterated track]
     *
     * @return array
     */
    private function extractTrackData(array $track): array {
        return [
            'timestamp' => (int) $track['date']['uts'],
            'album'     => $track['album']['#text'],
            'artist'    => $track['artist']['#text'],
            'title'     => $track['name'],
            'cover'     => str_replace('https://lastfm-img2.akamaized.net/i/u/34s/', '', $track['image'][0]['#text']),
        ];
    }
}
