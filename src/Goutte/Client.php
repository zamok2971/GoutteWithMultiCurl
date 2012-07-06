<?php

namespace Goutte;

use MultiClient;
use Symfony\Component\BrowserKit\Client as BaseClient;
use Symfony\Component\BrowserKit\History;
use Symfony\Component\BrowserKit\CookieJar;
use Symfony\Component\BrowserKit\Request;
use Symfony\Component\BrowserKit\Response;

use Zend\Http\Client as ZendClient;
use Zend\Http\Response as ZendResponse;

/*
 * This file is part of the Goutte package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Client.
 *
 * @package Goutte
 * @author  Fabien Potencier <fabien.potencier@symfony-project.com>
 */
class Client extends BaseClient
{
    const VERSION = '0.1';

    protected $zendConfig;

    protected $headers = array();

    protected $auth = null;

    public function __construct(array $zendConfig = array(), array $server = array(), History $history = null, CookieJar $cookieJar = null)
    {
        $this->zendConfig = $zendConfig;

        parent::__construct($server, $history, $cookieJar);
    }

    public function setHeader($name, $value)
    {
        $this->headers[$name] = $value;
       
    }

    public function getHeaders()
    {
        return $this->headers;
    }

    public function getHistory()
    {
        return $this->history;
    }

    public function setAuth($user, $password = '', $type = ZendClient::AUTH_BASIC)
    {
        $this->auth = array(
            'user' => $user,
            'password' => $password,
            'type' => $type
        );
    }

    /**
     * Calls a URI.
     *
     * @param string  $method        The request method
     * @param string  $uri           The URI to fetch
     * @param array   $parameters    The Request parameters
     * @param array   $files         The files
     * @param array   $server        The server parameters (HTTP headers are referenced with a HTTP_ prefix as PHP does)
     * @param string  $content       The raw body data
     * @param Boolean $changeHistory Whether to update the history or not (only used internally for back(), forward(), and reload())
     * @param string  $c_type        content-type
     * @param string  $http_referer  The data from another Client
     *
     * @return Crawler
     *
     * @api
     */
    public function request($method, $uri, array $parameters = array(), array $files = array(), array $server = array(), $content = null, $changeHistory = true, $c_type = '', $http_referer ='')
    {
        $uri = $this->getAbsoluteUri($uri);
        $server = array_merge($this->server, $server);
        if (!$this->history->isEmpty()) {
            $server['HTTP_REFERER'] = $this->history->current()->getUri();
        }

        if($http_referer){
            $server['HTTP_REFERER'] = $http_referer;
        }


        $server['HTTP_HOST'] = parse_url($uri, PHP_URL_HOST);
        $server['HTTPS'] = 'https' == parse_url($uri, PHP_URL_SCHEME);

        $request = new Request($uri, $method, $parameters, $files, $this->cookieJar->allValues($uri), $server, $content);
        $this->request = $this->filterRequest($request);

        if (true === $changeHistory) {
            $this->history->add($request);
        }

        if ($this->insulated) {
            $this->response = $this->doRequestInProcess($this->request);
        } else {
            $this->response = $this->doRequest($this->request);
        }

        $response = $this->filterResponse($this->response);

        $this->cookieJar->updateFromResponse($response, $uri);

        $this->redirect = $response->getHeader('Location');

        if ($this->followRedirects && $this->redirect) {
            return $this->crawler = $this->followRedirect();
        }

        //begin my modified
        $content = $response->getContent();
        if ($c_type == 'xml') {
            $content_type = "text/xml\r\n";
            //избавляемся от битых символов
            $content = iconv('utf-8', 'utf-8//IGNORE', $content);
        }
        else {
            $content_type = $response->getHeader('Content-Type');
        }
        //end my modified
        return $this->crawler = $this->createCrawlerFromContent($request->getUri(), $content, $content_type);
    }

    public function getContent()
    {
        $response = $this->filterResponse($this->response);
        return $response->getContent();
    }

    public function multirequest($requests)
    {
        $requests_array = array();
        foreach ($requests as $key => &$req) {
            $req['uri']."\n";
            $req['uri'] = $this->getAbsoluteUri($req['uri']); // не уверена что надо
            $req['host'] = parse_url($req['uri'], PHP_URL_HOST);
            $req['path'] = parse_url($req['uri'], PHP_URL_PATH);
            $req['port'] = 80;
            $req['headers'] = array();
            $req['headers']['Host'] = $req['host'];
            $req['headers']['Accept-encoding'] = 'identity';
            if (isset($req['useragent'])) {
                $req['headers']['User-Agent'] = $req['useragent'];
            }
            // Set HTTP authentication if needed
            if (!empty($this->auth)) {
                switch ($this->auth['type']) {
                    case self::AUTH_BASIC :
                        $auth = $this->calcAuthDigest($this->auth['user'], $this->auth['password'], $this->auth['type']);
                        if ($auth !== false) {
                            $req['headers']['Authorization'] = 'Basic ' . $auth;
                        }
                        break;
                    case self::AUTH_DIGEST :
                        throw new Exception\RuntimeException("The digest authentication is not implemented yet");
                }
            }

            $requests_array[$key] = $req;
        }
        $response = $this->doMultiRequest($requests_array);
        foreach ($response as $key => $res) {
            $content[$requests[$key]['uri']] = $res;
            unset($res);
        }

        return $content;
    }

    protected function doMultiRequest($requests)
    {
        $client = new MultiClient();
        $response = $client->execute($requests);
        return $response;
    }

    protected function doRequest($request)
    {
        $client = $this->createClient($request);

        $response = $client->send($client->getRequest());

        return $this->createResponse($response);
    }

    protected function createClient(Request $request)
    {
        $client = $this->createZendClient();
        $client->setUri($request->getUri());
        $client->setConfig(array_merge(array(
                                            'maxredirects' => 0,
                                            'timeout' => 30,
                                            'useragent' => $this->server['HTTP_USER_AGENT'],
                                            'adapter' => 'Zend\\Http\\Client\\Adapter\\Curl',
                                       ), $this->zendConfig));
        $client->setMethod(strtoupper($request->getMethod()));

        if ('POST' == $request->getMethod()) {
            $client->setParameterPost($request->getParameters());
           
        }
         $client->setHeaders($this->headers);

        if ($this->auth !== null) {
            $client->setAuth(
                $this->auth['user'],
                $this->auth['password'],
                $this->auth['type']
            );
        }

        foreach ($this->getCookieJar()->allValues($request->getUri()) as $name => $value) {
            $client->addCookie($name, $value);
        }

        foreach ($request->getFiles() as $name => $info) {
            if (isset($info['tmp_name']) && '' !== $info['tmp_name']) {
                $filename = $info['name'];
                if (false === ($data = @file_get_contents($info['tmp_name']))) {
                    throw new \RuntimeException("Unable to read file '{$filename}' for upload");
                }
                $client->setFileUpload($filename, $name, $data);
            }
        }

        return $client;
    }

    protected function createResponse(ZendResponse $response)
    {
        return new Response($response->getBody(), $response->getStatusCode(), $response->headers()->toArray());
    }

    protected function createZendClient()
    {
        return new ZendClient(null, array('encodecookies' => false));
    }
}
