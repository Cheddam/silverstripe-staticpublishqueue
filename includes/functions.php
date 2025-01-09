<?php

namespace SilverStripe\StaticPublishQueue;

use SilverStripe\Core\Environment;

if (!function_exists('SilverStripe\\StaticPublishQueue\\URLtoPath')) {
    function URLtoPath($url, $baseURL = '', $domainBasedCaching = false)
    {
        // parse_url() is not multibyte safe, see https://bugs.php.net/bug.php?id=52923.
        // We assume that the URL hsa been correctly encoded either on storage (for SiteTree->URLSegment),
        // or through URL collection (for controller method names etc.).
        $urlParts = @parse_url($url);

        // Handle query parameters
        $queryPath = '';
        if (!empty($urlParts['query'])) {
            parse_str($urlParts['query'], $queryParts);
            ksort($queryParts);

            // Drop the stage parameter if we're in Live mode
            if (!empty($queryParts['stage']) && $queryParts['stage'] === 'Live') {
                unset($queryParts['stage']);
            }

            // Query parameters can form part of the path, but only if they are explicitly configured
            if ($cacheableParameters = Environment::getEnv('CACHEABLE_QUERY_PARAMETERS')) {
                $cacheableParameters = explode(',', $cacheableParameters);
                foreach ($queryParts as $key => $value) {
                    // Any query parameter that isn't cacheable prevents generation of a valid path
                    if (!in_array($key, $cacheableParameters)) {
                        return;
                    }

                    $queryPath .= "--$key-$value";
                }
            }
        }

        // Remove base folders from the URL if webroot is hosted in a subfolder)
        $path = isset($urlParts['path']) ? $urlParts['path'] : '';
        if (mb_substr(mb_strtolower($path), 0, mb_strlen($baseURL)) === mb_strtolower($baseURL)) {
            $urlSegment = mb_substr($path, mb_strlen($baseURL));
        } else {
            $urlSegment = $path;
        }

        // Normalize URLs
        $urlSegment = trim($urlSegment, '/');

        $filename = $urlSegment ?: 'index';

        if ($domainBasedCaching) {
            if (!$urlParts) {
                throw new \LogicException('Unable to parse URL');
            }
            if (isset($urlParts['host'])) {
                $filename = $urlParts['host'] . '/' . $filename;
            }
        }
        $dirName = dirname($filename);
        $prefix = '';
        if ($dirName !== '/' && $dirName !== '.') {
            $prefix = $dirName . '/';
        }
        return $prefix . basename($filename) . $queryPath;
    }
}

if (!function_exists('SilverStripe\\StaticPublishQueue\\PathToURL')) {
    function PathToURL($path, $destPath, $domainBasedCaching = false)
    {
        if (strpos($path, $destPath) === 0) {
            //Strip off the full path of the cache dir from the front
            $path = substr($path, strlen($destPath));
        }

        // Strip off the file extension and leading /
        $relativeURL = substr($path, 0, strrpos($path, '.'));
        $relativeURL = ltrim($relativeURL, '/');

        // Parse out and append query parameters
        $splitQueryParameters = explode('--', $relativeURL);
        $relativeURL = array_shift($splitQueryParameters);
        if (!empty($splitQueryParameters)) {
            $formattedQueryParameters = array_map(
                fn ($param) => str_replace('-', '=', $param),
                $splitQueryParameters
            );
            $relativeURL .= '?' . implode('&', $formattedQueryParameters);
        }

        if ($domainBasedCaching) {
            // factor in the domain as the top dir
            if (substr($relativeURL, -6) === '/index') {
                $relativeURL = substr($relativeURL, 0, strlen($relativeURL) - 5);
            }
            return \SilverStripe\Control\Director::protocol() . $relativeURL;
        }

        return $relativeURL === 'index'
            ? \SilverStripe\Control\Director::absoluteBaseURL()
            : \SilverStripe\Control\Director::absoluteURL($relativeURL);
    }
}
