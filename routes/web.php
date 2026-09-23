<?php

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use App\Jobs\TestRedisJob;

Route::get('/queue-test', function () {
    TestRedisJob::dispatch();

    return 'Job enviado a la cola';
});

Route::get('/', function () {
    return view('welcome');
});