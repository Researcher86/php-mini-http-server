<?php

declare(strict_types=1);

namespace App\Tests\Router;

use App\Http\Protocol\HttpMethod;
use App\Http\Protocol\HttpVersion;
use App\Http\Request\HttpRequest;
use App\Http\Response\HttpResponse;
use App\Http\Response\HttpStatusCode;
use App\Http\Response\ResponseFactory;
use App\Router\RouteNotFoundException;
use App\Router\Router;
use App\Http\Headers\Headers;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    public function testDifferentUrlsExecuteDifferentHandlers(): void
    {
        $this->router->get('/hello', static fn (HttpRequest $r): HttpResponse => ResponseFactory::text('hi'));
        $this->router->get('/users', static fn (HttpRequest $r): HttpResponse => ResponseFactory::text('users'));

        $this->assertSame('hi', $this->router->dispatch($this->request('/hello'))->body);
        $this->assertSame('users', $this->router->dispatch($this->request('/users'))->body);
    }

    public function testMethodIsPartOfTheRoute(): void
    {
        $this->router->get('/users', static fn (HttpRequest $r): HttpResponse => ResponseFactory::text('read'));
        $this->router->post('/users', static fn (HttpRequest $r): HttpResponse => ResponseFactory::text('create'));

        $this->assertSame('read', $this->router->dispatch($this->request('/users', HttpMethod::GET))->body);
        $this->assertSame('create', $this->router->dispatch($this->request('/users', HttpMethod::POST))->body);
    }

    public function testDispatchPassesTheRequestToTheHandler(): void
    {
        $this->router->get('/echo', static fn (HttpRequest $r): HttpResponse => ResponseFactory::json([
            'method' => $r->method->value,
            'path' => $r->path(),
        ]));

        $response = $this->router->dispatch($this->request('/echo'));

        $this->assertSame('{"method":"GET","path":"/echo"}', $response->body);
    }

    public function testUnknownPathThrowsRouteNotFound(): void
    {
        $this->router->get('/hello', static fn (HttpRequest $r): HttpResponse => ResponseFactory::text('hi'));

        $this->expectException(RouteNotFoundException::class);
        $this->router->dispatch($this->request('/missing'));
    }

    public function testUnknownMethodOnKnownPathThrowsRouteNotFound(): void
    {
        $this->router->get('/hello', static fn (HttpRequest $r): HttpResponse => ResponseFactory::text('hi'));

        $this->expectException(RouteNotFoundException::class);
        $this->router->dispatch($this->request('/hello', HttpMethod::DELETE));
    }

    public function testQueryStringDoesNotAffectMatching(): void
    {
        $this->router->get('/search', static fn (HttpRequest $r): HttpResponse => ResponseFactory::text('ok'));

        $this->assertSame('ok', $this->router->dispatch($this->request('/search?q=php'))->body);
    }

    public function testPutAndDeleteRegistrations(): void
    {
        $this->router->put('/things/1', static fn (HttpRequest $r): HttpResponse => ResponseFactory::empty(HttpStatusCode::NO_CONTENT));
        $this->router->delete('/things/1', static fn (HttpRequest $r): HttpResponse => ResponseFactory::empty(HttpStatusCode::NO_CONTENT));

        $this->assertSame(204, $this->router->dispatch($this->request('/things/1', HttpMethod::PUT))->statusCode());
        $this->assertSame(204, $this->router->dispatch($this->request('/things/1', HttpMethod::DELETE))->statusCode());
    }

    public function testCountsRegisteredRoutes(): void
    {
        $this->router->get('/a', static fn (HttpRequest $r): HttpResponse => ResponseFactory::empty());
        $this->router->get('/b', static fn (HttpRequest $r): HttpResponse => ResponseFactory::empty());
        $this->router->post('/b', static fn (HttpRequest $r): HttpResponse => ResponseFactory::empty());

        $this->assertSame(3, $this->router->count());
    }

    private function request(string $target, HttpMethod $method = HttpMethod::GET): HttpRequest
    {
        return new HttpRequest(
            method: $method,
            target: $target,
            version: HttpVersion::HTTP_1_1,
            headers: new Headers(),
            body: '',
        );
    }
}