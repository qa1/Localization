<?php namespace Arcanedev\Localization\Utilities;

use Arcanedev\Localization\Contracts\UrlInterface;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Class     Url
 *
 * @package  Arcanedev\Localization\Utilities
 * @author   ARCANEDEV <arcanedev.maroc@gmail.com>
 *
 * @todo:    Refactoring
 */
class Url implements UrlInterface
{
    /* ------------------------------------------------------------------------------------------------
     |  Main Functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Extract attributes for current url.
     *
     * @param  bool|false|string  $url
     *
     * @return array
     */
    public static function extractAttributes($url = false)
    {
        $router     = app('router');

        if (empty($url)) {
            return self::extractAttributesFromCurrentRoute($router);
        }

        $attributes = [];
        $parse      = parse_url($url);
        $parse      = isset($parse['path']) ? explode('/', $parse['path']) : [];
        $url        = [];

        foreach ($parse as $segment) {
            if ( ! empty($segment)) $url[] = $segment;
        }

        foreach ($router->getRoutes() as $route) {
            /** @var Route $route */
            $path = $route->getUri();

            if ( ! preg_match('/{[\w]+}/', $path)) {
                continue;
            }

            $path  = explode('/', $path);
            $i     = 0;
            $match = true;

            foreach ($path as $j => $segment) {
                if (isset($url[$i])) {
                    if ($segment === $url[$i]) {
                        $i++;
                        continue;
                    }

                    if (preg_match('/{[\w]+}/', $segment)) {
                        // must-have parameters
                        $attributeName = preg_replace([ "/}/", "/{/", "/\?/" ], "", $segment);
                        $attributes[$attributeName] = $url[ $i ];
                        $i++;
                        continue;
                    }

                    if (preg_match('/{[\w]+\?}/', $segment)) {
                        // optional parameters
                        if ( ! isset($path[$j + 1]) || $path[$j + 1] !== $url[$i]) {
                            // optional parameter taken
                            $attributeName = preg_replace(['/}/', '/{/', '/\?/'], '', $segment);
                            $attributes[$attributeName] = $url[$i];
                            $i++;
                            continue;
                        }
                    }
                }
                elseif ( ! preg_match('/{[\w]+\?}/', $segment)) {
                    // no optional parameters but no more $url given
                    // this route does not match the url
                    $match = false;
                    break;
                }
            }

            if (isset($url[$i + 1])) {
                $match = false;
            }

            if ($match) {
                return $attributes;
            }
        }

        return $attributes;
    }

    /**
     * Change uri attributes (wildcards) for the ones in the $attributes array.
     *
     * @param  array   $attributes
     * @param  string  $uri
     *
     * @return string
     */
    public static function substituteAttributes(array $attributes, $uri)
    {
        foreach ($attributes as $key => $value) {
            $uri = str_replace('{' . $key . '}',  $value, $uri);
            $uri = str_replace('{' . $key . '?}', $value, $uri);
        }

        // delete empty optional arguments that are not in the $attributes array
        return preg_replace('/\/{[^)]+\?}/', '', $uri);
    }

    /**
     * Build URL using array data from parse_url.
     *
     * @param  array|false  $parsed
     *
     * @return string
     */
    public static function unparse($parsed)
    {
        if (empty($parsed)) return '';

        self::checkParsedUrl($parsed);

        $url  = self::getUrl($parsed);
        $url .= self::getQuery($parsed);
        $url .= self::getFragment($parsed);

        return $url;
    }

    /* ------------------------------------------------------------------------------------------------
     |  Extract Functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Extract Attributes From Router.
     *
     * @param  Router  $router
     *
     * @return array
     */
    private static function extractAttributesFromCurrentRoute(Router $router)
    {
        /** @var Route $route */
        $route = $router->current();

        if (is_null($route)) {
            return [];
        }

        $attributes = $route->parameters();
        $response   = event('routes.translation', [
            $attributes
        ]);

        if ( ! empty($response)) {
            $response = array_shift($response);
        }

        if (is_array($response)) {
            $attributes = array_merge($attributes, $response);
        }

        return $attributes;
    }

    /* ------------------------------------------------------------------------------------------------
     |  Unparse Functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Check parsed URL.
     *
     * @param  array  $parsed
     *
     * @return array
     */
    private static function checkParsedUrl(array &$parsed)
    {
        $scheme    =& $parsed['scheme'];
        $user      =& $parsed['user'];
        $pass      =& $parsed['pass'];
        $host      =& $parsed['host'];
        $port      =& $parsed['port'];
        $path      =& $parsed['path'];
        $path      = '/' . ltrim($path, '/'); // If / is missing for path.
        $query     =& $parsed['query'];
        $fragment  =& $parsed['fragment'];

        $parsed    = compact(
            'scheme', 'user', 'pass', 'host', 'port', 'path', 'query', 'fragment'
        );
    }

    /**
     * Get URL.
     *
     * @param  array  $parsed
     *
     * @return string
     */
    private static function getUrl(array $parsed)
    {
        $url       = '';

        if (strlen($parsed['scheme'])) {
            $url = $parsed['scheme'] . ':' . self::getHierPart($parsed);
        }

        return $url;
    }

    /**
     * Get hier part.
     *
     * @param  array  $parsed
     *
     * @return string
     */
    private static function getHierPart(array $parsed)
    {
        $path      = $parsed['path'];
        $authority = self::getAuthority($parsed);

        if (strlen($authority)) {
            $path = '//' . $authority . $path;
        }

        return $path;
    }

    /**
     * Get authority.
     *
     * @param  array  $parsed
     *
     * @return string
     */
    private static function getAuthority(array $parsed)
    {
        $userInfo  = self::getUserInfo($parsed);
        $host      = self::getHost($parsed);

        if (strlen($userInfo)) {
            return $userInfo . '@' . $host;
        }

        return $host;
    }

    /**
     * Get user info.
     *
     * @param  array  $parsed
     *
     * @return string
     */
    private static function getUserInfo(array $parsed)
    {
        $userInfo = '';

        if (strlen($parsed['pass'])) {
            $userInfo = $parsed['user'] . ':' . $parsed['pass'];
        }

        return $userInfo;
    }

    /**
     * Get host.
     *
     * @param  array  $parsed
     *
     * @return string
     */
    private static function getHost(array $parsed)
    {
        $host = $parsed['host'];

        if ( ! empty((string) $parsed['port'])) {
            $host = $host . ':' . $parsed['port'];
        }

        return $host;
    }

    /**
     * Get Query.
     *
     * @param  array  $parsed
     *
     * @return string
     */
    private static function getQuery(array $parsed)
    {
        $query = '';

        if (strlen($parsed['query'])) {
            $query = '?' . $parsed['query'];
        }

        return $query;
    }

    /**
     * Get fragment.
     *
     * @param  array  $parsed
     *
     * @return string
     */
    private static function getFragment(array $parsed)
    {
        $fragment = '';

        if (strlen($parsed['fragment'])) {
            $fragment = '#' . $parsed['fragment'];
        }

        return $fragment;
    }
}
