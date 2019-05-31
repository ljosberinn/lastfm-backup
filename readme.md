# what does this do?
crawls the entirety of a profiles scrobbles, puts them into `/backup`, named by scrobble day, e.g. `/backup/2019-05-30.json` will contain all scrobbles of May 30th 2019.

disclaimer: depending on your scrobble count, it _will_ take a while (~3800 scrobbles/min)

all timestamps are UTC

# setup

```bash
git clone https://github.com/ljosberinn/lastfm-backup
```

# how to use
- rename `/public/secrets.example.php` to `/public/secrets.php`
- fill out `/public/secrets.php`
- run `docker-start.bat` and access `localhost:8080`

# requirements
- docker & docker-compose
- php 7.1 or higher
- lastfm api account (can be requested [here](https://last.fm/api))
