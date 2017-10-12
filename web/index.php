<?php
/**
 * MIT License
 *
 * Copyright (c) 2017 NocWorx
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace GithubWebhookJira;

require_once '../vendor/autoload.php';

use \Symfony\Component\HttpFoundation\Request;
use \Silex\Application;
use \Silex\Provider\MonologServiceProvider;
use \GithubWebhookJira\Webhook;

$app = new Application();
$app['debug'] = (getenv('DEBUG') === 'true');

// Register the monolog logging service
$app->register(new MonologServiceProvider(), array(
  'monolog.logfile' => 'php://stderr',
));

// Handle the post request
$app->post('/', function(Request $request) use ($app) {
  $hook = new Webhook($app, $request);
  if ($hook->isValid()) {
    $hook->process();
  }
  return 'Done';
});

$app->run();
