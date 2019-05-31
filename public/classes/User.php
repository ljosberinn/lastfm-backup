<?php declare(strict_types=1);

class User {

    /**
     * @param URIBuilder $URIBuilder
     *
     * @return bool
     * @throws Exception
     */
    public static function verifyExistence(URIBuilder $URIBuilder): bool {
        $userInfo = Crawler::get($URIBuilder->getUserInfoUri());

        if(isset($userInfo['error'])) {
            die(ErrorHandler::handle((int) $userInfo['error']));
        }

        return true;
    }
}
