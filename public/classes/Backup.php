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
    /** @var array $scrobblesPerDayTracker */
    private $scrobblesPerDayTracker = [];
    /** @var float $start */
    private $start;

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
     * @param array $params
     *
     * @throws Exception
     */
    public function savePage(array $params): void {
        $timestamp             = $params['timestamp'];
        $page                  = $params['page'];
        $isCurrentlyScrobbling = $params['isCurrentlyScrobbling'];
        $collectNowPlaying     = $params['collectNowPlaying'];

        $this->start = microtime(true);
        // carry over last known timestamp of the previous page
        if($timestamp !== NULL) {
            User::verifyExistence($this->uriBuilder);

            $this->currentDate = date('Y-m-d', $timestamp);
            $this->handle      = $this->getJSONHandle();
        }

        $pageURI  = $this->uriBuilder->getRecentTracks($page);
        $response = Crawler::get($pageURI);

        // occasional API hiccup
        if(isset($response['error']) || !isset($response['recenttracks'])) {
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
            header('Location: combine.php?user=' .$response['recenttracks']['@attr']['user']);
        }

        $isUnfinishedJSON = false;

        $scrobbleCount = count($scrobbles);

        foreach($scrobbles as $scrobble) {
            // skip 'nowplaying' tracks
            if(isset($scrobble['@attr'], $scrobble['@attr']['nowplaying'])) {
                --$scrobbleCount;
                $isCurrentlyScrobbling = true;
                continue;
            }

            $scrobble  = $this->extractScrobbleData($scrobble);
            $timestamp = $scrobble['timestamp'];
            $trackDate = date('Y-m-d', $timestamp);

            if(!isset($this->scrobblesPerDayTracker[$trackDate])) {
                $this->scrobblesPerDayTracker[$trackDate] = 1;
            } else {
                ++$this->scrobblesPerDayTracker[$trackDate];
            }

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
                --$scrobbleCount;
                continue;
            }

            // append the JSON; if its a new file, prepend with [, else prepend it with ,
            fwrite($this->handle, $praefix . json_encode($scrobble));
        }

        if($isUnfinishedJSON) {
            $this->finishJSON();
        }

        if($collectNowPlaying || $scrobbleCount === 0) {
            header('Location: combine.php?user=' .$response['recenttracks']['@attr']['user']);
        }

        $newParams = [
            'page'       => $page + 1,
            'totalPages' => $response['recenttracks']['@attr']['totalPages'],
            'timestamp'  => $timestamp,
        ];

        // carry the flag across all pages
        if($isCurrentlyScrobbling) {
            $newParams['isCurrentlyScrobbling'] = 1;
        }

        $this->continue($newParams);
    }

    private function continue(array $newParams): void {
        $executionTime  = microtime(true) - $this->start;
        $currentPage    = $newParams['page'] - 1;
        $remainingPages = $newParams['totalPages'] - $currentPage;

        $percentDone   = round(($currentPage / $newParams['totalPages']) * 100, 2);
        $remainingTime = round($executionTime * $remainingPages / 60, 2);
        ?>
        <style>
            .has-text-right {
                text-align: right;
            }

            .has-text-centered {
                text-align: center;
            }

            table {
                border-collapse: collapse;
                width: 33%;
            }

            tr:nth-of-type(even) {
                background-color: lightgrey;
            }
        </style>
        <table>
            <thead>
            <tr>
                <th>Progress</th>
                <th>Done in ~ (min)</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td class="has-text-centered"><?= $percentDone ?>%</td>
                <td class="has-text-centered"><?= $remainingTime ?></td>
            </tr>
            </tbody>
            <thead>
            <tr>
                <th>Date</th>
                <th>Scrobble count</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach($this->scrobblesPerDayTracker as $date => $scrobbles) { ?>
                <tr>
                    <td><?= $date ?></td>
                    <td class="has-text-right"><?= number_format($scrobbles) ?></td>
                </tr>
            <?php } ?>
            </tbody>
            <tfoot>
            <tr>
                <td><?= count($this->scrobblesPerDayTracker) ?> days</td>
                <td class="has-text-right"><?= array_sum(array_values($this->scrobblesPerDayTracker)) ?></td>
            </tr>
            </tfoot>
        </table>
        <!-- hack to circumvent Chrome Too Many Redirects warning -->
        <script>
          setTimeout(() => (location.href = "index.php?<?= http_build_query($newParams) ?>"), 150);
        </script>
        <?php
    }

    /**
     * Ends the current handle, reopens it, sorts it by timestamp ASC and places the new contents back into the file
     */
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
            $json            = json_decode($previousContent, true);

            // if contents are valid array, remove array ending to allow appending
            if($json !== NULL) {
                $this->mostRecentScrobble = (int) end($json)['timestamp'];

                $handle = fopen($jsonPath, 'wb+');
                fwrite($handle, substr($previousContent, 0, -1));
            } else {
                $handle = fopen($jsonPath, 'ab');
            }
        } else {
            $handle = fopen($jsonPath, 'wb+');
        }

        if(is_resource($handle)) {
            return $handle;
        }

        die('Couldn\'t create handle for ' . $jsonPath);
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
