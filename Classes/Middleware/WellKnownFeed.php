<?php

declare(strict_types=1);

namespace LetsEmploy\OJobPub\Middleware;

use LetsEmploy\OJobPub\Feed\Spec;
use LetsEmploy\OJobPub\Service\FeedService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Serves /.well-known/ojobpub.json for the site the request's host belongs to.
 *
 * Runs right after the site resolver, before redirects, static routes and page
 * resolving. If the feed is disabled or not configured the request continues,
 * which ends in TYPO3's regular 404 handling.
 */
final class WellKnownFeed implements MiddlewareInterface
{
    private const MAX_AGE = 900;

    public function __construct(
        private readonly FeedService $feedService,
        private readonly SiteFinder $siteFinder,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getUri()->getPath() !== Spec::WELL_KNOWN_PATH) {
            return $handler->handle($request);
        }
        $site = $this->site($request);
        if ($site === null || !$this->feedService->isEnabled($site)) {
            return $handler->handle($request);
        }

        $method = $request->getMethod();
        if ($method === 'OPTIONS') {
            return $this->cors($this->responseFactory->createResponse(204))
                ->withHeader('Access-Control-Allow-Methods', 'GET, HEAD, OPTIONS')
                ->withHeader('Access-Control-Max-Age', '86400');
        }
        if ($method !== 'GET' && $method !== 'HEAD') {
            return $this->responseFactory->createResponse(405)->withHeader('Allow', 'GET, HEAD, OPTIONS');
        }

        $feed = $this->feedService->get($site);
        if ($feed === null) {
            return $handler->handle($request);
        }

        $response = $this->cors($this->responseFactory->createResponse())
            ->withHeader('ETag', $feed->etag)
            ->withHeader('Last-Modified', gmdate('D, d M Y H:i:s', $feed->lastUpdated->getTimestamp()) . ' GMT')
            ->withHeader('Cache-Control', 'public, max-age=' . self::MAX_AGE)
            ->withHeader('X-Content-Type-Options', 'nosniff');

        if ($this->notModified($request, $feed->etag)) {
            return $response->withStatus(304);
        }

        $response = $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Content-Length', (string)strlen($feed->json));

        return $method === 'HEAD'
            ? $response
            : $response->withBody($this->streamFactory->createStream($feed->json));
    }

    /**
     * The resolved site, or (if the site has a base path or language prefixes
     * the resolver did not match) the site whose base host equals the request host.
     */
    private function site(ServerRequestInterface $request): ?Site
    {
        $site = $request->getAttribute('site');
        if ($site instanceof Site) {
            return $site;
        }
        $host = strtolower($request->getUri()->getHost());
        foreach ($this->siteFinder->getAllSites() as $candidate) {
            if (strtolower($candidate->getBase()->getHost()) === $host) {
                return $candidate;
            }
        }

        return null;
    }

    private function notModified(ServerRequestInterface $request, string $etag): bool
    {
        $header = $request->getHeaderLine('If-None-Match');
        if ($header === '') {
            return false;
        }
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*' || $candidate === $etag || $candidate === 'W/' . $etag) {
                return true;
            }
        }

        return false;
    }

    private function cors(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Access-Control-Allow-Origin', '*');
    }
}
