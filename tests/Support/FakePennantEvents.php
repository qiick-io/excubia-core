<?php

declare(strict_types=1);

/*
 * Stand-ins for Laravel Pennant's events (laravel/pennant 1.x) (#225),
 * under their real class names and with their public properties
 * (src/Events): `FeatureRetrieved` and `FeatureResolved` carry the feature
 * name, the scope and the value, `UnknownFeatureResolved` the name and the
 * scope. Pennant's decorator fires them through Laravel's dispatcher.
 * Pennant is not a dependency, so the tests fake its events instead of
 * installing it. Loaded with require_once by the Pennant tests only; once
 * loaded, `class_exists()` sees Pennant as installed for the rest of the
 * run, which changes nothing while `runtime.pennant` is off.
 */

namespace Laravel\Pennant\Events {
    class FeatureRetrieved
    {
        /**
         * @param  string  $feature
         * @param  mixed  $scope
         * @param  mixed  $value
         */
        public function __construct(public $feature, public $scope, public $value) {}
    }

    class FeatureResolved
    {
        /**
         * @param  string  $feature
         * @param  mixed  $scope
         * @param  mixed  $value
         */
        public function __construct(public $feature, public $scope, public $value) {}
    }

    class UnknownFeatureResolved
    {
        /**
         * @param  string  $feature
         * @param  mixed  $scope
         */
        public function __construct(public $feature, public $scope) {}
    }
}
