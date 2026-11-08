<?php

declare(strict_types=1);

/*
 * Stand-ins for Laravel Scout (laravel/scout 11.x) (#221), under their real
 * class names: the `Scout` class with its two job class properties, the
 * `Searchable` trait's `search()` and queued sync calls as Scout writes
 * them (src/Searchable.php), the search `Builder` with its public `model`,
 * and the `MakeSearchable` and `RemoveFromSearch` jobs, which serialize
 * their models with `SerializesModels` and call the model's engine like the
 * real ones (src/Jobs). Scout is not a dependency, so the tests fake it
 * instead of installing it. Loaded with require_once by the Scout tests
 * only; once loaded, `class_exists()` sees Scout as installed for the rest
 * of the run, which changes nothing while `runtime.scout` is off.
 */

namespace Laravel\Scout {
    use Illuminate\Database\Eloquent\Collection;
    use Laravel\Scout\Jobs\MakeSearchable;
    use Laravel\Scout\Jobs\RemoveFromSearch;

    class Scout
    {
        /** @var class-string */
        public static $makeSearchableJob = MakeSearchable::class;

        /** @var class-string */
        public static $removeFromSearchJob = RemoveFromSearch::class;
    }

    class Builder
    {
        /**
         * @param  object  $model
         * @param  string  $query
         * @param  callable|null  $callback
         * @param  bool  $softDelete
         */
        public function __construct(public $model, public $query, public $callback = null, public $softDelete = false) {}

        /**
         * @return mixed
         */
        public function get()
        {
            return $this->model->searchableUsing()->search($this);
        }
    }

    trait Searchable
    {
        /**
         * @param  string  $query
         * @param  callable|null  $callback
         */
        public static function search($query = '', $callback = null): Builder
        {
            return app(Builder::class, [
                'model' => new static,
                'query' => $query,
                'callback' => $callback,
                'softDelete' => false,
            ]);
        }

        /**
         * @param  Collection<int, static>  $models
         */
        public function queueMakeSearchable($models): void
        {
            dispatch(new Scout::$makeSearchableJob($models));
        }

        /**
         * @param  Collection<int, static>  $models
         */
        public function queueRemoveFromSearch($models): void
        {
            dispatch(new Scout::$removeFromSearchJob($models));
        }
    }
}

namespace Laravel\Scout\Jobs {
    use Illuminate\Bus\Queueable;
    use Illuminate\Contracts\Queue\ShouldQueue;
    use Illuminate\Database\Eloquent\Collection;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Queue\SerializesModels;

    class MakeSearchable implements ShouldQueue
    {
        use Queueable, SerializesModels;

        /**
         * @param  Collection<int, Model>  $models
         */
        public function __construct(public $models) {}

        public function handle(): void
        {
            if ($this->models->isEmpty()) {
                return;
            }

            $this->models->first()->searchableUsing()->update($this->models);
        }
    }

    class MakeSearchableUniquely extends MakeSearchable {}

    class RemoveFromSearch implements ShouldQueue
    {
        use Queueable, SerializesModels;

        /**
         * @param  Collection<int, Model>  $models
         */
        public function __construct(public $models) {}

        public function handle(): void
        {
            if ($this->models->isNotEmpty()) {
                $this->models->first()->searchableUsing()->delete($this->models);
            }
        }
    }
}
