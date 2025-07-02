<?php

namespace Drupal\vactory_dashboard\EventSubscriber;

use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Redirects user to dashboard after login.
 */
class LoginRedirectSubscriber implements EventSubscriberInterface
{

    /**
     * The current user.
     *
     * @var \Drupal\Core\Session\AccountProxyInterface
     */
    protected $currentUser;

    /**
     * The route match service.
     *
     * @var \Drupal\Core\Routing\RouteMatchInterface
     */
    protected $routeMatch;

    /**
     * The request stack.
     *
     * @var \Symfony\Component\HttpFoundation\RequestStack
     */
    protected $requestStack;

    /**
     * The URL generator.
     *
     * @var \Drupal\Core\Routing\UrlGeneratorInterface
     */
    protected $urlGenerator;

    /**
     * Constructs the LoginRedirectSubscriber.
     *
     * @param \Drupal\Core\Session\AccountProxyInterface $current_user
     *   The current user.
     * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
     *   The route match.
     * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
     *   The request stack.
     * @param \Drupal\Core\Routing\UrlGeneratorInterface $url_generator
     *   The URL generator.
     */
    public function __construct(
        AccountProxyInterface $current_user,
        RouteMatchInterface $route_match,
        RequestStack $request_stack,
        UrlGeneratorInterface $url_generator
    ) {
        $this->currentUser = $current_user;
        $this->routeMatch = $route_match;
        $this->requestStack = $request_stack;
        $this->urlGenerator = $url_generator;
    }

    /**
     * Redirects to dashboard after login having the necessary permissions.
     *
     * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
     *   The response event.
     */
    public function onKernelResponse(ResponseEvent $event)
    {
        if ($this->currentUser->isAuthenticated() && $this->routeMatch->getRouteName() === 'user.login') {
            $request = $this->requestStack->getCurrentRequest();

            if ($request->query->has('destination')) {
                return;
            }

            if ($this->currentUser->hasPermission('access dashboard')) {
                $redirect_url = $this->urlGenerator->generate('vactory_dashboard.home');
                $response = new RedirectResponse($redirect_url);
                $event->setResponse($response);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse'],
        ];
    }
}
