<?php

declare(strict_types=1);

/*
 * Stand-ins for Laravel Sanctum (laravel/sanctum 4.x) and Laravel Passport
 * (laravel/passport 13.x) (#219), under their real class names: Sanctum's
 * `Sanctum` class and `TokenAuthenticated` event with its public `token`,
 * and Passport's `Passport` class with its public lifetime and key path
 * properties and `keyPath()`, which resolves a key file as the real one does
 * (src/Passport.php). Their tables are created by the migrations under
 * tests/Fixtures/Migrations, copied from both packages. Neither package is a
 * dependency, so the tests fake them instead of installing them. Loaded with
 * require_once by the API token tests only; once loaded, `class_exists()`
 * sees both as installed for the rest of the run, which changes nothing
 * while `runtime.auth` is off and the app has no `sanctum` or `passport`
 * configuration.
 */

namespace Laravel\Sanctum {
    class Sanctum
    {
        /** @var string */
        public static $personalAccessTokenModel = 'Laravel\\Sanctum\\PersonalAccessToken';
    }
}

namespace Laravel\Sanctum\Events {
    class TokenAuthenticated
    {
        /**
         * @param  object  $token  the personal access token model
         */
        public function __construct(public $token) {}
    }
}

namespace Laravel\Passport {
    use DateInterval;

    class Passport
    {
        public static ?DateInterval $tokensExpireIn = null;

        public static ?DateInterval $refreshTokensExpireIn = null;

        public static ?DateInterval $personalAccessTokensExpireIn = null;

        /** @var string|null */
        public static $keyPath;

        public static function keyPath(string $file): string
        {
            $file = ltrim($file, '/\\');

            return isset(static::$keyPath)
                ? rtrim(static::$keyPath, '/\\').DIRECTORY_SEPARATOR.$file
                : storage_path($file);
        }
    }
}
