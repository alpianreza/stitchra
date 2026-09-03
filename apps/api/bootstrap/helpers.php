<?php

/*
 * Compatibility helpers.
 *
 * Laravel 13 no longer ships the global fake() helper that older factories
 * and tests rely on. Keep the original behaviour behind function_exists so
 * the app works with any framework patch release.
 */

use Faker\Factory as FakerFactory;
use Faker\Generator as FakerGenerator;

if (! function_exists('fake')) {
    /**
     * Return a Faker generator for the given (or configured) locale.
     */
    function fake(?string $locale = null): FakerGenerator
    {
        static $generators = [];

        $locale = $locale ?? (function_exists('config') ? config('app.faker_locale') : null) ?? 'en_US';

        return $generators[$locale] ??= FakerFactory::create($locale);
    }
}
