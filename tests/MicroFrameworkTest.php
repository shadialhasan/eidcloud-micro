<?php

declare(strict_types=1);

namespace EidCloud\Micro\Tests;

use EidCloud\Micro\App;
use EidCloud\Micro\Container;
use EidCloud\Micro\Middleware\CorsMiddleware;
use EidCloud\Micro\Middleware\ExecutionTimerMiddleware;
use EidCloud\Micro\Middleware\JsonValidatorMiddleware;
use EidCloud\Micro\MiddlewareInterface;
use EidCloud\Micro\Request;
use EidCloud\Micro\Response;
use EidCloud\Micro\RouteMatch;
use EidCloud\Micro\Router;
use RuntimeException;

/**
 * Complete zero-dependency unit and integration test suite for EidCloud Micro.
 */
class MicroFrameworkTest
{
    /** @var list<string> */
    public array $logs = [];

    // Helper assertions
    private function assertTrue(bool $condition, string $message = ''): void
    {
        if (!$condition) {
            throw new RuntimeException($message ?: 'Failed asserting that condition is TRUE.');
        }
    }

    private function assertFalse(bool $condition, string $message = ''): void
    {
        if ($condition) {
            throw new RuntimeException($message ?: 'Failed asserting that condition is FALSE.');
        }
    }

    private function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $expStr = var_export($expected, true);
            $actStr = var_export($actual, true);
            throw new RuntimeException($message ?: "Failed asserting that {$actStr} matches expected {$expStr}.");
        }
    }

    private function assertContains(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new RuntimeException($message ?: "Failed asserting that '{$haystack}' contains '{$needle}'.");
        }
    }

    private function assertArrayHasKey(string|int $key, array $array, string $message = ''): void
    {
        if (!array_key_exists($key, $array)) {
            throw new RuntimeException($message ?: "Failed asserting that array contains key '{$key}'.");
        }
    }

    // --- TEST SUITE METHODS ---

    public function testStaticRouteDispatch(): void
    {
        $router = new Router();
        $router->get('/health', fn() => 'OK');

        $match = $router->dispatch('GET', '/health');
        $this->assertTrue($match->isFound(), 'Static route should be found');
        $this->assertEquals([], $match->getParams(), 'Static route should have no params');

        $handler = $match->getHandler();
        $this->assertEquals('OK', $handler());
    }

    public function testDynamicRouteWithParameters(): void
    {
        $router = new Router();
        $router->get('/users/{id}', fn() => 'user');
        $router->get('/posts/{slug}/comments/{cid}', fn() => 'comment');

        // Match single param
        $match = $router->dispatch('GET', '/users/42');
        $this->assertTrue($match->isFound());
        $this->assertEquals(['id' => '42'], $match->getParams());

        // Match multiple params
        $match2 = $router->dispatch('GET', '/posts/hello-world/comments/99');
        $this->assertTrue($match2->isFound());
        $this->assertEquals(['slug' => 'hello-world', 'cid' => '99'], $match2->getParams());
    }

    public function testCustomRegexRouteParameters(): void
    {
        $router = new Router();
        $router->get('/orders/{id:[0-9]+}', fn() => 'numeric-order');
        $router->get('/orders/{code:[A-Z]{3}-\d+}', fn() => 'code-order');

        // Matches numeric
        $matchNum = $router->dispatch('GET', '/orders/12345');
        $this->assertTrue($matchNum->isFound());
        $this->assertEquals(['id' => '12345'], $matchNum->getParams());

        // Matches custom regex code
        $matchCode = $router->dispatch('GET', '/orders/SYR-2026');
        $this->assertTrue($matchCode->isFound());
        $this->assertEquals(['code' => 'SYR-2026'], $matchCode->getParams());

        // Fails non-matching pattern
        $matchInvalid = $router->dispatch('GET', '/orders/invalid_code_here');
        $this->assertTrue($matchInvalid->isNotFound());
    }

    public function testRouteGroupAndPrefix(): void
    {
        $router = new Router();
        $router->group('/api/v1', function (Router $r) {
            $r->get('/status', fn() => 'v1_status');
            $r->group('/auth', function (Router $r2) {
                $r2->post('/login', fn() => 'login');
            });
        });

        $m1 = $router->dispatch('GET', '/api/v1/status');
        $this->assertTrue($m1->isFound());

        $m2 = $router->dispatch('POST', '/api/v1/auth/login');
        $this->assertTrue($m2->isFound());

        $m3 = $router->dispatch('GET', '/status');
        $this->assertTrue($m3->isNotFound());
    }

    public function testHttpMethodsDispatch(): void
    {
        $router = new Router();
        $router->get('/item', fn() => 'GET');
        $router->post('/item', fn() => 'POST');
        $router->put('/item', fn() => 'PUT');
        $router->delete('/item', fn() => 'DELETE');
        $router->patch('/item', fn() => 'PATCH');

        $this->assertEquals('GET', ($router->dispatch('GET', '/item')->getHandler())());
        $this->assertEquals('POST', ($router->dispatch('POST', '/item')->getHandler())());
        $this->assertEquals('PUT', ($router->dispatch('PUT', '/item')->getHandler())());
        $this->assertEquals('DELETE', ($router->dispatch('DELETE', '/item')->getHandler())());
        $this->assertEquals('PATCH', ($router->dispatch('PATCH', '/item')->getHandler())());
    }

    public function testNotFound404(): void
    {
        $app = App::create();
        $app->get('/existing', fn() => 'here');

        $req = Request::create('GET', '/non-existent-path');
        $res = $app->handle($req);

        $this->assertEquals(404, $res->getStatusCode());
        $json = json_decode($res->getBody(), true);
        $this->assertEquals('Not Found', $json['error']);
    }

    public function testMethodNotAllowed405(): void
    {
        $app = App::create();
        $app->get('/resource', fn() => 'get-resource');
        $app->delete('/resource', fn() => 'delete-resource');

        $req = Request::create('POST', '/resource');
        $res = $app->handle($req);

        $this->assertEquals(405, $res->getStatusCode());
        $this->assertTrue($res->hasHeader('Allow'));
        $allowHeader = $res->getHeader('Allow');
        $this->assertContains('GET', (string) $allowHeader);
        $this->assertContains('DELETE', (string) $allowHeader);

        $json = json_decode($res->getBody(), true);
        $this->assertEquals('Method Not Allowed', $json['error']);
    }

    public function testRequestAbstraction(): void
    {
        $req = Request::create(
            method: 'POST',
            uri: '/test?page=2&sort=desc',
            headers: [
                'Authorization' => 'Bearer token123',
                'Content-Type' => 'application/json',
                'X-Custom' => 'hello',
            ],
            body: '{"name":"EidCloud","active":true}'
        );

        $this->assertEquals('POST', $req->getMethod());
        $this->assertEquals('/test', $req->getPath());
        $this->assertEquals('2', $req->query('page'));
        $this->assertEquals('desc', $req->query('sort'));
        $this->assertEquals('Bearer token123', $req->getHeader('authorization')); // case-insensitive
        $this->assertEquals('hello', $req->getHeader('x-custom'));
        $this->assertTrue($req->hasHeader('content-type'));
        $this->assertTrue($req->isJson());

        // JSON extraction
        $this->assertEquals('EidCloud', $req->json('name'));
        $this->assertEquals(true, $req->json('active'));
        $this->assertEquals('default_val', $req->json('missing', 'default_val'));
    }

    public function testResponseAbstraction(): void
    {
        $jsonRes = Response::json(['success' => true, 'count' => 10], 201, ['X-Test' => 'abc']);
        $this->assertEquals(201, $jsonRes->getStatusCode());
        $this->assertEquals('abc', $jsonRes->getHeader('X-Test'));
        $this->assertContains('application/json', (string) $jsonRes->getHeader('Content-Type'));
        $this->assertEquals('{"success":true,"count":10}', $jsonRes->getBody());

        $textRes = Response::text('Plain Text', 200);
        $this->assertEquals('Plain Text', $textRes->getBody());
        $this->assertContains('text/plain', (string) $textRes->getHeader('Content-Type'));

        $emptyRes = Response::empty(204);
        $this->assertEquals(204, $emptyRes->getStatusCode());
        $this->assertEquals('', $emptyRes->getBody());

        $redirectRes = Response::redirect('https://eidcloud.org', 301);
        $this->assertEquals(301, $redirectRes->getStatusCode());
        $this->assertEquals('https://eidcloud.org', $redirectRes->getHeader('Location'));
    }

    public function testOnionMiddlewareExecutionOrder(): void
    {
        $order = [];
        $app = App::create();

        // Middleware 1 (Outer)
        $app->add(function (Request $req, callable $next) use (&$order): Response {
            $order[] = 'M1_START';
            $res = $next($req);
            $order[] = 'M1_END';
            return $res->withHeader('X-M1', 'processed');
        });

        // Middleware 2 (Inner)
        $app->add(function (Request $req, callable $next) use (&$order): Response {
            $order[] = 'M2_START';
            $res = $next($req);
            $order[] = 'M2_END';
            return $res->withHeader('X-M2', 'processed');
        });

        $app->get('/flow', function () use (&$order) {
            $order[] = 'HANDLER';
            return ['status' => 'ok'];
        });

        $res = $app->handle(Request::create('GET', '/flow'));

        // Assert Onion execution order
        $expectedOrder = [
            'M1_START',
            'M2_START',
            'HANDLER',
            'M2_END',
            'M1_END',
        ];

        $this->assertEquals($expectedOrder, $order);
        $this->assertEquals('processed', $res->getHeader('X-M1'));
        $this->assertEquals('processed', $res->getHeader('X-M2'));
    }

    public function testRouteSpecificMiddleware(): void
    {
        $app = App::create();
        $adminCalled = false;

        $authMiddleware = function (Request $req, callable $next) use (&$adminCalled): Response {
            $adminCalled = true;
            return $next($req);
        };

        $app->get('/public', fn() => 'public');
        $app->get('/admin', fn() => 'admin')->add($authMiddleware);

        // Call public - admin middleware must not run
        $app->handle(Request::create('GET', '/public'));
        $this->assertFalse($adminCalled, 'Admin middleware should not run on public route');

        // Call admin - admin middleware must run
        $app->handle(Request::create('GET', '/admin'));
        $this->assertTrue($adminCalled, 'Admin middleware should run on admin route');
    }

    public function testCorsMiddleware(): void
    {
        $cors = new CorsMiddleware(origin: 'https://eidcloud.org', methods: 'GET, POST');

        // 1. Preflight OPTIONS request
        $optReq = Request::create('OPTIONS', '/api/data');
        $optRes = $cors->process($optReq, fn() => throw new RuntimeException('Should not reach next handler on OPTIONS'));

        $this->assertEquals(204, $optRes->getStatusCode());
        $this->assertEquals('https://eidcloud.org', $optRes->getHeader('Access-Control-Allow-Origin'));
        $this->assertEquals('GET, POST', $optRes->getHeader('Access-Control-Allow-Methods'));

        // 2. Normal GET request
        $getReq = Request::create('GET', '/api/data');
        $getRes = $cors->process($getReq, fn() => Response::json(['data' => 'secret']));
        $this->assertEquals(200, $getRes->getStatusCode());
        $this->assertEquals('https://eidcloud.org', $getRes->getHeader('Access-Control-Allow-Origin'));
    }

    public function testExecutionTimerMiddleware(): void
    {
        $timer = new ExecutionTimerMiddleware();
        $req = Request::create('GET', '/test');
        $res = $timer->process($req, function () {
            usleep(2000); // 2ms sleep
            return Response::text('done');
        });

        $this->assertTrue($res->hasHeader('X-Response-Time'));
        $val = $res->getHeader('X-Response-Time');
        $this->assertTrue((bool) preg_match('/^\d+\.\d+ms$/', (string) $val), "Header value '{$val}' should match X.XXms pattern");
    }

    public function testJsonValidatorMiddleware(): void
    {
        $validator = new JsonValidatorMiddleware();

        // 1. Invalid JSON body on POST -> 400
        $badReq = Request::create('POST', '/api/submit', [], '{malformed:json');
        $badRes = $validator->process($badReq, fn() => Response::text('ok'));
        $this->assertEquals(400, $badRes->getStatusCode());

        // 2. Valid JSON body on POST -> passes through
        $goodReq = Request::create('POST', '/api/submit', [], '{"valid": true}');
        $goodRes = $validator->process($goodReq, fn() => Response::text('ok'));
        $this->assertEquals(200, $goodRes->getStatusCode());
        $this->assertEquals('ok', $goodRes->getBody());
    }

    public function testContainerBindingsAndSingletons(): void
    {
        $container = new Container();

        // Standard factory binding
        $container->set('rand', fn() => random_int(1, 1000000));
        $val1 = $container->get('rand');
        $val2 = $container->get('rand');
        // Factory returns fresh values (unless coincidence)
        $container->set('counter', function () {
            static $c = 0;
            return ++$c;
        });
        $this->assertEquals(1, $container->get('counter'));
        $this->assertEquals(2, $container->get('counter'));

        // Singleton
        $container->singleton('single_counter', function () {
            static $sc = 0;
            return ++$sc;
        });
        $this->assertEquals(1, $container->get('single_counter'));
        $this->assertEquals(1, $container->get('single_counter'));
    }

    public function testContainerAutoWiring(): void
    {
        $container = new Container();
        $container->singleton('db_name', 'eidcloud_production');

        // Closure with named injection and default parameter
        $callable = function (string $db_name, int $limit = 50) {
            return "Connected to {$db_name} with limit {$limit}";
        };

        $result = $container->call($callable);
        $this->assertEquals('Connected to eidcloud_production with limit 50', $result);

        // Override default with provided parameters
        $resultCustom = $container->call($callable, ['limit' => 100]);
        $this->assertEquals('Connected to eidcloud_production with limit 100', $resultCustom);
    }

    public function testFullAppIntegration(): void
    {
        $app = App::create();
        $app->enableCors();
        $app->enableTimer();

        $app->getContainer()->singleton('config', ['appName' => 'EidCloud Service']);

        $app->group('/api', function (Router $router) use ($app) {
            $router->get('/config', function () use ($app) {
                return $app->getContainer()->get('config');
            });

            $router->get('/users/{id:[0-9]+}', function (string $id, Request $request) {
                return [
                    'id' => (int) $id,
                    'uri' => $request->getPath(),
                    'tag' => $request->query('tag', 'none'),
                ];
            });
        });

        // Request 1: GET /api/config
        $req1 = Request::create('GET', '/api/config');
        $res1 = $app->handle($req1);
        $this->assertEquals(200, $res1->getStatusCode());
        $this->assertTrue($res1->hasHeader('Access-Control-Allow-Origin'));
        $this->assertTrue($res1->hasHeader('X-Response-Time'));
        $this->assertEquals('{"appName":"EidCloud Service"}', $res1->getBody());

        // Request 2: GET /api/users/88?tag=vip
        $req2 = Request::create('GET', '/api/users/88?tag=vip');
        $res2 = $app->handle($req2);
        $this->assertEquals(200, $res2->getStatusCode());
        $data2 = json_decode($res2->getBody(), true);
        $this->assertEquals(88, $data2['id']);
        $this->assertEquals('/api/users/88', $data2['uri']);
        $this->assertEquals('vip', $data2['tag']);
    }
}
