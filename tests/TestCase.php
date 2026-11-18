<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $connection = $app['config']->get('database.default');
        $database = $app['config']->get('database.connections.mysql.database');
        if ($connection !== 'mysql' || $database !== 'easy_gold_feedback_test') {
            throw new \RuntimeException('Tests require the dedicated MySQL database easy_gold_feedback_test.');
        }

        return $app;
    }
}
